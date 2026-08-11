<?php
/**
 * promotions.php — CRUD des promotions et codes promo.
 * Actions : liste (défaut) | enregistrer (POST) | desactiver (POST)
 */
if (!function_exists('est_connecte'))    { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard'))      { require_once __DIR__ . '/includes/helpers.php'; }

exiger_permission('promotions_consulter');

$action = $_GET['action'] ?? 'liste';

// ---- Traitement POST : enregistrer ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'enregistrer') {
    csrf_guard('promotions.php');
    exiger_permission('promotions_gerer');

    $data = extract_post_data([
        'id'                  => ['type' => 'int', 'default' => 0],
        'nom'                 => ['type' => 'string', 'trim' => true, 'required' => true, 'max' => 150, 'redirect' => 'promotions.php'],
        'code_promo'          => ['type' => 'string', 'trim' => true, 'nullable' => true, 'max' => 40, 'redirect' => 'promotions.php'],
        'type_reduction'      => ['type' => 'string', 'default' => 'pourcentage'],
        'valeur'              => ['type' => 'float', 'min' => 0],
        'article_id'          => ['type' => 'int', 'default' => 0],
        'categorie_id'        => ['type' => 'int', 'default' => 0],
        'montant_min_achat'   => ['type' => 'float', 'min' => 0, 'default' => 0],
        'date_debut'          => ['type' => 'string', 'nullable' => true],
        'date_fin'            => ['type' => 'string', 'nullable' => true],
        'limite_utilisations' => ['type' => 'int', 'nullable' => true],
        'actif'               => ['type' => 'int', 'default' => 1],
    ], 'promotions.php');

    $id = $data['id'];
    db_transaction(
        function(PDO $pdo) use ($data, $id) {
            if ($id > 0) {
                db_promotion_update($pdo, $id, $data);
            } else {
                db_promotion_insert($pdo, $data);
            }
        },
        'Promotion enregistrée avec succès.',
        'Erreur lors de l\'enregistrement.',
        'promotions.php'
    );

    suivre_activite('PROMOTION_ENREGISTREMENT', ($id > 0 ? 'Modification' : 'Création') . ' promotion : ' . $data['nom']);
    flash_success('Promotion enregistrée.');
    redirect('promotions.php');
}

// ---- Traitement POST : désactiver ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'desactiver') {
    csrf_guard('promotions.php');
    exiger_permission('promotions_gerer');
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        db_promotion_deactivate($pdo, $id);
        suivre_activite('PROMOTION_DESACTIVATION', 'Désactivation promotion #' . $id);
        flash_success('Promotion désactivée.');
    }
    redirect('promotions.php');
}

// ---- Liste ----
$filters = [
    'search' => $_GET['search'] ?? '',
    'actif'  => $_GET['actif'] ?? '',
];
$built = db_promotions_search_sql($filters);
$page  = max(1, (int)($_GET['page'] ?? 1));
$pagination = paginate($pdo, $built['sql'], $built['params'], $page, 15);

$articles   = db_articles_list_active($pdo, 1000);
$categories = function_exists('db_categories_all') ? db_categories_all($pdo) : [];

echo $twig->render('promotions.html.twig', [
    'rows'        => $pagination['data'],
    'pagination'  => $pagination,
    'filters'     => $filters,
    'articles'    => $articles,
    'categories'  => $categories,
    'devise'      => param('devise_symbole', 'FCFA'),
    'peut_gerer'  => peut('promotions_gerer'),
]);
