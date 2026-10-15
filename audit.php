<?php
/**
 * audit.php - Journal d'activité (permission RBAC).
 */
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_permission('audit_consulter');

$search   = input_string($_GET['search'] ?? '');
$f_action = $_GET['action_filter'] ?? '';
$debut    = $_GET['debut'] ?? '';
$fin      = $_GET['fin'] ?? '';

$search_sql = db_logs_activite_search_sql([
    'search' => $search,
    'action' => $f_action,
    'debut'  => $debut,
    'fin'    => $fin,
], user_magasin_id());
$result = paginate($search_sql['sql'], $search_sql['params'], 30);
$logs = $result['items'];

$actions_distinct = $pdo->query("SELECT DISTINCT action FROM logs_activite ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

echo $twig->render('audit.html.twig', [
    'titre_page'       => 'Journal d\'activité',
    'logs'             => $logs,
    'page'             => $result['page'],
    'total_pages'      => $result['total_pages'],
    'total'            => $result['total'],
    'search'           => $search,
    'f_action'         => $f_action,
    'debut'            => $debut,
    'fin'              => $fin,
    'actions_distinct' => $actions_distinct,
]);
