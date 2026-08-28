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
require_once __DIR__ . '/includes/letterhead.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commande Fournisseur #<?= $cmdId ?> — Impression</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/print-documents.css">
    <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Poppins', sans-serif; font-size: 8pt; color: #1a1a1a; background: #f1f5f9; }

    .print-wrap { display: flex; justify-content: center; padding: 24px 16px 40px; }
    .print-card {
        width: 210mm; max-width: 100%; background: #fff; border-radius: 10px;
        box-shadow: 0 8px 32px rgba(0,0,0,.10);
        padding: 0 0 12mm;
        position: relative; overflow: hidden; line-height: 1.3;
        display: flex; flex-direction: column;
    }

    .cmd-content { padding: 0 16mm; flex: 1; position: relative; z-index: 2; }

    /* Notes */
    .cmd-notes { background: #fffbeb; border: 1px solid #fde68a; border-radius: 5px; padding: 6px 10px; margin-bottom: 10px; font-size: 7pt; color: #92400e; position: relative; z-index: 1; }

    @media print {
        .no-print { display: none !important; }
        body { background: #fff !important; }
        .print-wrap { padding: 0; }
        .print-card { width: 100%; border-radius: 0; box-shadow: none; padding: 0; border: none; }
        .cmd-table thead th { background: var(--doc-red) !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .doc-t-row.grand { background: var(--doc-red) !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        @page { size: A4 landscape; margin: 8mm; }
    }
    </style>
</head>
<body>

<div class="doc-action-bar no-print">
    <a href="commandes_fournisseur.php" class="btn">
        <i class="bi bi-arrow-left"></i> Retour
    </a>
    <button id="btnPrintCmd" class="btn btn-primary">
        <i class="bi bi-printer"></i> Imprimer / PDF
    </button>
</div>

<div class="print-wrap">
    <div class="print-card">

        <!-- EN-TÊTE (logo + nom entreprise) -->
        <?= letterhead_header() ?>

        <!-- BANDEAU CHEVRON VERT -->
        <?= letterhead_chevron_top() ?>

        <!-- TITRE DU DOCUMENT -->
        <?= letterhead_doc_title(
            'Commande Fournisseur',
            h($cmd['id']),
            h(date_fr($cmd['date_commande'])),
            $badge_class,
            h($statut_libelles[$cmd['statut']] ?? $cmd['statut'])
        ) ?>

        <div class="cmd-content">

            <!-- PARTIES -->
            <div class="doc-parties">
                <div class="doc-party">
                    <div class="doc-party-label">Fournisseur</div>
                    <div class="doc-party-name"><?= h($cmd['fournisseur_nom'] ?? '—') ?></div>
                    <div class="doc-party-detail">
                        <?php if (!empty($cmd['fournisseur_telephone'])): ?>
                            <span><b>Tél :</b> <?= h($cmd['fournisseur_telephone']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="doc-party">
                    <div class="doc-party-label">Magasin de destination</div>
                    <div class="doc-party-name"><?= h($cmd['magasin_nom'] ?? '—') ?></div>
                    <div class="doc-party-detail">
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
            <div class="doc-table-wrap">
                <table class="doc-table">
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
                            <td><code style="font-size:7.5pt;color:#666;"><?= h($l['code_barre']) ?></code></td>
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
            <div class="doc-totals-wrap">
                <div class="doc-totals" style="width:260px;">
                    <div class="doc-t-row">
                        <span class="tl">Total HT</span>
                        <span class="tv"><?= h(money($totalHT)) ?></span>
                    </div>
                    <div class="doc-t-row">
                        <span class="tl">Articles commandés</span>
                        <span class="tv"><?= $idx ?> ligne(s)</span>
                    </div>
                    <div class="doc-t-row">
                        <span class="tl">Total unités reçues</span>
                        <span class="tv" style="color:#16a34a;"><?= $totalRecu ?></span>
                    </div>
                    <div class="doc-t-row">
                        <span class="tl">Total unités restantes</span>
                        <span class="tv" style="color:#eab308;"><?= $totalReste ?></span>
                    </div>
                    <div class="doc-t-row grand">
                        <span class="tl">TOTAL HT</span>
                        <span class="tv"><?= h(money($totalHT)) ?></span>
                    </div>
                </div>
            </div>

            <!-- Document info -->
            <div style="font-size:7pt;color:#94a3b8;text-align:right;margin-bottom:8px;">
                Document généré le <?= h(date('d/m/Y à H:i')) ?>
            </div>

        </div><!-- /.cmd-content -->

        <!-- PIED DE PAGE -->
        <?= letterhead_footer() ?>

        <!-- BANDEAU CHEVRON ROUGE (BAS) -->
        <?= letterhead_chevron_bottom() ?>

    </div>
</div>

<script nonce="<?= h(csp_nonce()) ?>">
document.getElementById('btnPrintCmd').addEventListener('click', function() {
    window.print();
});
</script>

</body>
</html>
