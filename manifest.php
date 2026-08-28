<?php
/**
 * manifest.php — Manifest PWA dynamique.
 */
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=86400');

$base = BASE_URL;
echo json_encode([
    'name'             => 'eStock — Gestion de Stock & Caisse',
    'short_name'       => 'eStock',
    'description'      => 'Application de gestion de stock et point de vente',
    'start_url'        => $base . 'tableau_bord.php',
    'display'          => 'standalone',
    'background_color' => '#ffffff',
    'theme_color'      => '#4f46e5',
    'orientation'      => 'any',
    'categories'       => ['business', 'finance'],
    'icons' => [
        ['src' => $base . 'assets/images/logo-eStock-2.PNG', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => $base . 'assets/images/logo-eStock-2.PNG', 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => $base . 'assets/images/logo-eStock-2.PNG', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
