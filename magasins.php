<?php
/**
 * magasins.php - Gestion des magasins (CRUD).
 *
 * Directeur / Admin : peut créer, activer/désactiver les magasins.
 */
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_role(ROLE_DIRECTEUR, ROLE_ADMIN);

// ---- Traitement formulaire ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Ajouter un magasin ---
    if ($action === 'ajouter') {
        csrf_guard('magasins.php');

        $data = extract_post_data([
            'nom'         => ['type' => 'string', 'trim' => true],
            'adresse'     => ['type' => 'string', 'trim' => true],
            'code_postal' => ['type' => 'string', 'trim' => true],
        ], 'magasins.php');

        if ($data['nom'] === '') {
            flash_error('Le nom du magasin est obligatoire.');
            redirect('magasins.php');
        }

        if (mb_strlen($data['nom']) > 150) {
            flash_error('Le nom du magasin ne doit pas dépasser 150 caractères.');
            redirect('magasins.php');
        }

        try {
            $new_id = db_magasin_insert($pdo, $data['nom'], $data['adresse'], $data['code_postal']);
            suivre_activite('CREATION_MAGASIN', 'Création du magasin « ' . $data['nom'] . ' » (ID #' . $new_id . ')');
            flash_success('Magasin « ' . h($data['nom']) . ' » créé avec succès.');
        } catch (Throwable $e) {
            error_log('Erreur création magasin: ' . $e->getMessage());
            flash_error('Erreur lors de la création du magasin.');
        }
        redirect('magasins.php');
    }

    // --- Activer / Désactiver un magasin ---
    if ($action === 'toggle') {
        csrf_guard('magasins.php');

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash_error('Magasin invalide.');
            redirect('magasins.php');
        }

        try {
            db_magasin_toggle_active($pdo, $id);
            $mag = db_magasin_get_by_id($pdo, $id);
            $statut = ($mag && $mag['actif'] ?? 0) ? 'désactivé' : 'activé';
            suivre_activite('TOGGLE_MAGASIN', 'Magasin « ' . ($mag['nom'] ?? '#'.$id) . ' » ' . $statut);
            flash_success('Magasin mis à jour avec succès.');
        } catch (Throwable $e) {
            error_log('Erreur toggle magasin: ' . $e->getMessage());
            flash_error('Erreur lors de la mise à jour du magasin.');
        }
        redirect('magasins.php');
    }

    flash_error('Action inconnue.');
    redirect('magasins.php');
}

// ---- Données ----
$magasins = db_magasins_list_all($pdo);
$titre_page = 'Gestion des magasins';
$sous_titre = 'Administration';

echo $twig->render('magasins.html.twig', [
    'magasins' => $magasins,
]);
