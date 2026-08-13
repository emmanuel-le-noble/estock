<?php
/**
 * valider_facture.php - Enregistre une vente validée à la caisse.
 * Support complet des articles périssables (FEFO) et non périssables (Sans DLC).
 *
 * Reçoit en POST (depuis caisse.php) :
 *   lignes[article_id][article_id]
 *   lignes[article_id][quantite]
 *   lignes[article_id][prix_unitaire]
 *   total_ttc
 *   montant_paye
 *   paiements_json  (JSON array: [{mode_paiement, montant, reference}])
 */
// Guard: compatible avec le routeur front controller (index.php)
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_role(ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_VENDEUR);

$taux_tva = param_tva_taux();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('caisse.php');
}

csrf_guard('caisse.php');

$lignes      = $_POST['lignes'] ?? [];
if (!is_array($lignes)) {
    $lignes = [];
}
$totalTtcPost= (float)($_POST['total_ttc'] ?? 0);
$montantPaye = (float)($_POST['montant_paye'] ?? 0);
$clientId    = (int)($_POST['client_id'] ?? 0);
$pointsUtilises = (int)($_POST['points_utilises'] ?? 0);

// Multi-paiements : parser le JSON envoyé par caisse.js
$paiementsJsonRaw = $_POST['paiements_json'] ?? '[]';
$paiementsList = json_decode($paiementsJsonRaw, true);
if (!is_array($paiementsList)) $paiementsList = [];

// Calculer le total payé depuis les paiements individuels
$montantPayeCalc = 0.0;
foreach ($paiementsList as $p) {
    $montantPayeCalc += (float)($p['montant'] ?? 0);
}
// Si aucun paiement JSON valide, fallback sur montant_paye classique
if ($montantPayeCalc <= 0 && $montantPaye > 0) {
    $montantPayeCalc = $montantPaye;
    $paiementsList = [['mode_paiement' => 'Especes', 'montant' => $montantPaye]];
}
$montantPaye = $montantPayeCalc;

// Magasin courant
$u = user_courant();
$magasin_id = user_magasin_id();
$user_id    = (int)($u['id'] ?? 0);

if ($magasin_id <= 0) {
    flash_error('Aucun magasin affecté à votre compte. Contactez un administrateur.');
    redirect('caisse.php');
}

if (empty($lignes)) {
    flash_error('Aucun article dans le panier.');
    redirect('caisse.php');
}

// Vérifier que la caisse n'est pas déjà clôturée pour aujourd'hui
if (db_cloture_deja_ferme($pdo, $magasin_id, $user_id)) {
    flash_error('La caisse est déjà clôturée pour aujourd\'hui. Impossible d\'enregistrer une nouvelle vente.');
    redirect('cloture.php');
}

