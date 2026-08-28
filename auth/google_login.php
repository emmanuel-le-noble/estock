<?php
/**
 * google_login.php — Redirige vers Google pour l'authentification OAuth 2.0.
 */
if (!function_exists('est_connecte')) { require_once __DIR__ . '/../config/connexion.php'; }
if (!function_exists('param')) { require_once __DIR__ . '/../config/parametres.php'; }
if (!function_exists('csrf_field')) { require_once __DIR__ . '/../includes/helpers.php'; }
require_once __DIR__ . '/../config/connexion.php';
require_once __DIR__ . '/../config/parametres.php';
require_once __DIR__ . '/../includes/helpers.php';

// Vérifier que Google OAuth est activé et configuré
$clientId = param('google_client_id', '');
$clientSecret = param('google_client_secret', '');
$oauthActif = param_bool('google_oauth_actif', false);

if (!$oauthActif || $clientId === '' || $clientSecret === '') {
    flash_error('La connexion Google n\'est pas configurée.');
    redirect('login.php');
}

$redirectUri = BASE_URL . 'auth/google_callback.php';

// Générer un state CSRF pour sécuriser le flow
$state = bin2hex(random_bytes(32));
$_SESSION['google_oauth_state'] = $state;

// Construire l'URL d'autorisation Google
$params = http_build_query([
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'access_type'   => 'offline',
    'prompt'        => 'select_account',
]);

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
exit;
