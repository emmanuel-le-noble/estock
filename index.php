<?php
/**
 * index.php - Point d'entrée simple.
 * Redirige vers le tableau de bord ou la page de connexion.
 */
require_once __DIR__ . '/config/connexion.php';

if (est_connecte()) {
    header('Location: tableau_bord.php');
} else {
    header('Location: login.php');
}
exit;
