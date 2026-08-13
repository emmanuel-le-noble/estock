<?php
/**
 * tests/fixtures/numero_concurrent.php — Génère N numéros de facture dans un
 * processus enfant (utilisé par ConcurrenceNumerotationTest pour simuler des
 * ventes simultanées multi-caisses).
 *
 * Usage : php numero_concurrent.php <quantite>
 * Sortie : un numéro par ligne (stdout), code de sortie 0 si OK.
 * Utilise la base de TEST (DB_NAME_TEST), jamais la base de production.
 */
$root = dirname(__DIR__, 2);
putenv('DB_NAME=' . (getenv('DB_NAME_TEST') ?: 'estock_db_test'));

require_once $root . '/config/connexion.php';
require_once $root . '/includes/db_functions.php';
require_once $root . '/includes/helpers.php';

$quantite = (int)($argv[1] ?? 1);
if ($quantite < 1 || $quantite > 1000) { fwrite(STDERR, "Quantité invalide.\n"); exit(1); }

try {
    for ($i = 0; $i < $quantite; $i++) {
        echo generate_invoice_number($pdo), "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Erreur: ' . $e->getMessage() . "\n");
    exit(1);
}