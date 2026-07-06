<?php
/**
 * logout.php - Déconnexion.
 */
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/helpers.php';

suivre_activite('DECONNEXION', 'Déconnexion');

$_SESSION = [];
session_regenerate_id(true);
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: login.php');
exit;
