<?php
/**
 * security_headers.php - En-têtes HTTP de sécurité.
 * Incluée depuis config/connexion.php.
 */

if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    $nonce = csp_nonce();
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; connect-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net;");
}
