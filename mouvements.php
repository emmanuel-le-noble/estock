<?php
/**
 * mouvements.php - Historique global et filtrable des mouvements de stock.
 * + saisie d'une entrée / sortie manuelle (Magasinier+).
 *
 * Magasinier : peut saisir des Entrée/Sortie et consulter l'historique.
 */
// Guard: compatible avec le routeur front controller (index.php)
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_role(ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_MAGASINIER);

// ---- Saisie d'un mouvement manuel ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajouter') {
    csrf_guard('mouvements.php');

    $data = extract_post_data([
        'article_id' => ['type' => 'int'],
        'type'       => ['type' => 'string', 'whitelist' => ['Entree', 'Sortie']],
        'quantite'   => ['type' => 'int', 'min' => 1],
        'motif'      => ['type' => 'string', 'trim' => true],
    ], 'mouvements.php');

    if ($data['article_id'] <= 0 || $data['type'] === '' || $data['quantite'] <= 0) {
        flash_error('Données invalides.');
        redirect('mouvements.php');
    }

    try {
        $pdo->beginTransaction();
        $u = user_courant();
        $magasin_id = user_magasin_id();
        $numero_lot = !empty($_POST['numero_lot']) ? trim($_POST['numero_lot']) : null;
        if ($numero_lot !== null && mb_strlen($numero_lot) > 100) {
            $numero_lot = mb_substr($numero_lot, 0, 100);
        }
        $date_peremption = !empty($_POST['date_peremption']) ? $_POST['date_peremption'] : null;
        
        // 1. Enregistrement de l'historique du mouvement (et stock par magasin)
        //    process_stock_movement() synchronise déjà le stock global via
        //    db_stock_magasin_update() : aucun ajustement supplémentaire requis.
        $art = process_stock_movement($pdo, $data['article_id'], $data['type'], $data['quantite'], $data['motif'], $u['id'] ?? 0, $magasin_id, $numero_lot, $date_peremption);

        $pdo->commit();
        suivre_activite('MOUVEMENT_STOCK', $data['type'] . ' de ' . $data['quantite'] . ' unité(s) — ' . $art['nom']);
        flash_success(sprintf('%s de %d unité(s) enregistrée pour « %s ».',
            $data['type'] === 'Entree' ? 'Entrée' : 'Sortie', $data['quantite'], $art['nom']));
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Erreur mouvement: ' . $e->getMessage());
        flash_error('Une erreur est survenue lors de l\'enregistrement du mouvement.');
    }
    redirect('mouvements.php');
}

// ---- Filtres GET ----
$f_article = trim($_GET['article'] ?? '');
$f_type    = $_GET['type'] ?? '';
$f_debut   = $_GET['debut'] ?? '';
$f_fin     = $_GET['fin'] ?? '';
$u = user_courant();
$f_magasin = user_magasin_id();

$search_sql = db_mouvements_search_sql([
    'article' => $f_article,
    'type'    => $f_type,
    'debut'   => $f_debut,
    'fin'     => $f_fin,
    'magasin' => $f_magasin,
]);
$result = paginate($search_sql['sql'], $search_sql['params'], 25);
$mouvements = $result['items'];

// Liste articles pour le select du formulaire
$articles_list = db_articles_list_active($pdo);

