<?php
/**
 * logout.php - Déconnexion (POST uniquement + CSRF).
 */
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/helpers.php';

// SEC-12 : Exiger POST + CSRF pour éviter les attaques CSRF logout
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_validate()) {
    flash_error('Requête invalide.');
    redirect('dashboard.php');
}

suivre_activite('DECONNEXION', 'Déconnexion');

$_SESSION = [];
session_regenerate_id(true);
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'] ?? '/',
        'domain'   => $p['domain'] ?? '',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly'  => true,
        'samesite'  => 'Lax',
    ]);
}
session_destroy();
header('Location: login.php');
exit;
