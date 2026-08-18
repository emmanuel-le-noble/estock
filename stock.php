<?php
/**
 * stock.php - Consultation simple du stock (accessible au Vendeur).
 * Lecture seule : pas d'édition ni de prix d'achat affiché.
 */
// Guard: compatible avec le routeur front controller (index.php)
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_permission('stock_consulter');

$search = input_string($_GET['q'] ?? '');
$u = user_courant();

// Déterminer le magasin à filtrer : choix de l'utilisateur via paramètre GET, 
// sinon le magasin affecté à son profil utilisateur.
if (peut_administrer()) {
    // L'admin peut filtrer par n'importe quel magasin via GET
    $magasin_id = isset($_GET['magasin_id']) ? (int)$_GET['magasin_id'] : user_magasin_id();
} else {
    // Les autres utilisateurs sont restreints à leur magasin assigné
    $magasin_id = user_magasin_id();
    if ($magasin_id <= 0) {
        flash_error('Aucun magasin affecté à votre compte.');
        redirect('tableau_bord.php');
    }
}

$categorie_id = (int)($_GET['categorie_id'] ?? 0);

// Récupération de la liste complète des magasins et des catégories pour les filtres
$magasins_all = [];
try {
    $stmt_mag = $pdo->query("SELECT id, nom FROM magasins WHERE actif = 1 ORDER BY nom ASC");
    $magasins_all = $stmt_mag->fetchAll();
} catch (\Throwable $e) {
    error_log("Erreur chargement magasins : " . $e->getMessage());
}
$categories_all = db_categories_all($pdo);

// Recherche des articles avec les stocks correspondants (Globaux ou par magasin)
$articles = db_articles_search_stock($pdo, $search, 200, $magasin_id, $categorie_id);

$titre_page = 'Consultation du stock';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <div>
        <h4 class="mb-1"><i class="bi bi-box-seam text-primary"></i> Répertoire des stocks</h4>
        <p class="text-muted mb-0 small">Vision actuelle : <strong><?= $magasin_id > 0 ? 'Filtre Magasin spécifique' : 'Stock Global Multi-Magasins' ?></strong></p>
    </div>

    <div class="d-flex gap-2 align-items-center">
        <a href="<?= h('exports.php?type=articles&search=' . urlencode($search) . '&categorie_id=' . $categorie_id) ?>" class="btn btn-outline-success">
            <i class="bi bi-file-earmark-excel"></i> Export Excel / CSV
        </a>
    </div>
    
    <form method="get" class="d-flex flex-wrap gap-2 align-items-center" role="search">
        <!-- Permettre le choix du magasin si l'utilisateur possède les droits d'administration -->
        <?php if (peut_administrer()): ?>
            <select name="magasin_id" class="form-select w-auto" data-auto-submit>
                <option value="0">Tous les magasins (Global)</option>
                <?php foreach ($magasins_all as $m): ?>
                    <option value="<?= (int)$m['id'] ?>" <?= $magasin_id === (int)$m['id'] ? 'selected' : '' ?>>
                        <?= h($m['nom']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php else: ?>
            <input type="hidden" name="magasin_id" value="<?= $magasin_id ?>">
        <?php endif; ?>

        <select name="categorie_id" class="form-select w-auto" data-auto-submit>
            <option value="0">Toutes les catégories</option>
            <?php foreach ($categories_all as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $categorie_id === (int)$c['id'] ? 'selected' : '' ?>>
                    <?= h($c['nom']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div class="input-group">
            <input type="search" name="q" class="form-control" placeholder="Rechercher un produit..." value="<?= h($search) ?>">
            <button class="btn btn-primary"><i class="bi bi-search"></i></button>
        </div>
    </form>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Nom du produit</th>
                        <th>Catégorie</th>
                        <th>Code-barres</th>
                        <th>Rayon / Emplacement</th>
                        <?php if ($magasin_id === 0): ?>
                            <th>Magasin associé</th>
                        <?php endif; ?>
                        <th class="text-end">Prix de vente</th>
                        <th class="text-center">Stock disponible</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$articles): ?>
                    <?php table_empty_row('Aucun article trouvé pour ces critères de recherche.', $magasin_id === 0 ? 7 : 6); ?>
                <?php else: foreach ($articles as $a):
                    $stock_reel = isset($a['quantite']) ? (int)$a['quantite'] : (int)$a['quantite_stock'];
                    $seuil_alerte = isset($a['stock_alerte']) ? (int)$a['stock_alerte'] : (int)$a['seuil_alerte'];
                    
                    $en_alerte = is_stock_low($stock_reel, $seuil_alerte);
                ?>
                    <tr class="<?= $en_alerte ? 'table-danger' : '' ?>">
                        <td class="fw-semibold">
                            <?= h($a['nom']) ?>
                            <?php if ($en_alerte): ?>
                                <span class="badge bg-danger ms-1" style="font-size: 10px;">Alerte Seuil</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-light text-dark border"><?= h($a['categorie_nom'] ?? 'Sans catégorie') ?></span></td>
                        <td><code><?= h($a['code_barre']) ?></code></td>
                        <td><?= h($a['emplacement'] ?: '—') ?></td>
                        
                        <?php if ($magasin_id === 0): ?>
                            <td class="text-muted small">
                                <i class="bi bi-shop me-1"></i><?= h($a['magasin_nom'] ?? 'Global / Non affecté') ?>
                            </td>
                        <?php endif; ?>
                        
                        <td class="text-end fw-bold text-secondary"><?= money($a['prix_vente']) ?></td>
                        <td class="text-center"><?= stock_badge($stock_reel, $seuil_alerte) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