$titre_page = 'Mouvements de stock';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-end align-items-center mb-3 gap-2">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalMouvement">
        <i class="bi bi-plus-lg"></i> Nouveau mouvement
    </button>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small">Article / Code-barres</label>
                <input type="text" name="article" class="form-control" value="<?= h($f_article) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Type</label>
                <select name="type" class="form-select">
                    <option value="">Tous</option>
                    <?php foreach (['Entree'=>'Entrée','Sortie'=>'Sortie','Vente'=>'Vente'] as $k=>$lbl): ?>
                        <option value="<?= $k ?>" <?= $f_type===$k?'selected':'' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Du</label>
                <input type="date" name="debut" class="form-control" value="<?= h($f_debut) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Au</label>
                <input type="date" name="fin" class="form-control" value="<?= h($f_fin) ?>">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrer</button>
                <a href="<?= h('mouvements.php') ?>" class="btn btn-outline-secondary">Réinit.</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Date</th><th>Article</th><th>Code-barres</th>
                        <th>Type</th><th class="text-center">Quantité</th>
                        <th>Motif</th><th>Opérateur</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$mouvements): ?>
                    <?php table_empty_row('Aucun mouvement.', 7); ?>
                <?php else: foreach ($mouvements as $m): ?>
                    <tr>
                        <td><?= h(date_fr($m['date_mouvement'])) ?></td>
                        <td class="fw-semibold"><?= h($m['article_nom']) ?></td>
                        <td><code><?= h($m['code_barre']) ?></code></td>
                        <td><span class="badge text-bg-<?= movement_type_color($m['type']) ?>"><?= h($m['type']) ?></span></td>
                        <td class="text-center fw-bold"><?= movement_type_sign($m['type']) ?><?= (int)$m['quantite'] ?></td>
                        <td><?php
                            $motif_affiche = h($m['motif'] ?: '—');
                            if (!empty($m['numero_lot'])) {
                                $motif_affiche .= '<br><small class="text-muted">Lot: ' . h($m['numero_lot']) . '</small>';
                            }
                            if (!empty($m['date_peremption'])) {
                                $motif_affiche .= '<br><small class="text-muted">DLC: ' . h(date_fr($m['date_peremption'])) . '</small>';
                            }
                            echo $motif_affiche;
                        ?></td>
                        <td><?= h($m['user_nom'] ?: 'Système') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$filter_params = build_filter_params([
    'article' => $f_article,
    'type'    => $f_type,
    'debut'   => $f_debut,
    'fin'     => $f_fin,
]);
$base_url = page_url('mouvements', $filter_params);
pagination_links($result['page'], $result['total_pages'], $base_url);
?>

<div class="modal fade" id="modalMouvement" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="ajouter">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-arrow-left-right"></i> Saisir un mouvement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Article *</label>
                    <?= render_dropdown('article_id', $articles_list, null, '— Sélectionner —', 'required', true) ?>
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">Type *</label>
                        <select name="type" id="type_mouvement" class="form-select" required>
                            <option value="Entree">Entrée (+ stock)</option>
                            <option value="Sortie">Sortie (- stock)</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Quantité *</label>
                        <input type="number" name="quantite" min="1" class="form-control" required value="1">
                    </div>
                </div>
                <div class="mt-3">
                    <label class="form-label">Motif</label>
                    <input type="text" name="motif" class="form-control" placeholder="ex : Réappro, casse, inventaire...">
                </div>
                <div class="row g-3 mt-2" id="lotFields" style="display: none;">
                    <div class="col-md-6">
                        <label class="form-label" for="numero_lot">Numéro de lot</label>
                        <input type="text" class="form-control" id="numero_lot" name="numero_lot"
                               placeholder="Ex: LOT-2024-001" maxlength="100">
                        <small class="text-muted">Optionnel — pour la traçabilité</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="date_peremption">Date de péremption (DLC/DDM)</label>
                        <input type="date" class="form-control" id="date_peremption" name="date_peremption">
                        <small class="text-muted">Optionnel — Date limite de consommation</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                <button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button>
            </div>
        </form>
        <script nonce="<?= h(csp_nonce()) ?>">
        document.addEventListener('DOMContentLoaded', function() {
            var typeSelect = document.getElementById('type_mouvement');
            var lotFields = document.getElementById('lotFields');
            if (typeSelect && lotFields) {
                typeSelect.addEventListener('change', function() {
                    var showLot = this.value === 'Entree';
                    lotFields.style.display = showLot ? 'block' : 'none';
                });
            }
        });
        </script>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>