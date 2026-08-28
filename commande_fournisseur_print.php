<?php
/**
 * commande_fournisseur_print.php - Version imprimable d'une commande fournisseur.
 * Usage : commande_fournisseur_print.php?id=<id>&token=<token>
 */
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }

require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';

$cmdId = (int)($_GET['id'] ?? 0);
if ($cmdId <= 0) { flash_error('Paramètre invalide.'); redirect('commandes_fournisseur.php'); }

$token = input_string($_GET['token'] ?? '');
if (!verify_url_signature($cmdId, $token, ['action' => 'imprimer'])) {
    flash_error('Lien expiré ou invalide.'); redirect('commandes_fournisseur.php');
}

$cmd = db_commande_fournisseur_get_by_id($pdo, $cmdId);
if (!$cmd) { flash_error('Commande introuvable.'); redirect('commandes_fournisseur.php'); }

$lignes = db_commande_fournisseur_lignes_get($pdo, $cmdId);

$nom_boutique = param_shop_name();
$adresse_boutique = trim(param('adresse_boutique', ''));
$code_postal = trim(param('code_postal', ''));
$pays = trim(param('pays', 'Togo'));
$telephone = trim(param('telephone_boutique', ''));
$email = trim(param('email_boutique', ''));
$nif = trim(param('nif_boutique', ''));
$rccm = trim(param('rccm_boutique', ''));
$localisation = trim(implode(' ', array_filter([$code_postal, $pays])));
$devise = param('devise_symbole', 'FCFA');

$totalHT = 0;
foreach ($lignes as $l) {
    $totalHT += (float)$l['quantite_commandee'] * (float)$l['prix_achat_unitaire'];
}

$totalRecu = 0;
$totalReste = 0;
foreach ($lignes as $l) {
    $totalRecu += (int)$l['quantite_recue'];
    $totalReste += max(0, (int)$l['quantite_commandee'] - (int)$l['quantite_recue']);
}

$statut_libelles = [
    'Brouillon' => 'Brouillon',
    'En_Attente' => 'En attente de validation',
    'Envoyee' => 'Envoyée',
    'Recue_Partielle' => 'Reçue (partielle)',
    'Recue' => 'Reçue (totale)',
    'Annulee' => 'Annulée',
];

