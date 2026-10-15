<?php
/**
 * manifest.php — Manifest PWA dynamique.
 */
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$base = BASE_URL;
$logo_boutique = param_shop_logo();
$icone_pwa = $logo_boutique ?: 'assets/images/estock-mark.svg';
$icone_abs = __DIR__ . '/' . $icone_pwa;
$icone_dimensions = $logo_boutique && is_file($icone_abs) ? @getimagesize($icone_abs) : false;
$icone_taille = $icone_dimensions ? $icone_dimensions[0] . 'x' . $icone_dimensions[1] : 'any';
$icone_type = $logo_boutique ? (['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][pathinfo($logo_boutique, PATHINFO_EXTENSION)] ?? 'image/png') : 'image/svg+xml';
echo json_encode([
    'name'             => 'eStock — Gestion de Stock & Caisse',
    'short_name'       => 'eStock',
    'description'      => 'Application de gestion de stock et point de vente',
    'start_url'        => $base . 'tableau_bord.php',
    'display'          => 'standalone',
    'background_color' => '#ffffff',
    'theme_color'      => '#155bd0',
    'orientation'      => 'any',
    'categories'       => ['business', 'finance'],
    'icons' => [
        ['src' => $base . $icone_pwa, 'sizes' => $icone_taille, 'type' => $icone_type],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
