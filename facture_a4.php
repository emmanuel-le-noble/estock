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
include __DIR__ . '/includes/header.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* === A5 INVOICE CARD === */
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
    padding: 22mm 18mm 16mm;
    position: relative;
    overflow: hidden;
    font-family: 'Poppins', sans-serif;
    font-size: 9.5pt;
    color: #1e293b;
    line-height: 1.4;
}

/* Bandeau coloré en haut */
.invoice-a5::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 5px;
    background: linear-gradient(90deg, var(--brand, #2563eb), var(--brand-2, #1d4ed8));
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
    color: rgba(220,38,38,.07);
    text-transform: uppercase;
    letter-spacing: 6px;
    pointer-events: none;
    z-index: 0;
}

/* --- En-tête --- */
.inv-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 14px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--brand, #2563eb);
    position: relative;
    z-index: 1;
}
.inv-brand { flex: 1; }
.inv-brand-name {
    font-size: 16pt;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 2px;
    letter-spacing: -0.3px;
}
.inv-brand-meta { font-size: 9pt; color: #64748b; line-height: 1.5; }
.inv-brand-meta span { display: block; }
.inv-brand-meta b { color: #475569; font-weight: 600; }

.inv-title-block { text-align: right; }
.inv-title-label {
    font-size: 12pt;
    font-weight: 700;
    color: var(--brand, #2563eb);
    text-transform: uppercase;
    letter-spacing: 1.5px;
    margin-bottom: 3px;
}
.inv-num { font-size: 11pt; font-weight: 700; color: #0f172a; }
.inv-date { font-size: 9.5pt; color: #64748b; margin-top: 1px; }
.inv-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 8.5pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 4px;
}
.inv-badge.paid { background: #dcfce7; color: #166534; }
.inv-badge.unpaid { background: #fef3c7; color: #92400e; }
.inv-badge.cancelled { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

/* --- Parties --- */
.inv-parties {
    display: flex;
    gap: 10px;
    margin-bottom: 12px;
    position: relative;
    z-index: 1;
}
.inv-party {
    flex: 1;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 5px;
    padding: 8px 10px;
}
.inv-party-label {
    font-size: 8pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--brand, #2563eb);
    margin-bottom: 4px;
    padding-bottom: 2px;
    border-bottom: 1px solid #e2e8f0;
}
.inv-party-name { font-weight: 700; font-size: 10.5pt; color: #0f172a; margin-bottom: 1px; }
.inv-party-detail { font-size: 9pt; color: #64748b; line-height: 1.45; }
.inv-party-detail span { display: block; }

/* --- Tableau --- */
.inv-table-wrap { margin-bottom: 10px; position: relative; z-index: 1; }
.inv-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 9.5pt;
}
.inv-table thead th {
    background: var(--brand-2, #1e3a5f);
    color: #fff;
    font-weight: 600;
    padding: 5px 6px;
    text-align: left;
    font-size: 8.5pt;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.inv-table thead th:first-child { border-radius: 3px 0 0 0; }
.inv-table thead th:last-child { border-radius: 0 3px 0 0; text-align: right; }
.inv-table thead th.r { text-align: right; }
.inv-table thead th.c { text-align: center; }

.inv-table tbody tr { border-bottom: 1px solid #f1f5f9; }
.inv-table tbody tr:nth-child(even) { background: #f8fafc; }
.inv-table tbody tr:last-child { border-bottom: 2px solid var(--brand-2, #1e3a5f); }
.inv-table tbody td { padding: 4px 6px; vertical-align: top; }
.inv-table tbody td.r { text-align: right; }
.inv-table tbody td.c { text-align: center; }
.inv-table .a-name { font-weight: 600; color: #0f172a; }
.inv-table .a-ref { font-size: 8.5pt; color: #94a3b8; }
.inv-table .remise-chip {
    display: inline-block;
    background: #fef3c7;
    color: #92400e;
    font-size: 8pt;
    font-weight: 700;
    padding: 1px 4px;
    border-radius: 2px;
}
.inv-table .old-p { color: #94a3b8; text-decoration: line-through; font-size: 8.5pt; }

/* --- Totaux --- */
.inv-totals-wrap {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 10px;
    position: relative;
    z-index: 1;
}
.inv-totals {
    width: 210px;
    border: 1px solid #e2e8f0;
    border-radius: 5px;
    overflow: hidden;
}
.inv-t-row {
    display: flex;
    justify-content: space-between;
    padding: 3px 8px;
    font-size: 9.5pt;
}
.inv-t-row:nth-child(even) { background: #f8fafc; }
.inv-t-row .tl { color: #64748b; }
.inv-t-row .tv { font-weight: 600; text-align: right; }
.inv-t-row.grand {
    background: var(--brand-2, #1e3a5f);
    color: #fff;
    padding: 5px 8px;
    font-size: 10pt;
    font-weight: 700;
}
.inv-t-row.grand .tl { color: #cbd5e1; }
.inv-t-row.grand .tv { color: #fff; }
.inv-t-row.remise { color: #16a34a; }
.inv-t-row.remise .tv { color: #16a34a; }
.inv-t-row.tva-note { font-style: italic; font-size: 8.5pt; color: #94a3b8; background: none; }

/* --- Paiements --- */
.inv-pay-section { margin-bottom: 10px; position: relative; z-index: 1; }
.inv-pay-title {
    font-size: 8.5pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: var(--brand, #2563eb);
    margin-bottom: 4px;
    padding-bottom: 2px;
    border-bottom: 1px solid #e2e8f0;
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

/* --- Footer --- */
.inv-footer {
    margin-top: auto;
    padding-top: 8px;
    border-top: 1.5px solid #e2e8f0;
    position: relative;
    z-index: 1;
}
.inv-footer-grid {
    display: flex;
    justify-content: space-between;
    gap: 12px;
}
.inv-footer-col { flex: 1; }
.inv-footer-col .fl {
    font-size: 8pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: var(--brand, #2563eb);
    margin-bottom: 2px;
}
.inv-footer-col .ft { font-size: 8.5pt; color: #64748b; line-height: 1.45; }
.inv-footer-col .ft span { display: block; }
.inv-legal {
    margin-top: 6px;
    padding: 5px 8px;
    background: #f1f5f9;
    border-radius: 3px;
    font-size: 8pt;
    color: #94a3b8;
    text-align: center;
    line-height: 1.4;
}
.inv-legal b { color: #475569; }

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
        padding: 12mm 14mm;
        border: none;
    }
    .invoice-a5::before { background: var(--brand, #2563eb) !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .inv-table thead th { background: var(--brand-2, #1e3a5f) !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .inv-t-row.grand { background: var(--brand-2, #1e3a5f) !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .inv-badge { border: 1px solid currentColor; }
    @page { size: A5; margin: 10mm; }
}
</style>

<div class="invoice-preview-wrap">
    <div class="invoice-a5 <?= $is_annulee ? 'watermark-active' : '' ?>">

        <!-- EN-TÊTE -->
        <div class="inv-header">
            <div class="inv-brand">
                <div class="inv-brand-name"><?= h($nom_boutique) ?></div>
                <div class="inv-brand-meta">
                    <?php if ($adresse_boutique): ?><span><?= h($adresse_boutique) ?></span><?php endif; ?>
                    <?php if ($localisation): ?><span><?= h($localisation) ?></span><?php endif; ?>
                    <?php if ($telephone): ?><span><b>Tél :</b> <?= h($telephone) ?></span><?php endif; ?>
                    <?php if ($email): ?><span><b>E-mail :</b> <?= h($email) ?></span><?php endif; ?>
                    <?php if ($nif): ?><span><b>NIF :</b> <?= h($nif) ?></span><?php endif; ?>
                    <?php if ($rccm): ?><span><b>RCCM :</b> <?= h($rccm) ?></span><?php endif; ?>
                </div>
            </div>
            <div class="inv-title-block">
                <div class="inv-title-label">Facture</div>
                <div class="inv-num">N° <?= h($_numero) ?></div>
                <div class="inv-date"><?= h($date_facture) ?></div>
                <?php if ($is_annulee): ?>
                    <span class="inv-badge cancelled">Annulée</span>
                <?php elseif ((float)$f['montant_paye'] >= (float)$f['total_ttc']): ?>
                    <span class="inv-badge paid">Payée</span>
                <?php else: ?>
                    <span class="inv-badge unpaid">En attente</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- CLIENT / VENDEUR -->
        <div class="inv-parties">
            <div class="inv-party">
                <div class="inv-party-label">Client</div>
                <?php if ($hasClient): ?>
                    <div class="inv-party-name"><?= h($clientRaison ?: $clientNom) ?></div>
                    <div class="inv-party-detail">
                        <?php if ($clientRaison && $clientNom): ?><span><?= h($clientNom) ?></span><?php endif; ?>
                        <?php if ($clientNif): ?><span>NIF : <?= h($clientNif) ?></span><?php endif; ?>
                        <?php if ($clientAdresse): ?><span><?= h($clientAdresse) ?></span><?php endif; ?>
                        <?php if ($clientTel): ?><span>Tél : <?= h($clientTel) ?></span><?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="inv-party-name">Client de passage</div>
                <?php endif; ?>
            </div>
            <div class="inv-party">
                <div class="inv-party-label">Vendeur</div>
                <div class="inv-party-name"><?= h($vendeurNom) ?></div>
                <div class="inv-party-detail">
                    <span>Magasin : <?= h(user_magasin_nom($pdo)) ?></span>
                </div>
            </div>
        </div>

        <!-- LIGNES -->
        <div class="inv-table-wrap">
            <table class="inv-table">
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
        <div class="inv-totals-wrap">
            <div class="inv-totals">
                <div class="inv-t-row">
                    <span class="tl">Total HT</span>
                    <span class="tv"><?= h(money((float)$f['total_ht'])) ?></span>
                </div>
                <?php if ($multiTaux): ?>
                    <?php foreach ($tauxTvaResume as $tv): ?>
                <div class="inv-t-row">
                    <span class="tl">TVA <?= h(number_format($tv['taux'], 2, ',', '')) ?>%</span>
                    <span class="tv"><?= h(money($tv['montant_tva'])) ?></span>
                </div>
                    <?php endforeach; ?>
                <?php elseif ((float)$f['tva_taux'] > 0 && !empty($tauxTvaResume)): ?>
                <div class="inv-t-row">
                    <span class="tl">TVA <?= h(number_format((float)$f['tva_taux'], 2, ',', ' ')) ?>%</span>
                    <span class="tv"><?= h(money((float)$f['total_ttc'] - (float)$f['total_ht'])) ?></span>
                </div>
                <?php elseif ($regime_tpu): ?>
                <div class="inv-t-row tva-note">
                    <span class="tl">TVA non applicable (TPU)</span>
                    <span class="tv"></span>
                </div>
                <?php endif; ?>
                <?php if ($totalRemises > 0): ?>
                <div class="inv-t-row remise">
                    <span class="tl">Remises</span>
                    <span class="tv">-<?= h(money($totalRemises)) ?></span>
                </div>
                <?php endif; ?>
                <div class="inv-t-row grand">
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
            <div style="margin-top:4px;font-size:9pt;color:#64748b;">
                Monnaie rendue : <strong style="color:#0f172a;"><?= h(money((float)$f['monnaie_rendue'])) ?></strong>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- PIED DE PAGE -->
        <div class="inv-footer">
            <div class="inv-footer-grid">
                <div class="inv-footer-col">
                    <?php if ($ticket_entete): ?>
                    <div class="fl">Message</div>
                    <div class="ft"><?= h($ticket_entete) ?></div>
                    <?php endif; ?>
                    <?php if ($ticket_remarque): ?>
                    <div class="ft" style="margin-top:2px;font-style:italic;"><?= h($ticket_remarque) ?></div>
                    <?php endif; ?>
                </div>
                <div class="inv-footer-col" style="text-align:right;">
                    <div class="fl">Coordonnées</div>
                    <div class="ft">
                        <?php if ($site): ?><span><?= h($site) ?></span><?php endif; ?>
                        <?php if ($telephone): ?><span>Tél : <?= h($telephone) ?></span><?php endif; ?>
                        <?php if ($email): ?><span><?= h($email) ?></span><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($nif || $rccm): ?>
            <div class="inv-legal">
                <b><?= h($nom_boutique) ?></b>
                — NIF : <?= h($nif) ?> · RCCM : <?= h($rccm) ?>
                — Facture conforme à la législation fiscale en vigueur.
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

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

<script nonce="<?= h(csp_nonce()) ?>">
document.getElementById('btnPrintA5').addEventListener('click', function() {
    window.print();
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
