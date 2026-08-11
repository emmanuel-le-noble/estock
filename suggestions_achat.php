<?php
/**
 * suggestions_achat.php - Articles en alerte de stock regroupes par fournisseur.
 *
 * Permet au Directeur et au Magasinier de visualiser les articles necessitant
 * un reapprovisionnement, avec les coordonnees du fournisseur associe.
 */
// Guard: compatible avec le routeur front controller (index.php)
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_role(ROLE_DIRECTEUR, ROLE_MAGASINIER);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'envoyer_alerte_email') {
    csrf_guard('suggestions_achat.php');
    $ok = notifier_stock_faible_email($pdo);
    if ($ok) {
        flash_success('Alerte de stock envoyé par email.');
    } else {
        flash_info('Email de notification simulé / enregistré dans les logs de l\'application.');
    }
    redirect('suggestions_achat.php');
}

$u = user_courant();
$magasin_id = (int)($u['magasin_id'] ?? 0);
$articles = db_articles_low_stock_with_supplier($pdo, 100, $magasin_id);
$groupes  = generate_restock_list_by_supplier(is_array($articles) ? $articles : []);
$nb_total = is_array($articles) ? count($articles) : 0;

echo $twig->render('suggestions_achat.html.twig', [
    'titre_page'    => "Alertes Stock — Suggestions d'achat",
    'articles'      => $articles,
    'groupes'       => $groupes,
    'nb_total'      => $nb_total,
    'csp_nonce_val' => csp_nonce() // Assure la transmission correcte du nonce pour l'impression
]);