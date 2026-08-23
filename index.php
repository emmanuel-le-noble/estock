<?php
/**
 * index.php - Point d'entrée simple.
 * Redirige vers le tableau de bord ou la page de connexion.
 */
// Verifier si l'installation est requise
if (!is_file(__DIR__ . '/.env')) {
    header('Location: install.php');
    exit;
}
require_once __DIR__ . '/config/connexion.php';

if (est_connecte()) {
    header('Location: tableau_bord.php');
} else {
    header('Location: auth/login.php');
}
exit;
