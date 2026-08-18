<?php
/**
 * suggestions_achat.php - Suggestions d'achat intelligentes (Directeur, Magasinier, Admin).
 *
 * Analyse les ventes (vitesse de vente, jours de couverture restants) et les alertes
 * de stock pour recommander les quantités à commander, regroupées par fournisseur.
 * Le Magasinier peut générer directement un bon de commande (brouillon) qui devra
 * ensuite être validé par le Directeur.
 */
// Guard: compatible avec le routeur front controller (index.php)
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_role(ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_MAGASINIER);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'envoyer_alerte_email') {
    csrf_guard('suggestions_achat.php');
    $ok = notifier_stock_faible_email($pdo);
    if ($ok) {
        flash_success('Alerte de stock mise en file d\'attente pour envoi par e-mail.');
    } else {
        flash_info('Aucun destinataire configuré ou e-mail bloqué (consentement requis).');
    }
    redirect('suggestions_achat.php');
}

// Magasin de suivi (choisi par le Directeur, magasin d'appartenance pour les autres)
$magasin_id = user_magasin_id();

$jours_etude = 30;      // Période d'analyse des ventes
$jours_couverture = 30; // Couverture cible en jours de vente estimée

$articles = db_articles_achat_recommandes($pdo, $magasin_id, $jours_couverture, $jours_etude);
$groupes  = generate_restock_list_by_supplier($articles);
$nb_total = count($articles);

// Meilleurs vendeurs (hors alerte éventuellement) avec stock et couverture
$ventes_rapides = db_articles_hautes_ventes($pdo, $magasin_id, 10, $jours_etude);

echo $twig->render('suggestions_achat.html.twig', [
    'titre_page'      => "Suggestions d'achat",
    'articles'        => $articles,
    'groupes'         => $groupes,
    'nb_total'        => $nb_total,
    'ventes_rapides'  => $ventes_rapides,
    'magasin_id'      => $magasin_id,
    'csp_nonce_val'   => csp_nonce()
]);