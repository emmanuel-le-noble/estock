<?php
/**
 * statistiques.php - Tableau de bord analytique avec graphiques.
 *
 * Accessible uniquement aux Directeurs et Administrateurs.
 * Affiche le CA, le nombre de ventes, le top articles et l'évolution journalière.
 */
// Guard: compatible avec le routeur front controller (index.php)
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_role(ROLE_DIRECTEUR, ROLE_ADMIN);

// ---- Filtres de date (défaut : mois en cours) ----
$debut = $_GET['date_debut'] ?? '';
$fin   = $_GET['date_fin'] ?? '';

// Validation défensive des dates
if ($debut === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut)) {
    $debut = date('Y-m-01');
}
if ($fin === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin)) {
    $fin = date('Y-m-t');
}
// S'assurer que debut <= fin
if ($debut > $fin) {
    [$debut, $fin] = [$fin, $debut];
}

// ---- Récupération des données ----
$stats      = db_stats_period($pdo, $debut, $fin);
$top        = db_stats_top_articles($pdo, $debut, $fin, 5);
$daily      = db_stats_daily_sales($pdo, $debut, $fin);
$nb_ventes  = (int)($stats['nb_ventes'] ?? 0);
$ca_total   = (float)($stats['ca_total'] ?? 0);
$panier_moyen = $nb_ventes > 0 ? $ca_total / $nb_ventes : 0;

// ---- Données comptables : Dépenses & Bénéfice Net ----
$benefice   = db_stats_benefice_net($pdo, $debut, $fin);
$camv       = $benefice['camv'];
$total_dep  = $benefice['depenses'];
$benefice_n = $benefice['benefice'];
$marge_brute = $benefice['marge_brute'];
$dep_daily  = db_depenses_daily($pdo, $debut, $fin);
$dep_cats   = db_depenses_by_categorie($pdo, $debut, $fin);

$titre_page = 'Statistiques';
include __DIR__ . '/includes/header.php';
?>

<!-- Formulaire de filtre -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small">Date début</label>
                <input type="date" name="date_debut" class="form-control" value="<?= h($debut) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small">Date fin</label>
                <input type="date" name="date_fin" class="form-control" value="<?= h($fin) ?>">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrer</button>
                <a href="statistiques.php" class="btn btn-outline-secondary">Mois en cours</a>
            </div>
        </form>
    </div>
</div>

<!-- Cartes statistiques -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card is-primary">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Chiffre d'affaires</div>
                    <div class="stat-value"><?= money($ca_total) ?></div>
                </div>
                <span class="stat-icon"><i class="bi bi-cash-coin"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card is-info">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Nombre de ventes</div>
                    <div class="stat-value"><?= $nb_ventes ?></div>
                </div>
                <span class="stat-icon"><i class="bi bi-receipt"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card is-success">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Panier moyen</div>
                    <div class="stat-value"><?= money($panier_moyen) ?></div>
                </div>
                <span class="stat-icon"><i class="bi bi-cart3"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card is-warning">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Top article</div>
                    <div class="stat-value text-truncate" style="max-width:140px" title="<?= h(($top[0]['nom'] ?? '—')) ?>">
                        <?= h(mb_strimwidth($top[0]['nom'] ?? '—', 0, 18, '…')) ?>
                    </div>
                </div>
                <span class="stat-icon"><i class="bi bi-trophy"></i></span>
            </div>
        </div>
    </div>
</div>

<!-- Cartes comptables : Marge brute, Dépenses, Bénéfice Net -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="stat-card is-info">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Coût d'achat (CAMV)</div>
                    <div class="stat-value"><?= money($camv) ?></div>
                </div>
                <span class="stat-icon"><i class="bi bi-cart-dash"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card is-danger">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Total Dépenses</div>
                    <div class="stat-value"><?= money($total_dep) ?></div>
                </div>
                <span class="stat-icon"><i class="bi bi-wallet2"></i></span>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="stat-card <?= $benefice_n >= 0 ? 'is-success' : 'is-danger' ?>">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Bénéfice Net</div>
                    <div class="stat-value"><?= money($benefice_n) ?></div>
                </div>
                <span class="stat-icon"><i class="bi bi-graph-up-arrow"></i></span>
            </div>
        </div>
    </div>
</div>

