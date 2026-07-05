<?php
/**
 * security_headers.php - En-têtes HTTP de sécurité.
 * Incluée depuis config/connexion.php.
 */

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    $nonce = csp_nonce();
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net;");
}
