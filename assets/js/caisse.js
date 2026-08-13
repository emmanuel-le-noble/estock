/* =========================================================
   caisse.js - Logique du lecteur de code-barres & panier
   =========================================================
   Principe :
   - Le champ #scanInput conserve le focus en permanence.
   - On écoute 'keydown' : la douchette simule un appui sur Enter.
   - À chaque Enter, on interroge api/scan (fetch).
   - Si l'article existe, on l'ajoute au panier (ou on incrémente),
     on recalcule les totaux en temps réel, puis on vide le champ.
   - Le panier vit en mémoire (tableau JS) ; la validation se fait
     par POST vers valider_facture.php (en ligne) ou sauvegarde
     dans localStorage (hors-ligne).
   - Mode hors-ligne : les ventes sont en file d'attente et
     synchronisées automatiquement dès le retour du réseau.
   ========================================================= */

document.addEventListener('DOMContentLoaded', function () {

    // Animation CSS pour les toasts de péremption
    if (!document.getElementById('lot-alert-styles')) {
        const style = document.createElement('style');
        style.id = 'lot-alert-styles';
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
        `;
        document.head.appendChild(style);
    }

    // ---- Références DOM ----
    const scanInput   = document.getElementById('scanInput');
    const panierTbody = document.getElementById('panierTbody');
    const totalHtEl   = document.getElementById('totalHt');
    const totalTtcEl  = document.getElementById('totalTtc');
    const nbArticlesEl= document.getElementById('nbArticles');
    const zoneAlerte  = document.getElementById('zoneAlerte');
    const formPaiement= document.getElementById('formPaiement');
    const inpMontantPaye = document.getElementById('montantPaye');
    const btnValider = document.getElementById('btnValider');
    const conteneurPanier = document.getElementById('conteneurPanier');
    const tvaDisplay = document.getElementById('tvaDisplay');

    const config = window.POS_CONFIG || {};
    const TAUX_TVA = Number.parseFloat(config.taux_tva ?? 0) || 0;
    const DEVISE_SYMBOLE = String(config.devise_symbole || 'FCFA');
    const DEVISE_POSITION = config.devise_position === 'avant' ? 'avant' : 'apres';
    const DEVISE_DECIMALES = Math.max(0, Math.min(4, Number.parseInt(config.devise_decimales ?? 2, 10) || 0));
    const SEPARATEUR_DECIMAL = String(config.separateur_decimal || ',');
    const SEPARATEUR_MILLIERS = String(config.separateur_milliers ?? ' ');

    const BASE = window.BASE_URL || '/eSotck/';
    const PENDING_SALES_KEY = 'estock_pending_sales';

    // Le panier : { [article_id]: {article, qte} }
    let panier = {};

    // ---- Fidélité : client sélectionné + configuration ----
    let clientActuel = null;
    let totalBrut = 0; // total TTC sans remise fidélité
    const FIDELITE = {
        actif: !!config.fidelite_actif,
        valeurPoint: Number.parseFloat(config.fidelite_valeur_point || 1) || 1,
        minPoints: Number.parseInt(config.fidelite_min_points_usage || 10, 10) || 10
    };

    // ---- Références DOM du panneau client ----
    const clientSearch = document.getElementById('clientSearch');
    const btnClientSearch = document.getElementById('btnClientSearch');
    const clientResults = document.getElementById('clientResults');
    const clientChip = document.getElementById('clientChip');
    const clientNom = document.getElementById('clientNom');
    const clientSolde = document.getElementById('clientSolde');
    const btnClientClear = document.getElementById('btnClientClear');
    const zonePoints = document.getElementById('zonePoints');
    const pointsUtilises = document.getElementById('pointsUtilises');
    const lblRemiseFidelite = document.getElementById('lblRemiseFidelite');
    const inpClientId = document.getElementById('inpClientId');
    const inpPointsUtilises = document.getElementById('inpPointsUtilises');

    // ============================================================
    //  MODE HORS-LIGNE : Badge de connexion
    // ============================================================
    function creerBadgeConnexion() {
        let badge = document.getElementById('connectionBadge');
        if (!badge) {
            badge = document.createElement('div');
            badge.id = 'connectionBadge';
            document.body.appendChild(badge);
        }
        mettreAJourBadge();
        return badge;
    }

    function mettreAJourBadge() {
        const badge = document.getElementById('connectionBadge');
        if (!badge) return;
        const enLigne = navigator.onLine;
        const nbPending = getPendingSales().length;
        badge.className = 'px-3 py-1 rounded-pill fw-bold';
        badge.style.cssText = 'font-size:.82rem;transition:all .3s;cursor:default;white-space:nowrap;';
        if (enLigne) {
            badge.style.backgroundColor = nbPending > 0 ? '#0d6efd' : '#198754';
            badge.style.color = '#fff';
            badge.innerHTML = nbPending > 0
                ? '<i class="bi bi-cloud-arrow-up"></i> En ligne — ' + nbPending + ' en attente'
                : '<i class="bi bi-wifi"></i> En ligne';
        } else {
            badge.style.backgroundColor = '#fd7e14';
            badge.style.color = '#fff';
            badge.innerHTML = '<i class="bi bi-wifi-off"></i> Hors-ligne'
                + (nbPending > 0 ? ' — ' + nbPending + ' vente(s) en file' : '');
        }
    }

    // Écouter les changements de connexion
    window.addEventListener('online', function () {
        mettreAJourBadge();
        alerter('Connexion rétablie ! Synchronisation en cours...', 'success');
        synchroniserVentes();
    });

    window.addEventListener('offline', function () {
        mettreAJourBadge();
        alerter('Connexion perdue. Les ventes seront sauvegardées localement.', 'warning');
    });

    creerBadgeConnexion();

    // ============================================================
    //  FILE D'ATTENTE : localStorage
    // ============================================================
    function getPendingSales() {
        try {
            return JSON.parse(localStorage.getItem(PENDING_SALES_KEY) || '[]');
        } catch (e) {
            return [];
        }
    }

    function savePendingSales(sales) {
        localStorage.setItem(PENDING_SALES_KEY, JSON.stringify(sales));
        mettreAJourBadge();
    }

    function genererUUID() {
        if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            var v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    function ajouterVenteEnAttente(vente) {
        var queue = getPendingSales();
        queue.push(vente);
        savePendingSales(queue);
    }

    // ============================================================
    //  SYNCHRONISATION AUTOMATIQUE
    // ============================================================
    async function synchroniserVentes() {
        if (!navigator.onLine) return;

        var queue = getPendingSales();
        var pending = queue.filter(function (s) { return s.statut === 'pending'; });
        if (pending.length === 0) return;

        alerter('Synchronisation de ' + pending.length + ' vente(s) en attente...', 'info');

        var syncOK = 0;
        var syncFail = 0;

        for (var i = 0; i < pending.length; i++) {
            var vente = pending[i];
            try {
                var res = await fetch(BASE + 'api/caisse/sync', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': (config.csrf_token || '')
                    },
                    body: JSON.stringify(vente)
                });
                var data = await res.json();

                if (data.success) {
                    // Marquer comme synchronisée
                    queue = queue.map(function (s) {
                        return s.client_sale_id === vente.client_sale_id
                            ? Object.assign({}, s, { statut: 'synced' })
                            : s;
                    });
                    syncOK++;
                    var ptsMsg = (data.points_gagnes || 0) > 0
                        ? ' — <strong>+' + data.points_gagnes + ' pts</strong> fidélité'
                        : '';
                    alerter(
                        'Vente synchronisée : ' + (data.numero || vente.client_sale_id.slice(0, 8)) + ptsMsg,
                        'success'
                    );
                } else {
                    syncFail++;
                    alerter('Échec sync (' + vente.client_sale_id.slice(0, 8) + ') : ' + (data.error || 'Erreur'), 'danger');
                }
            } catch (err) {
                syncFail++;
                alerter('Erreur réseau sync : ' + err.message, 'danger');
                // Arrêter la sync, on réessayera au prochain online
                break;
            }
        }

        // Nettoyer les ventes synchronisées
        queue = queue.filter(function (s) { return s.statut !== 'synced'; });
        savePendingSales(queue);

        if (syncOK > 0 && syncFail === 0) {
            alerter(syncOK + ' vente(s) synchronisée(s) avec succès.', 'success');
        } else if (syncFail > 0) {
            alerter('Synchronisation partielle : ' + syncOK + ' OK, ' + syncFail + ' échec(s).', 'warning');
        }
    }

    // Tenter la sync au chargement si en ligne et des ventes en attente
    if (navigator.onLine && getPendingSales().length > 0) {
        synchroniserVentes();
    }

    // ============================================================
    //  FOCUS PERMANENT
    // ============================================================
    if (scanInput) {
        scanInput.focus();
        document.body.addEventListener('click', function (e) {
            if (e.target.closest('input, button, select, textarea, a, .modal, table')) return;
            scanInput.focus();
        });
        scanInput.addEventListener('blur', function () {
            setTimeout(function () {
                if (document.activeElement === document.body ||
                    document.activeElement === null) {
                    scanInput.focus();
                }
            }, 50);
        });

        // ---- Écoute du scan (Enter = fin de code-barres) ----
        scanInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var code = scanInput.value.trim();
                if (code !== '') {
                    traiterScan(code);
                }
            }
        });
    }

    // ---- Traitement d'un scan ----
    function traiterScan(code) {
        if (scanInput) { scanInput.value = ''; scanInput.disabled = true; }

        var spinner = document.createElement('div');
        spinner.id = 'scanSpinner';
        spinner.className = 'text-center text-muted py-2';
        spinner.innerHTML = '<div class="spinner-border spinner-border-sm text-primary" role="status"></div> Recherche...';
        if (zoneAlerte) zoneAlerte.appendChild(spinner);

        fetch(BASE + 'api/scan?code=' + encodeURIComponent(code))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.found) {
                    ajouterAuPanier(data.article);
                    alerter(data.article.alerte || 'Article ajouté : ' + data.article.nom, 'success');

                    // Notification de péremption
                    if (data.alerte_peremption) {
                        const alerte = data.alerte_peremption;
                        const isExpired = alerte.statut === 'expire';
                        const bgColor = isExpired ? '#dc3545' : '#fd7e14';
                        const icon = isExpired ? 'bi-exclamation-octagon' : 'bi-exclamation-triangle';

                        const toast = document.createElement('div');
                        toast.className = 'position-fixed top-0 end-0 m-3 p-3 text-white rounded shadow';
                        toast.style.cssText = 'z-index: 9999; background: ' + bgColor + '; max-width: 350px; animation: slideIn 0.3s ease;';
                        toast.innerHTML =
                            '<div class="d-flex align-items-center gap-2">' +
                            '<i class="bi ' + icon + ' fs-4"></i>' +
                            '<div>' +
                            '<strong>' + (isExpired ? 'LOT PÉRIMÉ' : 'DLC Proche') + '</strong><br>' +
                            '<small>Lot ' + alerte.numero_lot + ' — ' + (isExpired ? 'Périmé depuis ' + Math.abs(alerte.jours_restants) + 'j' : 'Expire dans ' + alerte.jours_restants + 'j') + '</small>' +
                            '</div>' +
                            '<button class="btn-close btn-close-white ms-auto" id="btnToastClose"></button>' +
                            '</div>';
                        document.body.appendChild(toast);
                        toast.querySelector('#btnToastClose').addEventListener('click', function () { toast.remove(); });

                        setTimeout(function () { toast.remove(); }, 5000);
                    }

                    // Notification de promotion
                    if (data.article.promotion) {
                        var promo = data.article.promotion;
                        var toastPromo = document.createElement('div');
                        toastPromo.className = 'position-fixed top-0 end-0 m-3 p-3 text-white rounded shadow';
                        toastPromo.style.cssText = 'z-index: 9999; background: linear-gradient(135deg, #6366f1, #8b5cf6); max-width: 350px; animation: slideIn 0.3s ease;';
                        toastPromo.innerHTML =
                            '<div class="d-flex align-items-center gap-2">' +
                            '<i class="bi bi-lightning-charge-fill fs-4"></i>' +
                            '<div>' +
                            '<strong>PROMOTION VENTE FLASH</strong><br>' +
                            '<small>' + escapeHtml(promo.label) + '</small><br>' +
                            '<small><s>' + formatMoney(promo.prix_original) + '</s> → <strong>' + formatMoney(promo.prix_remise) + '</strong> (−' + promo.remise_pct + '%)</small>' +
                            '</div>' +
                            '<button class="btn-close btn-close-white ms-auto" id="btnToastPromoClose"></button>' +
                            '</div>';
                        document.body.appendChild(toastPromo);
                        toastPromo.querySelector('#btnToastPromoClose').addEventListener('click', function () { toastPromo.remove(); });

                        setTimeout(function () { toastPromo.remove(); }, 5000);
                    }
                } else {
                    alerter((data.message || 'Introuvable') + ' : ' + code, 'danger');
                    scanInput.classList.add('is-invalid');
                    setTimeout(function () { scanInput.classList.remove('is-invalid'); }, 600);
                }
            })
            .catch(function (err) {
                // Mode hors-ligne : proposer d'ajouter manuellement ?
                alerter('Erreur réseau : ' + err.message, 'danger');
            })
            .finally(function () {
                var sp = document.getElementById('scanSpinner');
                if (sp) sp.remove();
                if (scanInput) { scanInput.disabled = false; scanInput.focus(); }
            });
    }

    // ============================================================
    //  GESTION DU PANIER
    // ============================================================
    function ajouterAuPanier(art) {
        var id = art.id;
        if (panier[id]) {
            if (panier[id].qte + 1 > art.stock_dispo) {
                alerter('Stock maximum atteint pour ' + art.nom, 'warning');
                return;
            }
            panier[id].qte += 1;
        } else {
            if (art.stock_dispo <= 0) {
                alerter('Stock épuisé pour ' + art.nom, 'danger');
                return;
            }
            panier[id] = { article: art, qte: 1 };
        }
        rafraichirPanier();
    }

    var modifierQte = function modifierQte(id, delta) {
        if (!panier[id]) return;
        var nouvelleQte = panier[id].qte + delta;
        if (nouvelleQte <= 0) {
            delete panier[id];
        } else if (nouvelleQte > panier[id].article.stock_dispo) {
            alerter('Stock maximum atteint pour ' + panier[id].article.nom, 'warning');
            return;
        } else {
            panier[id].qte = nouvelleQte;
        }
        rafraichirPanier();
    };

    var supprimerLigne = function supprimerLigne(id) {
        if (!confirm('Supprimer cet article du panier ?')) return;
        delete panier[id];
        rafraichirPanier();
        scanInput.focus();
    };

    var viderPanier = function viderPanier() {
        if (!confirm('Vider tout le panier ?')) return;
        panier = {};
        rafraichirPanier();
        scanInput.focus();
    };

    window.viderPanier = viderPanier;
    window.modifierQte = modifierQte;
    window.supprimerLigne = supprimerLigne;

    // ---- Liaison événementielle (Event Delegation & Listeners CSP-Compliant) ----
    var btnViderPanier = document.getElementById('btnViderPanier');
    if (btnViderPanier) {
        btnViderPanier.addEventListener('click', function () {
            viderPanier();
        });
    }

    panierTbody.addEventListener('click', function (e) {
        var btnDecrease = e.target.closest('.btn-decrease');
        var btnIncrease = e.target.closest('.btn-increase');
        var btnDelete   = e.target.closest('.btn-delete');

        if (btnDecrease) {
            var id = parseInt(btnDecrease.dataset.id, 10);
            modifierQte(id, -1);
        } else if (btnIncrease) {
            var id = parseInt(btnIncrease.dataset.id, 10);
            modifierQte(id, 1);
        } else if (btnDelete) {
            var id = parseInt(btnDelete.dataset.id, 10);
            supprimerLigne(id);
        }
    });

    var quickPayContainer = document.getElementById('quickPayContainer');
    if (quickPayContainer) {
        quickPayContainer.addEventListener('click', function (e) {
            var btn = e.target.closest('.btn-quick-pay');
            if (!btn) return;

            if (btn.dataset.type === 'exact') {
                var raw = totalTtcEl.dataset.raw || '';
                inpMontantPaye.value = raw;
            } else if (btn.dataset.type === 'amount') {
                inpMontantPaye.value = btn.dataset.amount;
            }
            inpMontantPaye.dispatchEvent(new Event('input'));
        });
    }

    // ============================================================
    //  FIDÉLITÉ : RECHERCHE & SÉLECTION DU CLIENT
    // ============================================================
    function chercherClients(terme) {
        if (!clientResults || !terme.trim()) { cacherResultats(); return; }
        fetch(BASE + 'api/clients/search?q=' + encodeURIComponent(terme.trim()))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var liste = (data && data.clients) || [];
                if (liste.length === 0) {
                    clientResults.innerHTML =
                        '<div class="list-group-item text-muted">Aucun client trouvé.</div>';
                } else {
                    clientResults.innerHTML = liste.map(function (c) {
                        var label = escapeHtml(c.nom || 'Client #' + c.id);
                        if (c.code_fidelite) label += ' <code>' + escapeHtml(c.code_fidelite) + '</code>';
                        return '<button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" ' +
                            'data-client=\'' + JSON.stringify(c).replace(/'/g, '&#39;') + '\'>' +
                            '<span>' + label +
                            (c.telephone ? '<br><small class="text-muted">' + escapeHtml(c.telephone) + '</small>' : '') +
                            '</span>' +
                            '<span class="badge text-bg-primary">' + (c.points_fidelite || 0) + ' pts</span>' +
                            '</button>';
                    }).join('');
                    clientResults.style.display = 'block';
                    clientResults.querySelectorAll('button[data-client]').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var c = JSON.parse(btn.dataset.client);
                            selectionnerClient(c);
                        });
                    });
                }
                clientResults.style.display = 'block';
            })
            .catch(function () {
                if (clientResults) {
                    clientResults.innerHTML = '<div class="list-group-item text-danger">Erreur réseau.</div>';
                    clientResults.style.display = 'block';
                }
            });
    }

    function cacherResultats() {
        if (clientResults) { clientResults.style.display = 'none'; }
    }

    function selectionnerClient(c) {
        clientActuel = c;
        cacherResultats();
        if (clientSearch) clientSearch.value = '';
        if (inpClientId) inpClientId.value = c.id;
        if (inpPointsUtilises) inpPointsUtilises.value = 0;
        if (pointsUtilises) pointsUtilises.value = 0;

        if (clientChip && clientNom && clientSolde) {
            clientChip.classList.remove('d-none');
            clientNom.textContent = c.nom || ('Client #' + c.id);
            clientSolde.textContent = c.consentement_fidelite
                ? (c.points_fidelite || 0) + ' points disponibles'
                : 'Pas de consentement fidélité';
        }
        if (zonePoints) {
            zonePoints.classList.toggle('d-none', !(c.consentement_fidelite && (c.points_fidelite || 0) >= FIDELITE.minPoints));
        }
        recalculerPaiement();
        if (scanInput) scanInput.focus();
    }

    function retirerClient() {
        clientActuel = null;
        if (clientChip) clientChip.classList.add('d-none');
        if (zonePoints) zonePoints.classList.add('d-none');
        if (inpClientId) inpClientId.value = 0;
        if (inpPointsUtilises) inpPointsUtilises.value = 0;
        if (pointsUtilises) pointsUtilises.value = 0;
        if (lblRemiseFidelite) lblRemiseFidelite.textContent = '';
        recalculerPaiement();
        if (scanInput) scanInput.focus();
    }

    if (clientSearch) {
        clientSearch.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                chercherClients(clientSearch.value);
            }
        });
    }
    if (btnClientSearch) {
        btnClientSearch.addEventListener('click', function () {
            chercherClients(clientSearch ? clientSearch.value : '');
        });
    }
    if (btnClientClear) {
        btnClientClear.addEventListener('click', retirerClient);
    }
    if (pointsUtilises) {
        pointsUtilises.addEventListener('input', function () {
            if (inpPointsUtilises) inpPointsUtilises.value = Math.max(0, parseInt(pointsUtilises.value || '0', 10) || 0);
            recalculerPaiement();
        });
    }
    document.addEventListener('click', function (e) {
        if (clientResults && !e.target.closest('#panneauClient')) cacherResultats();
    });

    // Remise fidélité : usage plafonné (solde, minimum, total de la vente)
    function calculerRemiseFidelite() {
        if (!FIDELITE.actif || !clientActuel || !pointsUtilises || !lblRemiseFidelite) return 0;
        var pts = Math.max(0, parseInt(pointsUtilises.value || '0', 10) || 0);
        var solde = Math.max(0, parseInt(clientActuel.points_fidelite || 0, 10) || 0);
        var usage = Math.min(solde, pts);
        if (usage > 0 && totalBrut > 0) {
            var maxU = Math.floor(totalBrut * 100 / FIDELITE.valeurPoint);
            usage = Math.min(usage, Math.max(0, maxU));
        }
        if (usage > 0 && usage < FIDELITE.minPoints) {
            lblRemiseFidelite.innerHTML = '<span class="text-danger">Minimum ' + FIDELITE.minPoints + ' points pour une remise.</span>';
            return 0;
        }
        var remise = Math.round(usage * FIDELITE.valeurPoint) / 100;
        lblRemiseFidelite.innerHTML = usage > 0
            ? '<strong>' + formatMoney(remise) + '</strong> de remise — ' + usage + ' points'
            : 'Saisissez le nombre de points à utiliser.';
        return remise;
    }

    // ============================================================
    //  RENDU DU PANIER + RECALCUL
    // ============================================================
    function rafraichirPanier() {
        var totalHt = 0;
        var totalTva = 0;
        var nbArticles = 0;
        var lignes = Object.values(panier);

        // Taux TVA effectif d'une ligne : taux spécifique article si défini, sinon taux global
        var tauxTvaDe = function (art) {
            var t = parseFloat(art && art.taux_tva);
            return isFinite(t) ? t : TAUX_TVA;
        };

        if (lignes.length === 0) {
            panierTbody.innerHTML =
                '<tr><td colspan="5" class="text-center text-muted py-5">' +
                '<i class="bi bi-cart-x fs-1 d-block"></i>' +
                'Panier vide — scannez un produit pour commencer.' +
                '</td></tr>';
            btnValider.disabled = true;
        } else {
            panierTbody.innerHTML = lignes.map(function (l) {
                var sousTotal = l.article.prix_unitaire * l.qte;
                totalHt += sousTotal;
                totalTva += sousTotal * tauxTvaDe(l.article) / 100;
                nbArticles += l.qte;

                // Déterminer l'affichage du prix (promo ou normal)
                var promo = l.article.promotion || null;
                var prixHtml = '';
                if (promo) {
                    prixHtml =
                        '<div class="d-flex flex-column align-items-end">' +
                        '<small class="text-decoration-line-through text-muted" style="font-size:.75rem">' + formatMoney(promo.prix_original) + '</small>' +
                        '<span class="fw-bold text-danger">' + formatMoney(l.article.prix_unitaire) + '</span>' +
                        '</div>' +
                        '<span class="badge glass-badge-qty is-danger mt-1" style="font-size:.65rem">-' + promo.remise_pct + '%</span>';
                } else {
                    prixHtml = formatMoney(l.article.prix_unitaire);
                }

                // Badge promotion dans le nom de l'article
                var promoLabel = '';
                if (promo) {
                    promoLabel = '<br><span class="badge glass-badge-qty is-danger" style="font-size:.65rem"><i class="bi bi-lightning"></i> ' + escapeHtml(promo.label) + '</span>';
                }

                return '<tr data-id="' + l.article.id + '">' +
                    '<td>' +
                    '<div class="fw-semibold">' + escapeHtml(l.article.nom) + promoLabel + '</div>' +
                    '<small class="text-muted"><code>' + escapeHtml(l.article.code_barre) + '</code></small>' +
                    '</td>' +
                    '<td class="text-end">' + prixHtml + '</td>' +
                    '<td class="text-center">' +
                    '<div class="btn-group btn-group-sm">' +
                    '<button type="button" class="btn btn-outline-secondary btn-decrease" data-id="' + l.article.id + '">−</button>' +
                    '<span class="btn btn-light disabled qty-badge">' + l.qte + '</span>' +
                    '<button type="button" class="btn btn-outline-secondary btn-increase" data-id="' + l.article.id + '">+</button>' +
                    '</div>' +
                    '</td>' +
                    '<td class="text-end fw-bold">' + formatMoney(sousTotal) + '</td>' +
                    '<td class="text-center">' +
                    '<button type="button" class="btn btn-sm btn-outline-danger btn-delete" data-id="' + l.article.id + '" title="Retirer">' +
                    '<i class="bi bi-trash"></i>' +
                    '</button>' +
                    '</td>' +
                    '</tr>';
            }).join('');
            btnValider.disabled = false;
        }

        var montantTva = totalTva;
        totalBrut = totalHt + montantTva;

        totalHtEl.textContent    = formatMoney(totalHt);
        if (tvaDisplay) tvaDisplay.textContent = formatMoney(montantTva);
        nbArticlesEl.textContent = nbArticles;

        mettreAJourTotaux();
        recalculerPaiement();
    }

    // Met à jour le total TTC affiché (brut − remise fidélité) et le champ caché
    function mettreAJourTotaux() {
        var ttc = Math.max(0, totalBrut - calculerRemiseFidelite());
        totalTtcEl.textContent   = formatMoney(ttc);
        totalTtcEl.dataset.raw   = ttc.toFixed(2);
        totalTtcEl.dataset.brut  = totalBrut.toFixed(2);
        var inpTotalTtc = document.getElementById('inpTotalTtc');
        if (inpTotalTtc) inpTotalTtc.value = ttc.toFixed(2);
        return ttc;
    }

    // ============================================================
    //  PAIEMENT : MONNAIE À RENDRE + MULTI-MODE
    // ============================================================
    const modeTabs = document.getElementById('modePaiementTabs');
    const zoneEspeces = document.getElementById('zoneEspeces');
    const zoneMobile = document.getElementById('zoneMobile');
    const zoneCarte = document.getElementById('zoneCarte');
    const montantMobile = document.getElementById('montantMobile');
    const montantCarte = document.getElementById('montantCarte');
    const referenceMobile = document.getElementById('referenceMobile');
    const progressPaiement = document.getElementById('progressPaiement');
    const payeDisplay = document.getElementById('payeDisplay');
    const paiementsJson = document.getElementById('paiementsJson');
    let modeActif = 'Especes';

    if (modeTabs) {
        modeTabs.addEventListener('click', function(e) {
            var btn = e.target.closest('.btn-mode-paiement');
            if (!btn) return;
            modeTabs.querySelectorAll('.btn-mode-paiement').forEach(function(b) { b.classList.remove('active'); });
            btn.classList.add('active');
            modeActif = btn.dataset.mode;

            zoneEspeces.classList.toggle('d-none', modeActif !== 'Especes');
            zoneMobile.classList.toggle('d-none', modeActif !== 'Mobile_Money');
            zoneCarte.classList.toggle('d-none', modeActif !== 'Carte_Bancaire');

            if (modeActif === 'Mobile_Money' && montantMobile) {
                montantMobile.focus();
            } else if (modeActif === 'Carte_Bancaire' && montantCarte) {
                montantCarte.focus();
            }
            recalculerPaiement();
        });
    }

    if (inpMontantPaye) inpMontantPaye.addEventListener('input', recalculerPaiement);
    if (montantMobile) montantMobile.addEventListener('input', recalculerPaiement);
    if (montantCarte) montantCarte.addEventListener('input', recalculerPaiement);

    function recalculerPaiement() {
        var ttc = mettreAJourTotaux();
        var especes = parseFloat(inpMontantPaye?.value || 0);
        var mobile = parseFloat(montantMobile?.value || 0);
        var carte = parseFloat(montantCarte?.value || 0);

        var totalPaye = especes + mobile + carte;
        var reste = ttc - totalPaye;
        // Monnaie à rendre : excédent de l'ensemble des paiements (tous modes confondus),
        // cohérent avec le calcul serveur (montant_paye - total_ttc).
        var monnaie = totalPaye > ttc ? Math.max(0, totalPaye - ttc) : 0;

        // Monnaie (uniquement en mode Espèces avec paiement suffisant)
        var monnaieEl = document.getElementById('monnaieRendue');
        if (monnaieEl) {
            monnaieEl.textContent = modeActif === 'Especes' && monnaie > 0 ? formatMoney(monnaie) : '';
        }

        // Progression
        if (progressPaiement) {
            var pct = ttc > 0 ? Math.min(100, (totalPaye / ttc) * 100) : 0;
            progressPaiement.style.width = pct + '%';
            progressPaiement.className = 'progress-bar ' + (pct >= 100 ? 'bg-success' : pct > 0 ? 'bg-warning' : 'bg-secondary');
        }
        if (payeDisplay) {
            payeDisplay.textContent = formatMoney(totalPaye) + ' / ' + formatMoney(ttc);
        }

        // Données JSON pour le formulaire
        var paiements = [];
        if (especes > 0) paiements.push({ mode_paiement: 'Especes', montant: especes });
        if (mobile > 0) paiements.push({ mode_paiement: 'Mobile_Money', montant: mobile, reference: referenceMobile?.value || null });
        if (carte > 0) paiements.push({ mode_paiement: 'Carte_Bancaire', montant: carte });
        if (paiementsJson) paiementsJson.value = JSON.stringify(paiements);

        // Activer/désactiver le bouton
        if (btnValider) {
            var panierVide = Object.keys(panier).length === 0;
            btnValider.disabled = panierVide || totalPaye < ttc || ttc <= 0;
        }
    }

    // ============================================================
    //  VALIDATION : EN LIGNE OU HORS-LIGNE
    // ============================================================
    if (formPaiement) formPaiement.addEventListener('submit', function (e) {
        if (Object.keys(panier).length === 0) {
            e.preventDefault();
            alerter('Le panier est vide.', 'warning');
            return;
        }

        var ttc = parseFloat(document.getElementById('inpTotalTtc')?.value || 0);
        var especes = parseFloat(inpMontantPaye?.value || 0);
        var mobile = parseFloat(montantMobile?.value || 0);
        var carte = parseFloat(montantCarte?.value || 0);
        var totalPaye = especes + mobile + carte;
        if (totalPaye < ttc) {
            e.preventDefault();
            alerter('Le montant payé est insuffisant (' + formatMoney(totalPaye) + ' / ' + formatMoney(ttc) + ').', 'danger');
            return;
        }

        // ---- MODE HORS-LIGNE : sauvegarder dans localStorage ----
        if (!navigator.onLine) {
            e.preventDefault();
            sauvegarderVenteHorsLigne(ttc, totalPaye);
            return;
        }

        // ---- MODE EN LIGNE : envoi classique vers valider_facture.php ----
        var conteneur = document.getElementById('conteneurPanier');
        if (conteneur) conteneur.querySelectorAll('input[data-dyn]').forEach(function (i) { i.remove(); });

        if (conteneur) {
            Object.values(panier).forEach(function (l) {
                var a = document.createElement('input');
                a.type = 'hidden'; a.dataset.dyn = '1';
                a.name = 'lignes[' + l.article.id + '][article_id]';
                a.value = l.article.id;
                conteneur.appendChild(a);

                var b = document.createElement('input');
                b.type = 'hidden'; b.dataset.dyn = '1';
                b.name = 'lignes[' + l.article.id + '][quantite]';
                b.value = l.qte;
                conteneur.appendChild(b);

                var c = document.createElement('input');
                c.type = 'hidden'; c.dataset.dyn = '1';
                c.name = 'lignes[' + l.article.id + '][prix_unitaire]';
                c.value = l.article.prix_unitaire.toFixed(2);
                conteneur.appendChild(c);
            });
        }
    });

    // ============================================================
    //  SAUVEGARDE HORS-LIGNE
    // ============================================================
    function sauvegarderVenteHorsLigne(totalTtc, montantPaye) {
        var lignes = Object.values(panier).map(function (l) {
            return {
                article_id:    l.article.id,
                code_barre:    l.article.code_barre || '',
                nom:           l.article.nom || '',
                quantite:      l.qte,
                prix_unitaire: l.article.prix_unitaire,
                taux_tva:      (l.article.taux_tva != null && isFinite(parseFloat(l.article.taux_tva)))
                    ? parseFloat(l.article.taux_tva)
                    : TAUX_TVA
            };
        });

        // HT et TVA calculées ligne par ligne (taux spécifique article ou taux global)
        var totalHt = 0;
        var totalTva = 0;
        lignes.forEach(function (l) {
            var ht = l.prix_unitaire * l.quantite;
            totalHt += ht;
            totalTva += ht * l.taux_tva / 100;
        });

        var vente = {
            client_sale_id: genererUUID(),
            timestamp:      new Date().toISOString(),
            user_id:        (config.user_id || 0),
            magasin_id:     (config.magasin_id || 0),
            client_id:      (clientActuel ? clientActuel.id : 0),
            points_utilises: clientActuel
                ? Math.max(0, parseInt(pointsUtilises ? pointsUtilises.value || '0' : '0', 10) || 0)
                : 0,
            lignes:         lignes,
            total_ht:       Math.round(totalHt * 100) / 100,
            tva_taux:       TAUX_TVA,
            total_ttc:      totalTtc,
            montant_paye:   montantPaye,
            monnaie_rendue: Math.max(0, montantPaye - totalTtc),
            paiements_json: paiementsJson ? paiementsJson.value : '[]',
            statut:         'pending'
        };

        ajouterVenteEnAttente(vente);

        alerter(
            'Vente sauvegardée localement (' + lignes.length + ' article(s), '
            + formatMoney(totalTtc) + '). Elle sera synchronisée quand le réseau reviendra.',
            'success'
        );

        // Réinitialiser le panier
        panier = {};
        retirerClient();
        rafraichirPanier();
        if (inpMontantPaye) inpMontantPaye.value = '';
        if (montantMobile) montantMobile.value = '';
        if (montantCarte) montantCarte.value = '';
        if (referenceMobile) referenceMobile.value = '';
        recalculerPaiement();
    }

    // ============================================================
    //  UTILITAIRES
    // ============================================================
    function alerter(message, type) {
        type = type || 'info';
        var wrapper = document.createElement('div');
        wrapper.className = 'alert alert-' + type + ' alert-dismissible fade show py-2';
        wrapper.innerHTML = escapeHtml(message) +
            ' <button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        if (zoneAlerte) zoneAlerte.appendChild(wrapper);
        if (type === 'success' || type === 'info') {
            setTimeout(function () {
                if (wrapper.parentNode) {
                    wrapper.classList.remove('show');
                    setTimeout(function () { wrapper.remove(); }, 200);
                }
            }, 4000);
        }
    }

    function formatMoney(n) {
        var valeur = Number.parseFloat(n || 0) || 0;
        var signe = valeur < 0 ? '-' : '';
        var parties = Math.abs(valeur).toFixed(DEVISE_DECIMALES).split('.');
        var entier = parties[0].replace(/\B(?=(\d{3})+(?!\d))/g, SEPARATEUR_MILLIERS);
        var nombre = DEVISE_DECIMALES > 0
            ? signe + entier + SEPARATEUR_DECIMAL + parties[1]
            : signe + entier;
        if (!DEVISE_SYMBOLE) return nombre;
        return DEVISE_POSITION === 'avant'
            ? DEVISE_SYMBOLE + ' ' + nombre
            : nombre + ' ' + DEVISE_SYMBOLE;
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    // ---- Raccourcis clavier ----
    document.addEventListener('keydown', function (e) {
        if (e.key === 'F2') { e.preventDefault(); viderPanier(); }
        if (e.key === 'F8') { e.preventDefault(); if (btnValider) btnValider.click(); }
    });

    // Premier rendu
    rafraichirPanier();
});
