<?php
/**
 * carte_fidelite.php - Carte de fidélité imprimable (code-barres / QR).
 * Usage : carte_fidelite.php?id=<client>&ts=<ts>&token=<hmac>
 * Sécurisé : authentification OU signature HMAC valide (expiration 24h).
 */

if (!function_exists('est_connecte')) {
    require_once __DIR__ . '/config/connexion.php';
}
if (!function_exists('db_article_insert')) {
    require_once __DIR__ . '/includes/db_functions.php';
}
if (!function_exists('csrf_guard')) {
    require_once __DIR__ . '/includes/helpers.php';
}
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';

if (!param_bool('fidelite_actif', false)) {
    http_response_code(404);
    exit('Programme de fidélité inactif.');
}
if (!peut('clients_consulter')) {
    http_response_code(403);
    exit('Droits insuffisants.');
}

$clientId = (int)($_GET['id'] ?? 0);
if ($clientId <= 0) {
    http_response_code(400);
    exit('Paramètre id invalide.');
}

// Sécurité : authentification OU signature HMAC valide
$token = input_string($_GET['token'] ?? '');
if (!est_connecte()) {
    if (!verify_url_signature($clientId, $token)) {
        http_response_code(403);
        exit('Accès non autorisé.');
    }
}

$client = db_client_get_by_id($pdo, $clientId);
if (!$client) {
    http_response_code(404);
    exit('Client introuvable.');
}

// Générer un code de carte si absent (préfixe F + identifiant)
$code = trim((string)($client['code_fidelite'] ?? ''));
if ($code === '') {
    $code = 'F' . str_pad((string)$clientId, 8, '0', STR_PAD_LEFT);
}

$nom_client = !empty($client['nom']) ? $client['nom'] : ($client['raison_sociale'] ?? '');
$nom_client = trim($nom_client);
$nom_boutique = param_shop_name();
$devise = param('devise_symbole', 'FCFA');

$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/templates');
$twig = new \Twig\Environment($loader, ['autoescape' => 'html']);

echo $twig->render('carte_fidelite.html.twig', [
    'code'         => $code,
    'nom_client'   => $nom_client,
    'solde_points' => (int)$client['points_fidelite'],
    'consenti'     => (int)$client['consentement_fidelite'] === 1,
    'nom_boutique' => $nom_boutique,
    'devise'       => $devise,
    'base_url'     => BASE_URL,
]);