<!-- Résumé P&L -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="bi bi-calculator text-primary"></i> Compte de résultat — Période</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered mb-0 align-middle" style="max-width:600px">
                <tbody>
                    <tr>
                        <td class="fw-semibold">Chiffre d'affaires (Ventes)</td>
                        <td class="text-end fw-bold"><?= money($ca_total) ?></td>
                    </tr>
                    <tr class="table-light">
                        <td class="ps-4">− Coût d'achat marchandises vendues</td>
                        <td class="text-end text-muted">− <?= money($camv) ?></td>
                    </tr>
                    <tr class="table-light">
                        <td class="ps-4 fw-semibold">= Marge brute</td>
                        <td class="text-end fw-bold"><?= money($marge_brute) ?></td>
                    </tr>
                    <tr>
                        <td class="ps-4">− Dépenses d'exploitation</td>
                        <td class="text-end text-muted">− <?= money($total_dep) ?></td>
                    </tr>
                    <tr class="<?= $benefice_n >= 0 ? 'table-success' : 'table-danger' ?>">
                        <td class="fw-bold fs-6">= Bénéfice net</td>
                        <td class="text-end fw-bold fs-6"><?= money($benefice_n) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Graphiques -->
<div class="row g-3 mb-4">
    <!-- Courbe : évolution journalière CA vs Dépenses -->
    <div class="col-lg-8">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="bi bi-graph-up text-primary"></i> Évolution journalière : CA vs Dépenses</h5>
            </div>
            <div class="card-body">
                <?php if (empty($daily) && empty($dep_daily)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-bar-chart fs-1 d-block mb-2"></i>
                        Aucune donnée sur cette période.
                    </div>
                <?php else: ?>
                    <canvas id="dailyChart" height="260"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Barres : top articles -->
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="bi bi-bar-chart-line text-success"></i> Top 5 articles</h5>
            </div>
            <div class="card-body">
                <?php if (empty($top)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-trophy fs-1 d-block mb-2"></i>
                        Aucune donnée sur cette période.
                    </div>
                <?php else: ?>
                    <canvas id="topChart" height="260"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Tableau détaillé top articles -->
<?php if (!empty($top)): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="bi bi-list-ol text-secondary"></i> Détail du top articles</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Article</th>
                        <th>Code-barres</th>
                        <th class="text-center">Quantité vendue</th>
                        <th class="text-end">Montant total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top as $i => $a): ?>
                        <tr>
                            <td><span class="badge text-bg-primary"><?= $i + 1 ?></span></td>
                            <td class="fw-semibold"><?= h($a['nom'] ?? '') ?></td>
                            <td><code><?= h($a['code_barre'] ?? '') ?></code></td>
                            <td class="text-center"><?= (int)($a['total_qte'] ?? 0) ?></td>
                            <td class="text-end fw-bold"><?= money((float)($a['total_montant'] ?? 0)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Chart.js CDN + initialisation -->
<script nonce="<?= h(csp_nonce()) ?>" src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script nonce="<?= h(csp_nonce()) ?>">
(function () {
    <?php if (!empty($daily) || !empty($dep_daily)): ?>
    // Fusionner les dates CA + Dépenses pour un axe X commun
    const caMap = <?= json_encode(array_combine(
        array_map(fn($d) => $d['jour'] ?? '', $daily),
        array_map('floatval', array_column($daily, 'ca_jour'))
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const depMap = <?= json_encode(array_combine(
        array_map(fn($d) => $d['jour'] ?? '', $dep_daily),
        array_map('floatval', array_column($dep_daily, 'total'))
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const allDates = [...new Set([...Object.keys(caMap), ...Object.keys(depMap)])].sort();
    const labels = allDates.map(d => { const p = d.split('-'); return p[2] + '/' + p[1]; });

    const ctx1 = document.getElementById('dailyChart');
    if (ctx1) {
        new Chart(ctx1.getContext('2d'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'CA journalier',
                        data: allDates.map(d => caMap[d] || 0),
                        borderColor: '#6366f1',
                        backgroundColor: 'rgba(99,102,241,0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 4,
                        pointBackgroundColor: '#6366f1'
                    },
                    {
                        label: 'Dépenses',
                        data: allDates.map(d => depMap[d] || 0),
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239,68,68,0.08)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 4,
                        pointBackgroundColor: '#ef4444',
                        borderDash: [6, 3]
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', labels: { usePointStyle: true, padding: 16 } },
                    tooltip: { callbacks: { label: function(c) { return c.dataset.label + ' : ' + c.parsed.y.toLocaleString('fr-FR') + ' FCFA'; } } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: function(v) { return v.toLocaleString('fr-FR'); } } }
                }
            }
        });
    }
    <?php endif; ?>

    <?php if (!empty($top)): ?>
    const ctx2 = document.getElementById('topChart');
    if (ctx2) {
        new Chart(ctx2.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(fn($a) => mb_strimwidth($a['nom'] ?? '', 0, 15, '…'), $top), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                datasets: [{
                    label: 'Quantité vendue',
                    data: <?= json_encode(array_map('intval', array_column($top, 'total_qte')), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                    backgroundColor: ['#6366f1','#10b981','#f59e0b','#ef4444','#0ea5e9'],
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    }
    <?php endif; ?>
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
