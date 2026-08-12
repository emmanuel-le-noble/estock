<?php
/**
 * transferts.php — Gestion des transferts inter-magasins.
 *
 * Directeur / Admin : peut créer un transfert.
 * Magasinier : peut consulter les transferts liés à son magasin.
 */
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_role(ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_MAGASINIER);

$u = user_courant();
$magasin_id = (int)($u['magasin_id'] ?? 0);

// ---- Traitement formulaire : nouveau transfert ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'transferer') {
    csrf_guard('transferts.php');

    // Les transferts sont réservés à la direction : contrôle serveur (l'UI le masque aussi)
    if (!in_array(user_role(), [ROLE_DIRECTEUR, ROLE_ADMIN], true)) {
        flash_error('Droits insuffisants pour effectuer un transfert.');
        redirect('transferts.php');
    }

    $data = extract_post_data([
        'article_id'          => ['type' => 'int'],
        'magasin_source_id'   => ['type' => 'int'],
        'magasin_destination_id' => ['type' => 'int'],
        'quantite'            => ['type' => 'int', 'min' => 1],
        'motif'               => ['type' => 'string', 'trim' => true],
    ], 'transferts.php');

    if ($data['article_id'] <= 0 || $data['magasin_source_id'] <= 0 || $data['magasin_destination_id'] <= 0
        || $data['quantite'] <= 0 || $data['magasin_source_id'] === $data['magasin_destination_id']) {
        flash_error('Données invalides ou magasins identiques.');
        redirect('transferts.php');
    }

    try {
        db_transferer_stock(
            $pdo,
            $data['article_id'],
            $data['magasin_source_id'],
            $data['magasin_destination_id'],
            $data['quantite'],
            $data['motif'] !== '' ? $data['motif'] : null
        );

        $art = db_article_get_by_id($pdo, $data['article_id']);
        suivre_activite('TRANSFERT_STOCK',
            $data['quantite'] . ' unité(s) de « ' . ($art['nom'] ?? '?') . ' » '
            . 'du magasin #' . $data['magasin_source_id'] . ' vers #' . $data['magasin_destination_id']
        );
        flash_success('Transfert enregistré avec succès.');
    } catch (Throwable $e) {
        // db_transferer_stock() gère déjà sa propre transaction (rollback interne) :
        // appeler rollBack() ici provoquerait une PDOException "no active transaction".
        error_log('Erreur transfert: ' . $e->getMessage());
        flash_error('Erreur lors du transfert. Veuillez réessayer.');
    }
    redirect('transferts.php');
}

// ---- Liste des transferts ----
$magasins = db_magasins_list($pdo);
$transferts = db_transferts_list($pdo, $magasin_id);
$articles = db_articles_list_active($pdo);

$titre_page = 'Transferts inter-magasins';
echo $twig->render('transferts.html.twig', [
    'transferts'    => $transferts,
    'magasins'      => $magasins,
    'articles'      => $articles,
    'magasin_id'    => $magasin_id,
    'can_transfer'  => in_array($u['role'] ?? '', [ROLE_DIRECTEUR, ROLE_ADMIN], true),
]);
