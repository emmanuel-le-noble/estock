<?php
/**
 * suggestions_impression.php - Version épurée et moderne au format A4 pour impression directe.
 */
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_role(ROLE_DIRECTEUR, ROLE_MAGASINIER);

require_once __DIR__ . '/includes/letterhead.php';

$u = user_courant();
$magasin_id = user_magasin_id();
$articles = db_articles_low_stock_with_supplier($pdo, 100, $magasin_id);
$articles = is_array($articles) ? $articles : [];
$groupes  = generate_restock_list_by_supplier($articles);
$groupes  = is_array($groupes) ? $groupes : [];
$nb_total = count($articles);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Suggestions d'Achat — <?= h(param_shop_name()) ?></title>
    <link rel="icon" type="image/x-icon" href="assets/images/logo-eStock.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/print-documents.css">
    <style>
        body {
            background-color: #f1f5f9;
            color: #1a1a1a;
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .page-a4 {
            width: 29.7cm;
            min-height: 21cm;
            margin: 2rem auto;
            background: #fff;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border-radius: 8px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .page-content {
            padding: 0 2cm;
            flex: 1;
            position: relative;
            z-index: 2;
        }

        .supplier-section {
            page-break-inside: avoid;
            margin-bottom: 28px;
        }

        .supplier-header {
            background-color: #f5f5f5;
            border-left: 4px solid var(--doc-green, #8DC63F);
            padding: 10px 14px;
            font-weight: 600;
            color: #1a1a1a;
            border-radius: 0 6px 6px 0;
            margin-bottom: 8px;
        }

        .supplier-contact {
            font-size: 11px;
            color: #666;
            margin-bottom: 8px;
            padding-left: 14px;
        }

        .doc-table th {
            background-color: var(--doc-red, #D52B1E) !important;
            color: #fff !important;
        }

        .badge-stock {
            padding: 4px 8px;
            font-weight: 600;
            border-radius: 4px;
            font-size: 11px;
        }

        .badge-danger-soft { background-color: #fee2e2; color: #991b1b; }
        .badge-warning-soft { background-color: #fef3c7; color: #92400e; }

        /* Summary bar */
        .summary-bar {
            background: #f5f5f5;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .summary-bar .label { color: #666; font-weight: 500; }
        .summary-bar .badge { background: var(--doc-red, #D52B1E); color: #fff; padding: 4px 12px; border-radius: 4px; font-weight: 700; font-size: 14px; }

        .empty-state {
            text-align: center;
            padding: 40px 0;
            color: #999;
        }
        .empty-state .icon { font-size: 48px; color: var(--doc-green, #8DC63F); margin-bottom: 12px; }

        @media print {
            body { background: #fff; margin: 0; padding: 0; }
            .page-a4 { width: auto; min-height: auto; margin: 0; box-shadow: none; border-radius: 0; }
            .no-print { display: none !important; }
            .doc-table thead th { background: var(--doc-red) !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .badge-danger-soft { border: 1px solid #991b1b !important; color: #991b1b !important; background: transparent !important; }
            .badge-warning-soft { border: 1px solid #92400e !important; color: #92400e !important; background: transparent !important; }
        }
    </style>
</head>
<body>

<div class="page-a4">

    <!-- EN-TÊTE (logo + nom entreprise) -->
    <?= letterhead_header() ?>

    <!-- BANDEAU CHEVRON VERT -->
    <?= letterhead_chevron_top() ?>

    <!-- TITRE DU DOCUMENT -->
    <?= letterhead_doc_title(
        'Suggestions d\'Achat',
        date('d/m/Y'),
        date('H:i'),
        '',
        ''
    ) ?>

    <div class="page-content">

        <!-- Résumé -->
        <div class="summary-bar">
            <span class="label">Statut des alertes de l'établissement :</span>
            <span class="badge"><?= $nb_total ?> article<?= $nb_total > 1 ? 's' : '' ?> à commander</span>
        </div>

        <?php if ($nb_total === 0): ?>
            <div class="empty-state">
                <div class="icon"><i class="bi bi-check-circle"></i></div>
                <p style="font-size:16px;margin:0;">Aucun article n'a atteint son seuil d'alerte.</p>
            </div>
        <?php else: ?>
            <?php foreach ($groupes as $g): ?>
                <?php $nb_refs = count($g['articles'] ?? []); ?>
                <div class="supplier-section">
                    <!-- Bloc Fournisseur -->
                    <div class="supplier-header d-flex justify-content-between align-items-center">
                        <span>
                            <i class="bi bi-truck me-2" style="color:var(--doc-green);"></i>
                            <?= h($g['fournisseur_nom'] ?? 'Sans fournisseur désigné') ?>
                        </span>
                        <span style="color:#666;font-size:11px;font-weight:400;">
                            <?= $nb_refs ?> référence<?= $nb_refs > 1 ? 's' : '' ?>
                        </span>
                    </div>

                    <!-- Coordonnées Fournisseur -->
                    <?php if (!empty($g['contact']) || !empty($g['telephone'])): ?>
                        <div class="supplier-contact">
                            <?php if (!empty($g['contact'])): ?>
                                <span class="me-3"><i class="bi bi-person me-1"></i> Contact : <strong><?= h($g['contact']) ?></strong></span>
                            <?php endif; ?>
                            <?php if (!empty($g['telephone'])): ?>
                                <span><i class="bi bi-telephone me-1"></i> Tél : <strong><?= h($g['telephone']) ?></strong></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Grille des articles -->
                    <table class="doc-table" style="border-color:#e5e7eb;">
                        <thead>
                            <tr>
                                <th>Désignation Article</th>
                                <th style="width: 130px;">Code-barres</th>
                                <th style="width: 120px;">Emplacement</th>
                                <th class="c" style="width: 100px;">Stock Actuel</th>
                                <th class="c" style="width: 100px;">Seuil Alerte</th>
                                <th class="r" style="width: 120px;">Prix Vente</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (($g['articles'] ?? []) as $a): ?>
                                <?php $qte = (int)($a['quantite_stock'] ?? 0); ?>
                                <tr>
                                    <td>
                                        <span class="a-name"><?= h($a['nom'] ?? '') ?></span>
                                    </td>
                                    <td><code style="font-size:8.5pt;color:#666;"><?= h($a['code_barre'] ?? '—') ?></code></td>
                                    <td style="color:#666;"><?= h($a['emplacement'] ?? '—') ?></td>
                                    <td class="c">
                                        <span class="badge-stock <?= $qte === 0 ? 'badge-danger-soft' : 'badge-warning-soft' ?>">
                                            <?= $qte === 0 ? 'Rupture' : $qte . ' unité' . ($qte > 1 ? 's' : '') ?>
                                        </span>
                                    </td>
                                    <td class="c" style="color:#666;font-weight:500;"><?= h($a['seuil_alerte'] ?? '—') ?></td>
                                    <td class="r" style="font-weight:500;"><?= money($a['prix_vente'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div><!-- /.page-content -->

    <!-- PIED DE PAGE -->
    <?= letterhead_footer() ?>

    <!-- BANDEAU CHEVRON ROUGE (BAS) -->
    <?= letterhead_chevron_bottom() ?>

</div><!-- /.page-a4 -->

<script nonce="<?= h(csp_nonce()) ?>">
    (function() {
        var btnPrint = document.getElementById('btnPrint');
        var btnClose = document.getElementById('btnClose');
        if (btnPrint) btnPrint.addEventListener('click', function () { window.print(); });
        if (btnClose) btnClose.addEventListener('click', function () {
            window.close();
            setTimeout(function () {
                if (!window.closed) window.history.back();
            }, 150);
        });
    })();
</script>

</body>
</html>