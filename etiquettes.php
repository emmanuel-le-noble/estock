<?php
/**
 * etiquettes.php — Générateur et impression d'étiquettes de rayon.
 * Format A4 grilles (3x8, 4x10) ou étiquettes individuelles avec EAN-13, Prix TTC, Nom shop.
 */
if (!function_exists('est_connecte'))    { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard'))      { require_once __DIR__ . '/includes/helpers.php'; }

exiger_role(ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_MAGASINIER);

$action = $_GET['action'] ?? 'form';

if ($action === 'imprimer') {
    // Récupérer les articles sélectionnés
    $article_ids = $_POST['articles'] ?? $_GET['articles'] ?? [];
    if (is_string($article_ids)) {
        $article_ids = array_filter(array_map('intval', explode(',', $article_ids)));
    }
    if (!is_array($article_ids)) $article_ids = [];

    $quantites = $_POST['quantites'] ?? [];
    $format    = $_GET['format'] ?? $_POST['format'] ?? 'grid_3x8'; // grid_3x8 (24/page), grid_4x10 (40/page), individuel
    $aff_prix  = isset($_POST['afficher_prix']) || isset($_GET['afficher_prix']);
    $aff_shop  = isset($_POST['afficher_shop']) || isset($_GET['afficher_shop']);

    $selection = [];
    if (!empty($article_ids)) {
        $placeholders = implode(',', array_fill(0, count($article_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT a.*, c.nom AS categorie_nom
            FROM articles a
            LEFT JOIN categories c ON c.id = a.categorie_id
            WHERE a.id IN ($placeholders) AND a.actif = 1
            ORDER BY a.nom
        ");
        $stmt->execute(array_values($article_ids));
        $fetched = $stmt->fetchAll();

        foreach ($fetched as $art) {
            $qte = max(1, (int)($quantites[$art['id']] ?? 1));
            for ($i = 0; $i < $qte; $i++) {
                $selection[] = $art;
            }
        }
    }

    echo $twig->render('etiquettes.html.twig', [
        'view'          => 'print',
        'articles'      => $selection,
        'format'        => $format,
        'afficher_prix' => $aff_prix,
        'afficher_shop' => $aff_shop,
        'nom_boutique'  => param_shop_name(),
        'devise'        => param('devise_symbole', 'FCFA'),
    ]);
    exit;
}

// Vue Formulaire de sélection
$search       = $_GET['search'] ?? '';
$categorie_id = (int)($_GET['categorie_id'] ?? 0);
$articles     = db_articles_search_stock($pdo, $search, 500, user_magasin_id(), $categorie_id);
$categories   = function_exists('db_categories_all') ? db_categories_all($pdo) : [];

echo $twig->render('etiquettes.html.twig', [
    'view'       => 'form',
    'articles'   => $articles,
    'categories' => $categories,
    'search'     => $search,
    'cat_id'     => $categorie_id,
    'devise'     => param('devise_symbole', 'FCFA'),
]);
