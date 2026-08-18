<?php
/**
 * factures.php - Historique des factures (recherche + consultation).
 */
// Guard: compatible avec le routeur front controller (index.php)
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_role(ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_VENDEUR);

$search = input_string($_GET['q'] ?? '');
$statut = $_GET['statut'] ?? '';
$debut  = $_GET['debut'] ?? '';
$fin    = $_GET['fin'] ?? '';

$filters = [
    'search' => $search,
    'statut' => $statut,
    'debut'  => $debut,
    'fin'    => $fin,
];
if (user_role() === ROLE_VENDEUR) {
    $filters['utilisateur_id'] = user_courant()['id'] ?? 0;
}
$search_sql = db_factures_search_sql($filters, user_magasin_id());
$result = paginate($search_sql['sql'], $search_sql['params'], 25);
$factures = $result['items'];

$titre_page = 'Factures';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-end align-items-center mb-3 gap-2">
    <a href="<?= h('caisse.php') ?>" class="btn btn-success"><i class="bi bi-cart-check"></i> Nouvelle vente</a>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small">N° facture / Vendeur</label>
                <input type="text" name="q" class="form-control" value="<?= h($search) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Statut</label>
                <select name="statut" class="form-select">
                    <option value="">Tous</option>
                    <option value="Payee"   <?= $statut==='Payee'?'selected':'' ?>>Payée</option>
                    <option value="Annulee" <?= $statut==='Annulee'?'selected':'' ?>>Annulée</option>
                </select>
            </div>
            <div class="col-md-2"><label class="form-label small">Du</label>
                <input type="date" name="debut" class="form-control" value="<?= h($debut) ?>"></div>
            <div class="col-md-2"><label class="form-label small">Au</label>
                <input type="date" name="fin" class="form-control" value="<?= h($fin) ?>"></div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrer</button>
                <a href="<?= h('factures.php') ?>" class="btn btn-outline-secondary">Réinit.</a>
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
                        <th>N° Facture</th><th>Date</th><th>Vendeur</th>
                        <th class="text-center">Articles</th>
                        <th class="text-end">Total TTC</th>
                        <th class="text-center">Statut</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$factures): ?>
                    <?php table_empty_row('Aucune facture.', 7); ?>
                <?php else: foreach ($factures as $f): ?>
                    <tr>
                        <td class="fw-semibold"><code><?= h($f['numero_facture']) ?></code></td>
                        <td><?= h(date_fr($f['date_facture'])) ?></td>
                        <td><?= h($f['vendeur_nom'] ?: '—') ?></td>
                        <td class="text-center"><?= (int)$f['nb_lignes'] ?></td>
                        <td class="text-end fw-bold"><?= money($f['total_ttc']) ?></td>
                        <td class="text-center">
                            <span class="badge text-bg-<?= $f['statut']==='Payee'?'success':'danger' ?>">
                                <?= h($f['statut']) ?>
                            </span>
                        </td>
                        <td class="text-center text-nowrap">
                            <a href="<?= h(generate_signed_url('facture_view.php', (int)$f['id'])) ?>" class="btn btn-sm btn-outline-primary" title="Consulter / Imprimer">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$pagination_params = build_filter_params([
    'q'      => $search,
    'statut' => $statut,
    'debut'  => $debut,
    'fin'    => $fin,
]);
$base_url = page_url('factures', $pagination_params);
pagination_links($result['page'], $result['total_pages'], $base_url);
?>

<?php include __DIR__ . '/includes/footer.php'; ?>