$badge_class = [
    'Brouillon' => 'secondary',
    'En_Attente' => 'dark',
    'Envoyee' => 'info',
    'Recue_Partielle' => 'warning',
    'Recue' => 'success',
    'Annulee' => 'danger',
][$cmd['statut']] ?? 'secondary';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commande Fournisseur #<?= $cmdId ?> — Impression</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
    :root { --brand: #2563eb; --brand-2: #1e3a5f; }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Poppins', sans-serif; font-size: 10pt; color: #1e293b; background: #f1f5f9; }

    .print-wrap { display: flex; justify-content: center; padding: 24px 16px 40px; }
    .print-card {
        width: 210mm; max-width: 100%; background: #fff; border-radius: 10px;
        box-shadow: 0 8px 32px rgba(0,0,0,.10); padding: 14mm 16mm 12mm;
        position: relative; overflow: hidden; line-height: 1.4;
    }
    .print-card::before {
        content: ''; position: absolute; top: 0; left: 0; right: 0; height: 5px;
        background: linear-gradient(90deg, var(--brand), var(--brand-2));
    }

    /* Action bar */
    .action-bar { display: flex; justify-content: center; gap: 8px; padding: 16px; }
    .action-bar .btn { font-size: .85rem; padding: 6px 16px; border-radius: 6px; cursor: pointer; border: 1px solid #cbd5e1; background: #fff; color: #334155; font-weight: 600; }
    .action-bar .btn:hover { background: #f8fafc; }
    .action-bar .btn-primary { background: var(--brand); color: #fff; border-color: var(--brand); }
    .action-bar .btn-primary:hover { background: #1d4ed8; }

    /* Header */
    .cmd-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid var(--brand); position: relative; z-index: 1; }
    .cmd-brand { flex: 1; }
    .cmd-brand-name { font-size: 16pt; font-weight: 800; color: #0f172a; margin-bottom: 2px; }
    .cmd-brand-meta { font-size: 9pt; color: #64748b; line-height: 1.5; }
    .cmd-brand-meta span { display: block; }
    .cmd-brand-meta b { color: #475569; font-weight: 600; }
    .cmd-title-block { text-align: right; }
    .cmd-title-label { font-size: 12pt; font-weight: 700; color: var(--brand); text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 3px; }
    .cmd-num { font-size: 11pt; font-weight: 700; color: #0f172a; }
    .cmd-date { font-size: 9.5pt; color: #64748b; margin-top: 1px; }
    .cmd-badge { display: inline-block; padding: 2px 10px; border-radius: 4px; font-size: 8.5pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }
    .cmd-badge.secondary { background: #e2e8f0; color: #475569; }
    .cmd-badge.dark { background: #1e293b; color: #fff; }
    .cmd-badge.info { background: #e0f2fe; color: #0369a1; }
    .cmd-badge.warning { background: #fef3c7; color: #92400e; }
    .cmd-badge.success { background: #dcfce7; color: #166534; }
    .cmd-badge.danger { background: #fee2e2; color: #991b1b; }

    /* Parties */
    .cmd-parties { display: flex; gap: 12px; margin-bottom: 14px; position: relative; z-index: 1; }
    .cmd-party { flex: 1; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 5px; padding: 10px 12px; }
    .cmd-party-label { font-size: 8pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; color: var(--brand); margin-bottom: 4px; padding-bottom: 2px; border-bottom: 1px solid #e2e8f0; }
    .cmd-party-name { font-weight: 700; font-size: 10.5pt; color: #0f172a; margin-bottom: 1px; }
    .cmd-party-detail { font-size: 9pt; color: #64748b; line-height: 1.45; }
    .cmd-party-detail span { display: block; }

    /* Notes */
    .cmd-notes { background: #fffbeb; border: 1px solid #fde68a; border-radius: 5px; padding: 8px 12px; margin-bottom: 14px; font-size: 9pt; color: #92400e; position: relative; z-index: 1; }

    /* Table */
    .cmd-table-wrap { margin-bottom: 14px; position: relative; z-index: 1; }
    .cmd-table { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
    .cmd-table thead th { background: var(--brand-2); color: #fff; font-weight: 600; padding: 6px 8px; text-align: left; font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.4px; }
    .cmd-table thead th:first-child { border-radius: 3px 0 0 0; }
    .cmd-table thead th:last-child { border-radius: 0 3px 0 0; text-align: right; }
    .cmd-table thead th.r { text-align: right; }
    .cmd-table thead th.c { text-align: center; }
    .cmd-table tbody tr { border-bottom: 1px solid #f1f5f9; }
    .cmd-table tbody tr:nth-child(even) { background: #f8fafc; }
    .cmd-table tbody tr:last-child { border-bottom: 2px solid var(--brand-2); }
    .cmd-table tbody td { padding: 5px 8px; vertical-align: top; }
    .cmd-table tbody td.r { text-align: right; }
    .cmd-table tbody td.c { text-align: center; }
    .cmd-table .a-name { font-weight: 600; color: #0f172a; }
    .cmd-table .a-ref { font-size: 8.5pt; color: #94a3b8; }
    .cmd-table .badge-ok { background: #dcfce7; color: #166534; font-size: 8pt; font-weight: 700; padding: 1px 6px; border-radius: 3px; }
    .cmd-table .badge-partial { background: #fef3c7; color: #92400e; font-size: 8pt; font-weight: 700; padding: 1px 6px; border-radius: 3px; }
    .cmd-table .badge-pending { background: #e2e8f0; color: #475569; font-size: 8pt; font-weight: 700; padding: 1px 6px; border-radius: 3px; }

    /* Totals */
    .cmd-totals-wrap { display: flex; justify-content: flex-end; margin-bottom: 14px; position: relative; z-index: 1; }
    .cmd-totals { width: 260px; border: 1px solid #e2e8f0; border-radius: 5px; overflow: hidden; }
    .cmd-t-row { display: flex; justify-content: space-between; padding: 4px 10px; font-size: 9.5pt; }
    .cmd-t-row:nth-child(even) { background: #f8fafc; }
    .cmd-t-row .tl { color: #64748b; }
    .cmd-t-row .tv { font-weight: 600; text-align: right; }
    .cmd-t-row.grand { background: var(--brand-2); color: #fff; padding: 6px 10px; font-size: 10.5pt; font-weight: 700; }
    .cmd-t-row.grand .tl { color: #cbd5e1; }
    .cmd-t-row.grand .tv { color: #fff; }

    /* Footer */
    .cmd-footer { margin-top: auto; padding-top: 10px; border-top: 1.5px solid #e2e8f0; position: relative; z-index: 1; display: flex; justify-content: space-between; gap: 12px; }
    .cmd-footer-col { flex: 1; }
    .cmd-footer-col .fl { font-size: 8pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: var(--brand); margin-bottom: 2px; }
    .cmd-footer-col .ft { font-size: 9pt; color: #64748b; line-height: 1.45; }
    .cmd-footer-col .ft span { display: block; }
    .cmd-legal { margin-top: 8px; padding: 6px 10px; background: #f1f5f9; border-radius: 3px; font-size: 8pt; color: #94a3b8; text-align: center; line-height: 1.4; }
    .cmd-legal b { color: #475569; }

    @media print {
        .no-print { display: none !important; }
        body { background: #fff !important; }
        .print-wrap { padding: 0; }
        .print-card { width: 100%; border-radius: 0; box-shadow: none; padding: 12mm 14mm; border: none; }
        .print-card::before { background: var(--brand) !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .cmd-table thead th { background: var(--brand-2) !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .cmd-t-row.grand { background: var(--brand-2) !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        @page { size: A4 landscape; margin: 10mm; }
    }
    </style>
</head>
<body>

<div class="action-bar no-print">
    <a href="commandes_fournisseur.php" class="btn">
        <i class="bi bi-arrow-left"></i> Retour
    </a>
    <button onclick="window.print()" class="btn btn-primary">
        <i class="bi bi-printer"></i> Imprimer / PDF
    </button>
</div>

<div class="print-wrap">
    <div class="print-card">

        <!-- EN-TÊTE -->
        <div class="cmd-header">
            <div class="cmd-brand">
                <div class="cmd-brand-name"><?= h($nom_boutique) ?></div>
                <div class="cmd-brand-meta">
                    <?php if ($adresse_boutique): ?><span><?= h($adresse_boutique) ?></span><?php endif; ?>
                    <?php if ($localisation): ?><span><?= h($localisation) ?></span><?php endif; ?>
                    <?php if ($telephone): ?><span><b>Tél :</b> <?= h($telephone) ?></span><?php endif; ?>
                    <?php if ($email): ?><span><b>E-mail :</b> <?= h($email) ?></span><?php endif; ?>
                    <?php if ($nif): ?><span><b>NIF :</b> <?= h($nif) ?></span><?php endif; ?>
                    <?php if ($rccm): ?><span><b>RCCM :</b> <?= h($rccm) ?></span><?php endif; ?>
                </div>
            </div>
            <div class="cmd-title-block">
                <div class="cmd-title-label">Commande Fournisseur</div>
                <div class="cmd-num">N° <?= h($cmd['id']) ?></div>
                <div class="cmd-date"><?= h(date_fr($cmd['date_commande'])) ?></div>
                <span class="cmd-badge <?= $badge_class ?>"><?= h($statut_libelles[$cmd['statut']] ?? $cmd['statut']) ?></span>
            </div>
        </div>

        <!-- PARTIES -->
        <div class="cmd-parties">
            <div class="cmd-party">
                <div class="cmd-party-label">Fournisseur</div>
                <div class="cmd-party-name"><?= h($cmd['fournisseur_nom'] ?? '—') ?></div>
                <div class="cmd-party-detail">
                    <?php if (!empty($cmd['fournisseur_telephone'])): ?>
                        <span><b>Tél :</b> <?= h($cmd['fournisseur_telephone']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="cmd-party">
                <div class="cmd-party-label">Magasin de destination</div>
                <div class="cmd-party-name"><?= h($cmd['magasin_nom'] ?? '—') ?></div>
                <div class="cmd-party-detail">
                    <span>Créée par : <b><?= h($cmd['utilisateur_nom'] ?? '—') ?></b></span>
                    <?php if (!empty($cmd['date_reception_prevue'])): ?>
                        <span>Réception prévue : <b><?= h(date_fr($cmd['date_reception_prevue'])) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- NOTES -->
        <?php if (!empty($cmd['notes'])): ?>
        <div class="cmd-notes">
            <b>Notes :</b> <?= h($cmd['notes']) ?>
        </div>
        <?php endif; ?>

        <!-- LIGNES -->
        <div class="cmd-table-wrap">
            <table class="cmd-table">
                <thead>
                    <tr>
                        <th style="width:5%">#</th>
                        <th style="width:30%">Article</th>
                        <th style="width:12%">Code-barres</th>
                        <th class="r" style="width:13%">Prix Achat Unit.</th>
                        <th class="c" style="width:10%">Qté Cmd</th>
                        <th class="c" style="width:10%">Qté Reçue</th>
                        <th class="c" style="width:10%">Reste</th>
                        <th class="r" style="width:13%">Total HT</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $idx = 0; foreach ($lignes as $l):
                        $idx++;
                        $reste = (int)$l['quantite_commandee'] - (int)$l['quantite_recue'];
                        $ltotal = (float)$l['quantite_commandee'] * (float)$l['prix_achat_unitaire'];
                    ?>
                    <tr>
                        <td class="c" style="color:#94a3b8;"><?= $idx ?></td>
                        <td>
                            <div class="a-name"><?= h($l['article_nom']) ?></div>
                        </td>
                        <td><code style="font-size:8.5pt;color:#64748b;"><?= h($l['code_barre']) ?></code></td>
                        <td class="r"><?= h(money((float)$l['prix_achat_unitaire'])) ?></td>
                        <td class="c" style="font-weight:700;"><?= (int)$l['quantite_commandee'] ?></td>
                        <td class="c" style="font-weight:700;color:#16a34a;"><?= (int)$l['quantite_recue'] ?></td>
                        <td class="c">
                            <?php if ($reste > 0): ?>
                                <span class="badge-pending"><?= $reste ?></span>
                            <?php else: ?>
                                <span class="badge-ok"><i class="bi bi-check"></i> OK</span>
                            <?php endif; ?>
                        </td>
                        <td class="r" style="font-weight:600;"><?= h(money($ltotal)) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- TOTAUX -->
        <div class="cmd-totals-wrap">
            <div class="cmd-totals">
                <div class="cmd-t-row">
                    <span class="tl">Total HT</span>
                    <span class="tv"><?= h(money($totalHT)) ?></span>
                </div>
                <div class="cmd-t-row">
                    <span class="tl">Articles commandés</span>
                    <span class="tv"><?= $idx ?> ligne(s)</span>
                </div>
                <div class="cmd-t-row">
                    <span class="tl">Total unités reçues</span>
                    <span class="tv" style="color:#16a34a;"><?= $totalRecu ?></span>
                </div>
                <div class="cmd-t-row">
                    <span class="tl">Total unités restantes</span>
                    <span class="tv" style="color:#eab308;"><?= $totalReste ?></span>
                </div>
                <div class="cmd-t-row grand">
                    <span class="tl">TOTAL HT</span>
                    <span class="tv"><?= h(money($totalHT)) ?></span>
                </div>
            </div>
        </div>

        <!-- PIED DE PAGE -->
        <div class="cmd-footer">
            <div class="cmd-footer-col">
                <div class="fl">Document généré le</div>
                <div class="ft"><?= h(date('d/m/Y à H:i')) ?></div>
            </div>
            <div class="cmd-footer-col" style="text-align:right;">
                <div class="fl">Coordonnées</div>
                <div class="ft">
                    <?php if ($telephone): ?><span>Tél : <?= h($telephone) ?></span><?php endif; ?>
                    <?php if ($email): ?><span>E-mail : <?= h($email) ?></span><?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ($nif || $rccm): ?>
        <div class="cmd-legal">
            <b><?= h($nom_boutique) ?></b>
            — NIF : <?= h($nif) ?> · RCCM : <?= h($rccm) ?>
        </div>
        <?php endif; ?>

    </div>
</div>

</body>
</html>
