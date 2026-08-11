<?php
/**
 * categories.php - CRUD des catégories d'articles.
 *
 * Permet aux Directeurs et Administrateurs de créer, modifier et désactiver les catégories.
 */
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';

exiger_permission('articles_gerer');

$action = $_POST['action'] ?? $_GET['action'] ?? 'liste';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard('categories.php');

    if ($action === 'ajouter') {
        $data = extract_post_data([
            'nom'         => ['type' => 'string', 'required' => true, 'max' => 100, 'redirect' => 'categories.php'],
            'description' => ['type' => 'string', 'required' => false, 'redirect' => 'categories.php'],
        ], 'categories.php');

        $id = db_categorie_insert($pdo, [
            'nom'         => $data['nom'],
            'description' => $data['description'] ?? null,
            'actif'       => 1
        ]);

        suivre_activite('CATEGORIE_CREEE', "Création de la catégorie « {$data['nom']} » (#{$id})");
        flash_success("Catégorie « {$data['nom']} » créée avec succès.");
        redirect('categories.php');
    }

    if ($action === 'editer') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_error('Catégorie invalide.');
            redirect('categories.php');
        }

        $data = extract_post_data([
            'nom'         => ['type' => 'string', 'required' => true, 'max' => 100, 'redirect' => 'categories.php'],
            'description' => ['type' => 'string', 'required' => false, 'redirect' => 'categories.php'],
            'actif'       => ['type' => 'int', 'required' => false, 'redirect' => 'categories.php'],
        ], 'categories.php');

        db_categorie_update($pdo, $id, [
            'nom'         => $data['nom'],
            'description' => $data['description'] ?? null,
            'actif'       => isset($_POST['actif']) ? 1 : 0
        ]);

        suivre_activite('CATEGORIE_MODIFIEE', "Modification de la catégorie #{$id}");
        flash_success("Catégorie mise à jour avec succès.");
        redirect('categories.php');
    }

    if ($action === 'desactiver') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            db_categorie_deactivate($pdo, $id);
            suivre_activite('CATEGORIE_DESACTIVEE', "Désactivation de la catégorie #{$id}");
            flash_success("Catégorie désactivée.");
        }
        redirect('categories.php');
    }
}

$categories = db_categories_all($pdo, false);

echo $twig->render('categories.html.twig', [
    'titre_page'    => "Catégories d'articles",
    'page_courante' => 'categories',
    'categories'    => $categories
]);
