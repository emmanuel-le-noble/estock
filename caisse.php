<?php
/**
 * caisse.php - Interface de Point de Vente (POS).
 *
 * - Champ "Scanner code-barres" à focus permanent.
 * - Panier en mémoire JS (caisse.js), alimenté via api/scan.
 * - Validation : POST vers valider_facture.php (en ligne) ou
 *   sauvegarde localStorage (hors-ligne) avec sync automatique.
 */
// Guard: compatible avec le routeur front controller (index.php)
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_role(ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_VENDEUR);

$taux_tva = param_tva_taux();
$devise_symbole = param('devise_symbole', 'FCFA');
$montants_rapides = array_values(array_filter([
    param_float('paiement_rapide_1', 20),
    param_float('paiement_rapide_2', 50),
    param_float('paiement_rapide_3', 100),
], static fn($montant) => $montant > 0));

$u = user_courant();
$magasin_id = (int)($u['magasin_id'] ?? 0);
$user_id = (int)($u['id'] ?? 0);

// Vérifier si la caisse est clôturée pour aujourd'hui
$caisse_fermee = db_cloture_deja_ferme($pdo, $magasin_id, $user_id);

// (La validation effective est dans valider_facture.php, on POST vers lui.)

$titre_page = 'Caisse';
include __DIR__ . '/includes/header.php';
?>
<!-- Configuration POS + données utilisateur pour le mode hors-ligne -->
<script nonce="<?= h(csp_nonce()) ?>">
window.POS_CONFIG = <?= json_encode(array_merge(param_pos_config(), [
    'user_id'    => $user_id,
    'magasin_id' => $magasin_id,
]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.BASE_URL = <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.CAISSE_FERMEE = <?= $caisse_fermee ? 'true' : 'false' ?>;
</script>

<?php if ($caisse_fermee): ?>
<div class="alert alert-warning d-flex align-items-center mb-3" role="alert">
    <i class="bi bi-lock-fill fs-4 me-2"></i>
    <div class="flex-grow-1">
        <strong>Caisse clôturée.</strong> La session de vente est fermée pour aujourd'hui.
        <a href="cloture.php?recap=1" class="alert-link">Voir le récapitulatif</a>
        ou
        <a href="cloture.php" class="alert-link">effectuer la clôture</a>.
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
    <!-- ===== Colonne gauche : scan + panier ===== -->
    <div class="col-lg-8">
        <!-- Zone de scan (sticky) -->
        <div class="scan-zone card shadow-sm mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-upc-scan text-primary"></i> Scanner code-barres</h6>
                <?php if (!$caisse_fermee): ?>
                <a href="cloture.php" class="btn btn-outline-warning btn-sm fw-bold"
                   title="Clôturer la caisse en fin de journée">
                    <i class="bi bi-lock"></i> Clôturer la Caisse
                </a>
                <?php else: ?>
                <span class="badge text-bg-secondary"><i class="bi bi-lock-fill"></i> Fermée</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <label for="scanInput" class="form-label fw-bold mb-2">
                    <i class="bi bi-upc-scan text-primary"></i> Scanner code-barres
                </label>
                <div class="input-group input-group-lg">
                    <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                    <input type="text" id="scanInput" class="form-control"
                           placeholder="<?= $caisse_fermee ? 'Caisse clôturée — scan désactivé' : 'Scannez ou saisissez un code-barres puis Entrée...' ?>"
                           autocomplete="off"
                           <?= $caisse_fermee ? 'disabled' : '' ?>>
                </div>
                <div class="form-text">
                    <i class="bi bi-keyboard"></i> Raccourcis : <kbd>F2</kbd> Vider le panier · <kbd>F8</kbd> Valider
                </div>
            </div>
        </div>

        <!-- Zone d'alertes dynamiques -->
        <div id="zoneAlerte"></div>

        <!-- Panier -->
        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-cart3"></i> Panier en cours
                    <span class="badge text-bg-primary" id="nbArticles">0</span>
                </h5>
                <button type="button" id="btnViderPanier" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-trash"></i> Vider
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm panier-table mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Article</th>
                                <th class="text-end">P.U.</th>
                                <th class="text-center">Qté</th>
                                <th class="text-end">Sous-total</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="panierTbody">
                            <tr><td colspan="5" class="text-center text-muted py-5">
                                <i class="bi bi-cart-x fs-1 d-block"></i>
                                Panier vide — scannez un produit pour commencer.
                            </td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== Colonne droite : totaux + paiement ===== -->
    <div class="col-lg-4">
        <div class="card shadow-sm sticky-top" style="top:80px;">
            <div class="card-header pay-head">
                <h5 class="mb-0 text-white"><i class="bi bi-calculator"></i> Paiement</h5>
            </div>
            <div class="card-body">
                <form id="formPaiement" method="post" action="<?= 'valider_facture.php' ?>">
                    <?= csrf_field() ?>
                    <div id="conteneurPanier"></div>
                    <input type="hidden" id="inpTotalTtc" name="total_ttc" value="0">

                    <table class="table table-borderless mb-3">
                        <tr>
                            <th class="text-end">Total HT :</th>
                            <td class="text-end fs-5" id="totalHt"><?= h(money(0)) ?></td>
                        </tr>
                        <tr>
                            <th class="text-end">TVA (<?= h(number_format($taux_tva, 2, ',', ' ')) ?>%) :</th>
                            <td class="text-end text-muted small">
                                <span id="tvaDisplay"></span>
                            </td>
                        </tr>
                        <tr class="border-top">
                            <th class="text-end fs-4">Total TTC :</th>
                            <td class="text-end fs-4 fw-bold text-success" id="totalTtc"><?= h(money(0)) ?></td>
                        </tr>
                    </table>

                    <!-- Panneau Multi-Paiements -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Mode de paiement</label>
                        <div class="d-flex gap-1 mb-2" id="modePaiementTabs">
                            <button type="button" class="btn btn-sm btn-mode-paiement active" data-mode="Especes">
                                <i class="bi bi-cash"></i> Espèces
                            </button>
                            <button type="button" class="btn btn-sm btn-mode-paiement" data-mode="Mobile_Money">
                                <i class="bi bi-phone"></i> Mobile
                            </button>
                            <button type="button" class="btn btn-sm btn-mode-paiement" data-mode="Carte_Bancaire">
                                <i class="bi bi-credit-card"></i> Carte
                            </button>
                        </div>

                        <!-- Zone Espèces -->
                        <div class="zone-paiement" id="zoneEspeces">
                            <div class="input-group input-group-lg mb-2">
                                <input type="number" step="0.01" min="0" id="montantPaye"
                                       class="form-control" placeholder="Montant reçu">
                                <span class="input-group-text"><i class="bi bi-cash"></i></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-muted small">Monnaie à rendre :</span>
                                <span class="fs-4 fw-bold text-warning" id="monnaieRendue"><?= h(money(0)) ?></span>
                            </div>
                        </div>

                        <!-- Zone Mobile Money / Carte -->
                        <div class="zone-paiement d-none" id="zoneMobile">
                            <div class="input-group input-group-lg mb-2">
                                <input type="number" step="0.01" min="0" id="montantMobile"
                                       class="form-control" placeholder="Montant payé">
                                <span class="input-group-text"><i class="bi bi-phone"></i></span>
                            </div>
                            <input type="text" id="referenceMobile" class="form-control mb-2"
                                   placeholder="Référence (optionnel)">
                        </div>
                        <div class="zone-paiement d-none" id="zoneCarte">
                            <div class="input-group input-group-lg mb-2">
                                <input type="number" step="0.01" min="0" id="montantCarte"
                                       class="form-control" placeholder="Montant payé">
                                <span class="input-group-text"><i class="bi bi-credit-card"></i></span>
                            </div>
                        </div>

                        <!-- Progression -->
                        <div class="mb-2">
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="text-muted">Payé</span>
                                <span id="payeDisplay" class="fw-bold">0 <?= h($devise_symbole) ?></span>
                            </div>
                            <div class="progress" style="height:8px">
                                <div class="progress-bar bg-success" id="progressPaiement" style="width:0%"></div>
                            </div>
                        </div>

                        <!-- Boutons montant rapide -->
                        <div class="d-flex gap-2 mb-3 flex-wrap" id="quickPayContainer">
                            <button type="button" class="btn btn-outline-secondary btn-sm btn-quick-pay" data-type="exact">Exact</button>
                            <?php foreach ($montants_rapides as $montant): ?>
                                <button type="button" class="btn btn-outline-secondary btn-sm btn-quick-pay"
                                        data-type="amount"
                                        data-amount="<?= h(number_format($montant, 2, '.', '')) ?>"><?= h(money($montant)) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Inputs cachés pour les paiements -->
                    <input type="hidden" id="paiementsJson" name="paiements_json" value="[]">

                    <button type="submit" id="btnValider" class="btn btn-success btn-lg w-100" disabled <?= $caisse_fermee ? 'disabled' : '' ?>>
                        <i class="bi bi-check2-circle"></i> Valider et Enregistrer
                    </button>
                    <div class="form-text text-center mt-2">
                        <?php if ($caisse_fermee): ?>
                            <span class="text-warning"><i class="bi bi-lock"></i> La caisse est clôturée. Nouvelle vente impossible.</span>
                        <?php else: ?>
                            Le stock sera décrémenté automatiquement.
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= h(csp_nonce()) ?>" src="<?= BASE_URL ?>assets/js/caisse.js"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