try {
    $pdo->beginTransaction();

    // ---- Fidélité : validation + verrouillage du solde client ----
    $loyautes = null;
    if ($clientId > 0) {
        $client = db_client_get_by_id($pdo, $clientId);
        if (!$client) {
            throw new RuntimeException('Client fidélité introuvable.');
        }
        if ((int)$client['consentement_fidelite'] !== 1) {
            throw new RuntimeException('Le client n\'a pas consenti au programme de fidélité.');
        }
        $loyautes = db_loyalite_verrouiller($pdo, $clientId, $pointsUtilises, $totalTtcPost);
    }

    // ---- 1) Numéro de facture unique ----
    $numero = generate_invoice_number($pdo);

    $totalHt = 0.0;
    $lignesAFacturer = [];

    // ---- 2) Vérifier + préparer chaque ligne ----
    foreach ($lignes as $key => $l) {
        $articleId   = (int)($l['article_id'] ?? 0);
        $quantite    = (int)($l['quantite'] ?? 0);
        $prixUnitaire= (float)($l['prix_unitaire'] ?? 0);

        if ($articleId <= 0 || $quantite <= 0) continue;

        // Verrouiller l'article et lire le stock réel
        $art = db_article_get_for_update($pdo, $articleId, $magasin_id);
        if (!$art) {
            throw new RuntimeException('Article introuvable (id=' . $articleId . ').');
        }
        
        // Sécurisation de l'extraction de la quantité disponible
        $stock_dispo = 0;
        if (isset($art['quantite']) && $art['quantite'] !== null) {
            $stock_dispo = (int)$art['quantite'];
        } elseif (isset($art['quantite_stock']) && $art['quantite_stock'] !== null) {
            $stock_dispo = (int)$art['quantite_stock'];
        }
        
        if ($stock_dispo < $quantite) {
            throw new RuntimeException(
                'Stock insuffisant pour « ' . $art['nom'] . ' » : '
                . $stock_dispo . ' disponible(s), ' . $quantite . ' demandé(s).'
            );
        }

        // On utilise le prix de la base
        $prixUnitaire = (float)$art['prix_vente'];
        $prixOriginal = (float)$art['prix_vente'];
        $remisePct = null;
        $promo = db_calculer_prix_article($pdo, $articleId, $magasin_id);
        if ($promo) {
            $prixUnitaire = $promo['prix_remise'];
            $prixOriginal = $promo['prix_original'];
            $remisePct = $promo['remise_pct'];
        }
        // Taux TVA effectif : spécifique à l'article si défini, sinon taux global
        $tauxTvaLigne = ($art['taux_tva'] !== null && $art['taux_tva'] !== '')
            ? (float)$art['taux_tva']
            : $taux_tva;

        $sousTotal = calc_line_subtotal($prixUnitaire, $quantite);
        $totalHt += $sousTotal;

        $lignesAFacturer[] = [
            'article_id'    => $articleId,
            'quantite'      => $quantite,
            'prix_unitaire' => $prixUnitaire,
            'prix_original' => $prixOriginal,
            'remise_pct'    => $remisePct,
            'taux_tva'      => $tauxTvaLigne,
            'stock_dispo'   => $stock_dispo
        ];
    }

    if (empty($lignesAFacturer)) {
        throw new RuntimeException('Aucune ligne valide.');
    }

    // Calcul TTC avec TVA multi-taux : somme des TTC par ligne
    $totalTtcCalc = 0.0;
    $tauxTvaResume = []; // ['taux' => ..., 'base_ht' => ..., 'montant_tva' => ...]
    foreach ($lignesAFacturer as $lf) {
        $htLigne = calc_line_subtotal($lf['prix_unitaire'], $lf['quantite']);
        $tvaLigne = $htLigne * ($lf['taux_tva'] / 100);
        $totalTtcCalc += $htLigne + $tvaLigne;
        $tauxKey = number_format((float)$lf['taux_tva'], 2);
        if (!isset($tauxTvaResume[$tauxKey])) {
            $tauxTvaResume[$tauxKey] = ['taux' => $lf['taux_tva'], 'base_ht' => 0.0, 'montant_tva' => 0.0];
        }
        $tauxTvaResume[$tauxKey]['base_ht'] += $htLigne;
        $tauxTvaResume[$tauxKey]['montant_tva'] += $tvaLigne;
    }
    // Taux TVA dominant pour la colonne factures.tva_taux (rétro-compat)
    $tauxTvaDominant = $taux_tva;
    if (count($lignesAFacturer) === 1) {
        $tauxTvaDominant = $lignesAFacturer[0]['taux_tva'];
    }

    // Remise fidélité (points) : à déduire du total TTC
    if ($loyautes !== null) {
        $totalTtcCalc = round($totalTtcCalc - $loyautes['remise'], 2);
    }

    // Anti-fraude : le total TTC envoyé par la caisse doit correspondre
    // au calcul serveur (remise fidélité incluse), tolérance 0.02
    if (abs($totalTtcPost - $totalTtcCalc) > 0.02) {
        throw new RuntimeException('Le total TTC transmis par la caisse ne correspond pas au calculé (remise fidélité incluse).');
    }

    if ($montantPaye < round($totalTtcCalc, 2)) {
        throw new RuntimeException('Le montant payé est insuffisant.');
    }
    $monnaieRendue = max(0.0, $montantPaye - $totalTtcCalc);

    // ---- 3) Insertion de la facture ----
    $factureId = db_facture_insert($pdo, [
        'numero_facture' => $numero,
        'utilisateur_id' => user_courant()['id'] ?? null,
        'total_ht'       => round($totalHt, 2),
        'tva_taux'       => round($tauxTvaDominant, 2),
        'total_ttc'      => round($totalTtcCalc, 2),
        'montant_paye'   => round($montantPaye, 2),
        'monnaie_rendue' => round($monnaieRendue, 2),
        'magasin_id'     => $magasin_id,
        'remise_fidelite'=> $loyautes !== null ? $loyautes['remise'] : 0.0,
        'points_utilises'=> $loyautes !== null ? $loyautes['points'] : 0,
    ]);

    if ($loyautes !== null) {
        $pdo->prepare("UPDATE factures SET client_id = ? WHERE id = ?")
            ->execute([$clientId, $factureId]);
    }

    // ---- 4 & 5) Lignes + déduction atomique stock lot + mouvements ----
    foreach ($lignesAFacturer as $l) {
        db_ligne_facture_insert($pdo, $factureId, $l['article_id'], $l['quantite'], $l['prix_unitaire'], $l['prix_original'], $l['remise_pct'], $l['taux_tva'] ?? null);

        // ---- SÉCURITÉ DOUBLE TYPE : PÉRISSABLE & NON PÉRISSABLE ----
        if ($magasin_id > 0) {
            $stmt_check_lot = $pdo->prepare("SELECT COUNT(*) FROM article_lots WHERE article_id = ? AND magasin_id = ?");
            $stmt_check_lot->execute([$l['article_id'], $magasin_id]);
            $has_lot = (int)$stmt_check_lot->fetchColumn();

            // Si aucun lot n'existe (vieux stocks ou produit non périssable), on initialise un lot permanent
            if ($has_lot === 0) {
                $stmt_create_lot = $pdo->prepare(
                    "INSERT IGNORE INTO article_lots (article_id, magasin_id, numero_lot, quantite, date_peremption) 
                     VALUES (?, ?, 'LOT-GENERAL', ?, NULL)"
                );
                $stmt_create_lot->execute([$l['article_id'], $magasin_id, $l['stock_dispo']]);
            }
        }

        // Déduction atomique FEFO au niveau des lots (Met nativement à jour la table stock_magasins)
        $lots_decrementes = db_deduire_stock_lot($pdo, $l['article_id'], $magasin_id, $l['quantite'], $numero);
        $lot_parts = [];
        foreach ($lots_decrementes as $ld) {
            $lot_parts[] = "Lot {$ld['numero_lot']}: -{$ld['quantite_prise']}";
        }
        $lots_info = $lot_parts ? ' [' . implode(', ', $lot_parts) . ']' : '';

        // db_deduire_stock_lot() gère la déduction sur les lots et met à jour stock_magasins.
        // La déduction sur la table articles.quantite_stock globale est maintenant gérée par un trigger
        // ou doit être consolidée pour éviter les doubles décomptes. L'appel manuel est supprimé.

        // Mouvement de stock pour traçabilité historique
        $motif_vente = 'Vente facture ' . $numero . $lots_info;
        db_mouvement_insert($pdo, $l['article_id'], user_courant()['id'] ?? null, 'Vente', $l['quantite'], $motif_vente, $magasin_id);
    }

    // ---- 6) Enregistrer les paiements multi-modes ----
    if (!empty($paiementsList)) {
        db_paiements_insert($pdo, $factureId, $paiementsList);
    }

    // ---- 7) Chaînage cryptographique NF525 (après lignes + paiements) ----
    db_facture_chainer($pdo, $factureId);

    // ---- 8) Consommation des points fidélité (solde verrouillé FOR UPDATE) ----
    if ($loyautes !== null && $loyautes['points'] > 0) {
        $pdo->prepare("UPDATE clients SET points_fidelite = points_fidelite - ? WHERE id = ?")
            ->execute([$loyautes['points'], $clientId]);
        // facture_id NULL : 1 ligne GAIN par facture (clé unique), l'utilisation
        // est tracée sur la facture via factures.points_utilises.
        $pdo->prepare(
            "INSERT INTO historique_points (client_id, facture_id, points, type_operation, commentaire, utilisateur_id)
             VALUES (?, NULL, ?, 'UTILISATION', 'Utilisation points en caisse', ?)"
        )->execute([$clientId, -$loyautes['points'], user_id()]);
    }

    $pdo->commit();

    if ($loyautes !== null) {
        db_client_attribuer_points($pdo, $clientId, $factureId, $totalTtcCalc);
    }
    suivre_activite('VENTE', 'Facture ' . $numero . ' validée — ' . count($lignesAFacturer) . ' ligne(s), TTC=' . number_format($totalTtcCalc, 2, ',', ' '));

    flash_success('Facture ' . $numero . ' enregistrée avec succès.');
    redirect(generate_signed_url('facture_view.php', (int)$factureId, ['print' => '1']));

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Erreur validation facture: ' . $e->getMessage());
    suivre_activite('ECHEC_VENTE', 'Erreur lors de la validation de vente.');
    
    flash_error('Une erreur technique est survenue lors de la vente. Veuillez réessayer.');
    redirect('caisse.php');
}