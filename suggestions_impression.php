<?php
/**
 * suggestions_impression.php - Version épurée et moderne au format A4 pour impression directe.
 */
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_role(ROLE_DIRECTEUR, ROLE_MAGASINIER);

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
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        body {
            background-color: #f3f4f6;
            color: #1f2937;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .page-a4 {
            width: 29.7cm;
            min-height: 21cm;
            padding: 2cm;
            margin: 2rem auto;
            background: #fff;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border-radius: 8px;
        }

        .print-header {
            border-bottom: 2px solid #f3f4f6;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .shop-name {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: -0.03em;
            color: #111827;
        }

        .doc-title {
            font-size: 1.4rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: #dc2626;
        }

        .supplier-section {
            page-break-inside: avoid;
            margin-bottom: 35px;
        }

        .supplier-header {
            background-color: #f9fafb;
            border-left: 4px solid #4f46e5;
            padding: 10px 14px;
            font-weight: 600;
            color: #374151;
            border-radius: 0 6px 6px 0;
        }

        .table {
            margin-top: 10px;
            border-color: #e5e7eb;
        }

        .table th {
            background-color: #f3f4f6 !important;
            color: #4b5563 !important;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.05em;
            padding: 8px 12px;
        }

        .table td {
            padding: 10px 12px;
        }

        .badge-stock {
            padding: 4px 8px;
            font-weight: 600;
            border-radius: 4px;
            font-size: 11px;
        }

        .badge-danger-soft { background-color: #ffeeef; color: #dc2626; }
        .badge-warning-soft { background-color: #fffbeb; color: #d97706; }

        @media print {
            body { background: #fff; margin: 0; padding: 0; }
            .page-a4 { width: auto; min-height: auto; padding: 0; margin: 0; box-shadow: none; border-radius: 0; }
            .no-print { display: none !important; }
            .badge-danger-soft { border: 1px solid #dc2626 !important; color: #dc2626 !important; background: transparent !important; }
            .badge-warning-soft { border: 1px solid #d97706 !important; color: #d97706 !important; background: transparent !important; }
        }
    </style>
</head>
<body>

<div class="page-a4">
    <!-- Barre d'action supérieure (masquée à l'impression) -->
    <div class="no-print d-flex justify-content-between align-items-center alert alert-white border shadow-sm p-3 mb-5 rounded-3">
        <div class="d-flex align-items-center gap-2 text-secondary">
            <i class="bi bi-file-earmark-pdf fs-4 text-danger"></i>
            <div>
                <strong class="text-dark">Aperçu avant impression</strong><br>
                <small>Format standard A4 optimisé.</small>
            </div>
        </div>
        <div class="d-flex gap-2">
            <button id="btnClose" class="btn btn-light btn-sm fw-medium border">Fermer</button>
            <button id="btnPrint" class="btn btn-dark btn-sm fw-medium d-flex align-items-center gap-2">
                <i class="bi bi-printer"></i> Imprimer le document
            </button>
        </div>
    </div>

    <!-- En-tête de la facture / bon -->
    <div class="print-header row align-items-center">
        <div class="col-7">
            <div class="shop-name text-uppercase"><?= h(param_shop_name()) ?></div>
            <div class="text-muted mt-1 small">
                <?php if (param('adresse_boutique', '')): ?><i class="bi bi-geo-alt"></i> <?= h(param('adresse_boutique')) ?><br><?php endif; ?>
                <?php if (param('telephone_boutique', '')): ?><i class="bi bi-telephone"></i> <?= h(param('telephone_boutique')) ?><?php endif; ?>
            </div>
        </div>
        <div class="col-5 text-end">
            <div class="doc-title text-uppercase mb-1">Suggestions d'Achat</div>
            <div class="text-muted small">
                <strong>Date :</strong> <?= date('d/m/Y') ?><br>
                <strong>Heure :</strong> <?= date('H:i') ?>
            </div>
        </div>
    </div>

    <!-- Résumé global -->
    <div class="alert alert-light border-0 bg-light p-3 rounded-3 d-flex justify-content-between align-items-center mb-4">
        <span class="fw-medium text-secondary">Statut des alertes de l'établissement :</span>
        <span class="badge bg-danger px-2 py-1 fs-6 fw-bold"><?= $nb_total ?> article<?= $nb_total > 1 ? 's' : '' ?> à commander</span>
    </div>

    <?php if ($nb_total === 0): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-check-circle display-4 text-success d-block mb-3"></i>
            <p class="fs-5 m-0">Aucun article n'a atteint son seuil d'alerte.</p>
        </div>
    <?php else: ?>
        <?php foreach ($groupes as $g): ?>
            <?php $nb_refs = count($g['articles'] ?? []); ?>
            <div class="supplier-section">
                <!-- Bloc Fournisseur -->
                <div class="supplier-header d-flex justify-content-between align-items-center">
                    <span>
                        <i class="bi bi-truck me-2 text-primary"></i>
                        <?= h($g['fournisseur_nom'] ?? 'Sans fournisseur désigné') ?>
                    </span>
                    <span class="text-muted small fw-normal">
                        <?= $nb_refs ?> référence<?= $nb_refs > 1 ? 's' : '' ?>
                    </span>
                </div>

                <!-- Coordonnées Fournisseur -->
                <?php if (!empty($g['contact']) || !empty($g['telephone'])): ?>
                    <div class="my-2 text-muted px-2 small">
                        <?php if (!empty($g['contact'])): ?>
                            <span class="me-3"><i class="bi bi-person me-1"></i> Contact : <strong><?= h($g['contact']) ?></strong></span>
                        <?php endif; ?>
                        <?php if (!empty($g['telephone'])): ?>
                            <span><i class="bi bi-telephone me-1"></i> Tél : <strong><?= h($g['telephone']) ?></strong></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Grille des articles -->
                <table class="table table-bordered align-middle">
                    <thead>
                        <tr>
                            <th>Désignation Article</th>
                            <th style="width: 130px;">Code-barres</th>
                            <th style="width: 120px;">Emplacement</th>
                            <th class="text-center" style="width: 100px;">Stock Actuel</th>
                            <th class="text-center" style="width: 100px;">Seuil Alerte</th>
                            <th class="text-end" style="width: 120px;">Prix Vente</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (($g['articles'] ?? []) as $a): ?>
                            <?php $qte = (int)($a['quantite_stock'] ?? 0); ?>
                            <tr>
                                <td>
                                    <span class="fw-semibold text-dark"><?= h($a['nom'] ?? '') ?></span>
                                </td>
                                <td><code class="text-secondary"><?= h($a['code_barre'] ?? '—') ?></code></td>
                                <td class="text-muted"><?= h($a['emplacement'] ?? '—') ?></td>
                                <td class="text-center">
                                    <span class="badge-stock <?= $qte === 0 ? 'badge-danger-soft' : 'badge-warning-soft' ?>">
                                        <?= $qte === 0 ? 'Rupture' : $qte . ' unité' . ($qte > 1 ? 's' : '') ?>
                                    </span>
                                </td>
                                <td class="text-center text-secondary fw-medium"><?= h($a['seuil_alerte'] ?? '—') ?></td>
                                <td class="text-end fw-medium"><?= money($a['prix_vente'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script nonce="<?= h(csp_nonce()) ?>">
    (function() {
        var btnPrint = document.getElementById('btnPrint');
        var btnClose = document.getElementById('btnClose');
        if (btnPrint) btnPrint.addEventListener('click', function () { window.print(); });
        if (btnClose) btnClose.addEventListener('click', function () {
            window.close();
            // Fallback si la fenêtre n'a pas été ouverte via window.open()
            setTimeout(function () {
                if (!window.closed) window.history.back();
            }, 150);
        });
    })();
</script>

</body>
</html>