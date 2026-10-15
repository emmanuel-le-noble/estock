<?php
/**
 * tableau_bord.php - Tableau de bord + alertes de stock faible.
 */
// Guard: compatible avec le routeur front controller (index.php)
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_connexion();

$u = user_courant();
$magasin_id = user_magasin_id();

// ---- Statistiques rapides (filtrées par magasin pour les non-admins) ----
$nb_articles   = db_articles_count_active($pdo, $magasin_id);
$valeur_stock  = db_articles_stock_value($pdo, $magasin_id);
$nb_ventes_jour = db_factures_count_today($pdo, $magasin_id);
$ca_jour       = db_factures_revenue_today($pdo, $magasin_id);

// ---- Articles en alerte (stock <= seuil) ----
$alertes = db_articles_low_stock($pdo, 50, $magasin_id);

// ---- Derniers mouvements ----
$derniers_mouvements = db_mouvements_recent($pdo, 8, $magasin_id);
$heure_actuelle = (int) date('G');
$salutation = $heure_actuelle < 12 ? 'Bonjour' : ($heure_actuelle < 18 ? 'Bon après-midi' : 'Bonsoir');
$nom_affiche = explode(' ', trim($u['nom'] ?? ''))[0] ?? '';

$titre_page = 'Tableau de bord';
include __DIR__ . '/includes/header.php';
?>

<section class="dashboard-hero" aria-labelledby="dashboardWelcome">
    <div class="dashboard-hero-copy">
        <span class="dashboard-hero-kicker"><i class="bi bi-stars"></i> Votre espace de gestion</span>
        <h2 id="dashboardWelcome"><?= h($salutation) ?><?= $nom_affiche !== '' ? ', ' . h($nom_affiche) : '' ?></h2>
        <p>Voici ce qui se passe dans votre magasin aujourd’hui.</p>
        <div class="dashboard-hero-actions">
            <?php if (peut_facturer()): ?>
                <a class="btn hero-primary-action" href="caisse.php"><i class="bi bi-cart-plus"></i> Nouvelle vente <i class="bi bi-arrow-up-right"></i></a>
            <?php endif; ?>
            <?php if (peut_gerer_stock()): ?>
                <a class="btn hero-secondary-action" href="stock.php"><i class="bi bi-boxes"></i> Consulter le stock</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="dashboard-hero-art" aria-hidden="true">
        <div class="hero-chart-panel">
            <div class="hero-chart-heading"><span>FLUX DU MAGASIN</span><i class="bi bi-activity"></i></div>
            <div class="hero-chart-area">
                <span class="hero-chart-gridline hero-chart-gridline-one"></span>
                <span class="hero-chart-gridline hero-chart-gridline-two"></span>
                <div class="hero-chart-bars">
                    <i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i>
                </div>
                <svg class="hero-chart-trend" viewBox="0 0 240 92" preserveAspectRatio="none">
                    <path d="M2 72 C25 68 30 48 55 53 S88 72 108 48 S139 41 156 47 S190 22 207 31 S227 18 238 12" />
                </svg>
                <span class="hero-chart-point"></span>
            </div>
            <div class="hero-chart-footer"><span><i></i> Activité suivie</span><span>Aujourd’hui</span></div>
        </div>
        <span class="hero-float hero-float-stock"><i class="bi bi-box-seam"></i></span>
        <span class="hero-float hero-float-cash"><i class="bi bi-cash-stack"></i></span>
    </div>
</section>

<div class="dashboard-section-heading">
    <div><span>EN UN COUP D’ŒIL</span><h2>Indicateurs clés</h2></div>
    <span class="dashboard-updated"><i class="bi bi-arrow-repeat"></i> Données du jour</span>
</div>

<!-- Cartes statistiques -->
<div class="row g-3 mb-4 dashboard-metrics">
    <div class="col-6 col-md-3">
        <div class="stat-card is-primary dashboard-metric">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Articles actifs</div>
                    <div class="stat-value"><?= $nb_articles ?></div>
                </div>
                <span class="stat-icon"><i class="bi bi-box-seam"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card is-success dashboard-metric">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Valeur du stock</div>
                    <div class="stat-value"><?= money($valeur_stock) ?></div>
                </div>
                <span class="stat-icon"><i class="bi bi-cash-coin"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card is-info dashboard-metric">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Ventes du jour</div>
                    <div class="stat-value"><?= $nb_ventes_jour ?></div>
                </div>
                <span class="stat-icon"><i class="bi bi-receipt"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card is-warning dashboard-metric">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">CA du jour</div>
                    <div class="stat-value"><?= money($ca_jour) ?></div>
                </div>
                <span class="stat-icon"><i class="bi bi-graph-up-arrow"></i></span>
            </div>
        </div>
    </div>
</div>

<?php $nb_alertes = count($alertes ?? []); ?>
<div class="dashboard-section-heading dashboard-activity-heading">
    <div><span>ACTIVITÉ</span><h2>À suivre</h2></div>
    <span class="dashboard-updated"><?= h(date_fr(date('Y-m-d'))) ?></span>
</div>
<div class="row g-3 dashboard-activity">
    <!-- Colonne alertes -->
    <div class="col-lg-7">
        <div class="card shadow-sm h-100 dashboard-panel">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle text-warning"></i> Alertes de stock</h5>
                <span class="badge <?= $nb_alertes > 0 ? 'text-bg-danger' : 'text-bg-success' ?>"><?= $nb_alertes ?> alerte<?= $nb_alertes > 1 ? 's' : '' ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (!$alertes): ?>
                    <div class="dashboard-empty-state" role="status" data-persistent="true">
                        <span class="dashboard-empty-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></span>
                        <div><strong>Tout est en ordre</strong><p>Aucun article n’est sous son seuil d’alerte.</p></div>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Article</th><th>Code-barres</th>
                                    <th class="text-center">Stock</th><th class="text-center">Seuil</th>
                                    <th>Emplacement</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($alertes as $a): ?>
                                <tr class="<?= $a['quantite_stock'] == 0 ? 'table-danger' : 'table-warning' ?>">
                                    <td><?= h($a['nom']) ?></td>
                                    <td><code><?= h($a['code_barre']) ?></code></td>
                                    <td class="text-center"><span class="badge text-bg-danger"><?= (int)$a['quantite_stock'] ?></span></td>
                                    <td class="text-center"><?= (int)$a['seuil_alerte'] ?></td>
                                    <td><?= h($a['emplacement'] ?: '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Colonne derniers mouvements -->
    <div class="col-lg-5">
        <div class="card shadow-sm h-100 dashboard-panel">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="bi bi-clock-history text-secondary"></i> Derniers mouvements</h5>
            </div>
            <div class="list-group list-group-flush">
                <?php if (!$derniers_mouvements): ?>
                    <div class="list-group-item text-muted">Aucun mouvement pour le moment.</div>
                <?php else: foreach ($derniers_mouvements as $m): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-start">
                        <div class="me-auto">
                            <div class="fw-semibold"><?= h($m['article_nom']) ?></div>
                            <div class="small text-muted">
                                <?= h($m['user_nom'] ?: 'Système') ?> · <?= h(date_fr($m['date_mouvement'])) ?>
                            </div>
                        </div>
                        <span class="badge text-bg-<?= movement_type_color($m['type']) ?> fs-6">
                            <?= h($m['type']) ?> <?= movement_type_sign($m['type']) ?><?= (int)$m['quantite'] ?>
                        </span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
