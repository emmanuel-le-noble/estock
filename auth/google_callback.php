<?php
/**
 * google_callback.php — Reçoit la réponse de Google, échange le code contre un token,
 * récupère le profil et connecte/crée l'utilisateur.
 */
if (!function_exists('est_connecte')) { require_once __DIR__ . '/../config/connexion.php'; }
if (!function_exists('param')) { require_once __DIR__ . '/../config/parametres.php'; }
if (!function_exists('db_user_get_by_google_email')) { require_once __DIR__ . '/../includes/db_functions.php'; }
if (!function_exists('csrf_field')) { require_once __DIR__ . '/../includes/helpers.php'; }
require_once __DIR__ . '/../config/connexion.php';
require_once __DIR__ . '/../config/parametres.php';
require_once __DIR__ . '/../includes/db_functions.php';
require_once __DIR__ . '/../includes/helpers.php';

$clientId     = param('google_client_id', '');
$clientSecret = param('google_client_secret', '');
$oauthActif   = param_bool('google_oauth_actif', false);

// --- Vérifications préalables ---
if (!$oauthActif || $clientId === '' || $clientSecret === '') {
    flash_error('La connexion Google n\'est pas configurée.');
    redirect('../auth/login.php');
}

// Vérifier le state CSRF
$state = $_GET['state'] ?? '';
if (empty($state) || !hash_equals($_SESSION['google_oauth_state'] ?? '', $state)) {
    flash_error('Session OAuth invalide. Réessayez.');
    redirect('../auth/login.php');
}
unset($_SESSION['google_oauth_state']);

// Vérifier la réponse de Google
if (isset($_GET['error'])) {
    $err = $_GET['error'];
    if ($err === 'access_denied') {
        flash_error('Connexion Google annulée.');
    } else {
        flash_error('Erreur Google : ' . h($err));
    }
    redirect('../auth/login.php');
}

$code = $_GET['code'] ?? '';
if ($code === '') {
    flash_error('Code d\'autorisation manquant.');
    redirect('../auth/login.php');
}

// --- Échange du code contre un token ---
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$redirectUri = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/eSotck/auth/google_callback.php';

$tokenData = json_decode(_google_fetch('https://oauth2.googleapis.com/token', [
    'code'          => $code,
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code',
]), true);

if (empty($tokenData['access_token'])) {
    error_log('[GOOGLE_OAUTH] Échange code→token échoué: ' . json_encode($tokenData));
    flash_error('Échec de l\'authentification Google. Réessayez.');
    redirect('../auth/login.php');
}

// --- Récupération du profil utilisateur ---
$profile = json_decode(_google_fetch('https://www.googleapis.com/oauth2/v2/userinfo', [
    'access_token' => $tokenData['access_token'],
]), true);

if (empty($profile['id']) || empty($profile['email'])) {
    error_log('[GOOGLE_OAUTH] Profil Google incomplet: ' . json_encode($profile));
    flash_error('Impossible de récupérer les informations du compte Google.');
    redirect('../auth/login.php');
}

$googleId    = (string)$profile['id'];
$googleEmail = (string)$profile['email'];
$googleName  = trim((string)($profile['name'] ?? ''));
$googleAvatar = $profile['picture'] ?? null;

if ($googleName === '') {
    $googleName = $profile['given_name'] ?? $googleEmail;
}

// --- Logique : lier ou créer ---
$user = db_user_get_by_google_email($pdo, $googleEmail);

if ($user) {
    // Compte existant lié → mise à jour google_id si nécessaire
    if (empty($user['google_id']) || $user['google_id'] !== $googleId) {
        db_user_link_google($pdo, $user['id'], $googleId, $googleEmail, $googleAvatar);
    }
    $utilisateur = $user;
    $actionLog = 'CONNEXION_GOOGLE';
    $msgLog = 'Connexion Google réussie (compte existant lié): ' . $googleEmail;
} else {
    // Créer un nouveau compte
    $newId = db_user_create_from_google($pdo, $googleName, $googleId, $googleEmail, $googleAvatar);
    $utilisateur = [
        'id' => $newId,
        'nom' => $googleName,
        'login' => 'google_' . $googleId,
        'role' => ROLE_VENDEUR,
        'magasin_id' => null,
        'actif' => 1,
    ];
    $actionLog = 'INSCRIPTION_GOOGLE';
    $msgLog = 'Nouveau compte créé via Google: ' . $googleEmail;
}

// --- Créer la session ---
session_regenerate_id(true);
$_SESSION['user'] = $utilisateur;
if (!empty($utilisateur['magasin_id'])) {
    $_SESSION['magasin_actif'] = (int)$utilisateur['magasin_id'];
} else {
    unset($_SESSION['magasin_actif']);
}

suivre_activite($actionLog, $msgLog);

// Rediriger selon le rôle
if (($utilisateur['role'] ?? '') === ROLE_DIRECTEUR) {
    redirect('choisir_magasin.php');
}
redirect('../tableau_bord.php');

// ============================================================
//  Fonction interne : requête HTTP simple (curl ou file_get_contents)
// ============================================================
function _google_fetch(string $url, array $postData = []): string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => !empty($postData),
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response ?: '';
    }
    // Fallback file_get_contents
    $opts = ['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
        'content' => http_build_query($postData),
        'timeout' => 15,
    ]];
    $ctx = stream_context_create($opts);
    return @file_get_contents($url, false, $ctx) ?: '';
}
