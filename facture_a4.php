<?php
/**
 * facture_a4.php - Facture A5 professionnelle intégrée dans le layout app.
 * Usage : facture_a4.php?facture_id=<id>
 */
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }

require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';

$factureId = (int)($_GET['facture_id'] ?? 0);
if ($factureId <= 0) { flash_error('Paramètre invalide.'); redirect('factures.php'); }

$token = input_string($_GET['token'] ?? '');
if (!est_connecte()) {
    if (!verify_url_signature($factureId, $token)) {
        flash_error('Accès non autorisé.'); redirect('factures.php');
    }
} else {
    $role_valide = in_array(user_role(), [ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_VENDEUR], true);
    if (!peut('facturation_consulter') && !peut('caisse_gerer') && !$role_valide) {
        flash_error('Droits insuffisants.'); redirect('factures.php');
    }
}

$f = db_facture_get_by_id($pdo, $factureId);
if (!$f) { flash_error('Facture introuvable.'); redirect('factures.php'); }

if (est_connecte() && user_role() === ROLE_VENDEUR
    && (int)($f['utilisateur_id'] ?? 0) !== (int)(user_courant()['id'] ?? 0)) {
    flash_error('Accès non autorisé.'); redirect('factures.php');
}
if (est_connecte() && (int)($f['magasin_id'] ?? 0) !== user_magasin_id()) {
    flash_error('Accès non autorisé.'); redirect('factures.php');
}

$lignes = db_facture_get_lignes($pdo, $factureId);

$paiements = [];
if (function_exists('db_paiements_by_facture')) {
    $paiements = db_paiements_by_facture($pdo, $factureId);
}

$vendeurNom = $f['vendeur_nom'] ?? 'Caissier';
if (empty($vendeurNom) && !empty($f['utilisateur_id'])) {
    if (function_exists('db_user_get_by_id')) {
        $vu = db_user_get_by_id($pdo, (int)$f['utilisateur_id']);
        $vendeurNom = $vu['nom'] ?? 'Caissier';
    }
}

$nom_boutique = param_shop_name();
$adresse_boutique = trim(param('adresse_boutique', ''));
$code_postal = trim(param('code_postal', ''));
$pays = trim(param('pays', 'Togo'));
$telephone = trim(param('telephone_boutique', ''));
$email = trim(param('email_boutique', ''));
$site = trim(param('site_boutique', ''));
$nif = trim(param('nif_boutique', ''));
$rccm = trim(param('rccm_boutique', ''));
$regime_tpu = param_regime_tpu();
$localisation = trim(implode(' ', array_filter([$code_postal, $pays])));

$tauxTva = param_tva_taux();
$devise = param('devise_symbole', 'FCFA');

$totalRemises = 0.0;
foreach ($lignes as $l) {
    if (!empty($l['prix_original']) && !empty($l['remise_pct']) && (float)$l['remise_pct'] > 0) {
        $totalRemises += ((float)$l['prix_original'] - (float)$l['prix_unitaire']) * (int)$l['quantite'];
    }
}
$totalRemises = round($totalRemises, 2);

$tauxTvaResume = [];
foreach ($lignes as $l) {
    $htLigne = calc_line_subtotal((float)$l['prix_unitaire'], (int)$l['quantite']);
    $tauxLigne = ($l['taux_tva'] !== null && $l['taux_tva'] !== '') ? (float)$l['taux_tva'] : $tauxTva;
    $tvaLigne = $htLigne * ($tauxLigne / 100);
    $tauxKey = number_format($tauxLigne, 2);
    if (!isset($tauxTvaResume[$tauxKey])) {
        $tauxTvaResume[$tauxKey] = ['taux' => $tauxLigne, 'base_ht' => 0.0, 'montant_tva' => 0.0];
    }
    $tauxTvaResume[$tauxKey]['base_ht'] += $htLigne;
    $tauxTvaResume[$tauxKey]['montant_tva'] += $tvaLigne;
}
$multiTaux = count($tauxTvaResume) > 1;

$clientNom = trim((string)($f['client_nom'] ?? ''));
$clientNif = trim((string)($f['client_nif'] ?? ''));
$clientRaison = trim((string)($f['client_raison_sociale'] ?? ''));
$clientAdresse = trim((string)($f['client_adresse'] ?? ''));
$clientTel = trim((string)($f['client_telephone'] ?? ''));
$clientEmail = trim((string)($f['client_email'] ?? ''));
$hasClient = $clientNom !== '' || $clientRaison !== '';

$date_facture = date_fr($f['date_facture']);
$_numero = $f['numero_facture'];
$annee_facture = date('Y', strtotime($f['date_facture']));
$statsut = $f['statut'] ?? 'Payee';
$is_annulee = ($statsut === 'Annulee');

$ticket_entete = trim(param('ticket_entete', ''));
$ticket_remarque = trim(param('ticket_remarque', ''));

$titre_page = 'Facture ' . $_numero;
include __DIR__ . '/includes/letterhead.php';
include __DIR__ . '/includes/header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/print-documents.css">
<style>
/* === A5 INVOICE CARD — Palette rouge/orange/vert === */
.invoice-preview-wrap {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding: 24px 16px 40px;
}

.invoice-a5 {
    width: 148mm;
    min-height: 210mm;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 8px 32px rgba(0,0,0,.10), 0 2px 8px rgba(0,0,0,.06);
    padding: 0 0 8mm;
    position: relative;
    overflow: hidden;
    font-family: 'Poppins', sans-serif;
    font-size: 9.5pt;
    color: #1a1a1a;
    line-height: 1.4;
    display: flex;
    flex-direction: column;
}

/* Filigrane ANNULÉE */
.invoice-a5.watermark-active::after {
    content: 'ANNULÉE';
    position: absolute;
    top: 42%;
    left: 50%;
    transform: translate(-50%, -50%) rotate(-30deg);
    font-size: 54pt;
    font-weight: 900;
    color: rgba(213,43,30,.06);
    text-transform: uppercase;
    letter-spacing: 6px;
    pointer-events: none;
    z-index: 0;
}

/* Paiements */
.inv-pay-section { margin-bottom: 10px; padding: 0 16px; position: relative; z-index: 2; }
.inv-pay-title {
    font-size: 8.5pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--doc-red, #D52B1E);
    margin-bottom: 4px;
    padding-bottom: 2px;
    border-bottom: 1px solid var(--doc-gray, #e0e0e0);
}
.inv-pay-grid { display: flex; gap: 6px; flex-wrap: wrap; }
.inv-pay-card {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 4px;
    padding: 4px 8px;
}
.inv-pay-mode { font-size: 8pt; color: #166534; font-weight: 700; text-transform: uppercase; }
.inv-pay-amount { font-size: 8.5pt; font-weight: 700; color: #15803d; }

/* Remise chips */
.remise-chip {
    display: inline-block;
    background: #fef3c7;
    color: #92400e;
    font-size: 8pt;
    font-weight: 700;
    padding: 1px 4px;
    border-radius: 2px;
}
.old-p { color: #94a3b8; text-decoration: line-through; font-size: 8.5pt; }

/* TVA note */
.tva-note { font-style: italic; font-size: 8.5pt; color: #94a3b8; background: none; }

/* Content padding */
.inv-content { padding: 0 16px; flex: 1; display: flex; flex-direction: column; position: relative; z-index: 2; }

/* --- Action bar --- */
.inv-action-bar {
    display: flex;
    justify-content: center;
    gap: 8px;
    padding: 16px;
}
.inv-action-bar .btn { font-size: .85rem; }

/* --- Print --- */
@media print {
    .no-print { display: none !important; }
    body { background: #fff !important; }
    .app-shell { display: block !important; }
    .sidebar, .app-topbar, .sidebar-backdrop { display: none !important; }
    .app-main { margin: 0 !important; padding: 0 !important; }
    .app-content { padding: 0 !important; }
    .invoice-preview-wrap { padding: 0; }
    .invoice-a5 {
        width: 100%;
        min-height: auto;
        border-radius: 0;
        box-shadow: none;
        padding: 0;
        border: none;
    }
    .inv-badge { border: 1px solid currentColor; }
    @page { size: A5; margin: 8mm; }
}
</style>

<!-- BARRE D'ACTION -->
<div class="inv-action-bar no-print">
    <a href="factures.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Retour
    </a>
    <button onclick="window.print()" class="btn btn-primary" id="btnPrintA5">
        <i class="bi bi-printer"></i> Imprimer la facture
    </button>
    <a href="ticket_print.php?facture_id=<?= $factureId ?>" class="btn btn-outline-dark" target="_blank">
        <i class="bi bi-receipt"></i> Ticket thermique
    </a>
</div>

<div class="invoice-preview-wrap">
    <div class="invoice-a5 <?= $is_annulee ? 'watermark-active' : '' ?>">

        <!-- EN-TÊTE (logo + nom entreprise) -->
        <?= letterhead_header() ?>

        <!-- BANDEAU CHEVRON VERT -->
        <?= letterhead_chevron_top() ?>

        <!-- TITRE DU DOCUMENT -->
        <?= letterhead_doc_title('Facture', h($_numero), h($date_facture),
            $is_annulee ? 'cancelled' : ((float)$f['montant_paye'] >= (float)$f['total_ttc'] ? 'paid' : 'unpaid'),
            $is_annulee ? 'Annulée' : ((float)$f['montant_paye'] >= (float)$f['total_ttc'] ? 'Payée' : 'En attente')
        ) ?>

        <!-- CONTENU -->
        <div class="inv-content">

            <!-- CLIENT / VENDEUR -->
            <div class="doc-parties">
                <div class="doc-party">
                    <div class="doc-party-label">Client</div>
                    <?php if ($hasClient): ?>
                        <div class="doc-party-name"><?= h($clientRaison ?: $clientNom) ?></div>
                        <div class="doc-party-detail">
                            <?php if ($clientRaison && $clientNom): ?><span><?= h($clientNom) ?></span><?php endif; ?>
                            <?php if ($clientNif): ?><span>NIF : <?= h($clientNif) ?></span><?php endif; ?>
                            <?php if ($clientAdresse): ?><span><?= h($clientAdresse) ?></span><?php endif; ?>
                            <?php if ($clientTel): ?><span>Tél : <?= h($clientTel) ?></span><?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="doc-party-name">Client de passage</div>
                    <?php endif; ?>
                </div>
                <div class="doc-party">
                    <div class="doc-party-label">Vendeur</div>
                    <div class="doc-party-name"><?= h($vendeurNom) ?></div>
                    <div class="doc-party-detail">
                        <span>Magasin : <?= h(user_magasin_nom($pdo)) ?></span>
                    </div>
                </div>
            </div>

            <!-- LIGNES -->
            <div class="doc-table-wrap">
                <table class="doc-table">
                    <thead>
                        <tr>
                            <th style="width:4%">#</th>
                            <th style="width:38%">Article</th>
                            <th class="c" style="width:7%">Qté</th>
                            <?php if ($totalRemises > 0): ?>
                            <th class="r" style="width:14%"><s>P.U. orig.</s></th>
                            <th class="c" style="width:8%">Rem.</th>
                            <?php endif; ?>
                            <th class="r" style="width:14%">P.U.</th>
                            <th class="r" style="width:15%">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $idx = 0; foreach ($lignes as $l):
                            $idx++;
                            $st = calc_line_subtotal((float)$l['prix_unitaire'], (int)$l['quantite']);
                            $hasRemise = !empty($l['prix_original']) && !empty($l['remise_pct']) && (float)$l['remise_pct'] > 0;
                        ?>
                        <tr>
                            <td class="c" style="color:#94a3b8;"><?= $idx ?></td>
                            <td>
                                <div class="a-name"><?= h($l['article_nom']) ?></div>
                                <?php if (!empty($l['article_code'])): ?><div class="a-ref">Réf : <?= h($l['article_code']) ?></div><?php endif; ?>
                            </td>
                            <td class="c"><?= (int)$l['quantite'] ?></td>
                            <?php if ($totalRemises > 0): ?>
                            <td class="r"><?= $hasRemise ? '<span class="old-p">' . h(money((float)$l['prix_original'])) . '</span>' : '—' ?></td>
                            <td class="c"><?= $hasRemise ? '<span class="remise-chip">-' . h(number_format((float)$l['remise_pct'], 0, ',', '')) . '%</span>' : '—' ?></td>
                            <?php endif; ?>
                            <td class="r"><?= h(money((float)$l['prix_unitaire'])) ?></td>
                            <td class="r" style="font-weight:600;"><?= h(money((float)$st)) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- TOTAUX -->
            <div class="doc-totals-wrap">
                <div class="doc-totals">
                    <div class="doc-t-row">
                        <span class="tl">Total HT</span>
                        <span class="tv"><?= h(money((float)$f['total_ht'])) ?></span>
                    </div>
                    <?php if ($multiTaux): ?>
                        <?php foreach ($tauxTvaResume as $tv): ?>
                    <div class="doc-t-row">
                        <span class="tl">TVA <?= h(number_format($tv['taux'], 2, ',', '')) ?>%</span>
                        <span class="tv"><?= h(money($tv['montant_tva'])) ?></span>
                    </div>
                        <?php endforeach; ?>
                    <?php elseif ((float)$f['tva_taux'] > 0 && !empty($tauxTvaResume)): ?>
                    <div class="doc-t-row">
                        <span class="tl">TVA <?= h(number_format((float)$f['tva_taux'], 2, ',', ' ')) ?>%</span>
                        <span class="tv"><?= h(money((float)$f['total_ttc'] - (float)$f['total_ht'])) ?></span>
                    </div>
                    <?php elseif ($regime_tpu): ?>
                    <div class="doc-t-row tva-note">
                        <span class="tl">TVA non applicable (TPU)</span>
                        <span class="tv"></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($totalRemises > 0): ?>
                    <div class="doc-t-row remise">
                        <span class="tl">Remises</span>
                        <span class="tv">-<?= h(money($totalRemises)) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="doc-t-row grand">
                        <span class="tl">TOTAL TTC</span>
                        <span class="tv"><?= h(money((float)$f['total_ttc'])) ?></span>
                    </div>
                </div>
            </div>

            <!-- PAIEMENTS -->
            <?php if (!empty($paiements) || (float)$f['montant_paye'] > 0): ?>
            <div class="inv-pay-section">
                <div class="inv-pay-title">Paiements</div>
                <div class="inv-pay-grid">
                    <?php foreach ($paiements as $p): ?>
                    <div class="inv-pay-card">
                        <div class="inv-pay-mode"><?= h(ucfirst(str_replace('_', ' ', $p['mode_paiement'] ?? ''))) ?></div>
                        <div class="inv-pay-amount"><?= h(money((float)$p['montant'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($paiements)): ?>
                    <div class="inv-pay-card">
                        <div class="inv-pay-mode">Paiement</div>
                        <div class="inv-pay-amount"><?= h(money((float)$f['montant_paye'])) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ((float)$f['monnaie_rendue'] > 0): ?>
                <div style="margin-top:4px;font-size:9pt;color:#666;">
                    Monnaie rendue : <strong style="color:#1a1a1a;"><?= h(money((float)$f['monnaie_rendue'])) ?></strong>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div><!-- /.inv-content -->

        <!-- PIED DE PAGE -->
        <?= letterhead_footer() ?>

        <!-- BANDEAU CHEVRON ROUGE (BAS) -->
        <?= letterhead_chevron_bottom() ?>

    </div>
</div>

<script nonce="<?= h(csp_nonce()) ?>">
(function() {
    var btn = document.getElementById('btnPrintA5');
    if (btn) btn.addEventListener('click', function() { window.print(); });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
