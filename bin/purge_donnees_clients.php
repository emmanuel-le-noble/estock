<?php
/**
 * bin/purge_donnees_clients.php — Purge programmée des données clients
 * (cron mensuel recommandé).
 *
 * 1. Anonymise les clients sans activité depuis 36 mois (politique de
 *    conservation : données sans activité → anonymisation) ;
 * 2. Supprime définitivement les fiches déjà anonymisées depuis 12 mois.
 *
 * Comportement strictement destructif : à exécuter sur la base de PRODUCTION
 * uniquement après avoir pris une sauvegarde (php bin/backup_db.php).
 *
 * Utilisation :
 *   php bin/purge_donnees_clients.php [--mois-inactivite=36] [--mois-anonyme=12] [--dry-run]
 *   --dry-run : affiche uniquement les compteurs, n'écrit rien.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Ce script doit être exécuté en ligne de commande (CLI).\n");
}

require_once __DIR__ . '/../config/connexion.php';
require_once __DIR__ . '/../includes/db_functions.php';

$argv = $_SERVER['argv'] ?? [];
$moisInactivite  = 36;
$moisAnonyme     = 12;
$dryRun          = false;

foreach ($argv as $arg) {
    if (preg_match('/^--mois-inactivite=(\d+)$/', $arg, $m)) $moisInactivite = (int)$m[1];
    if (preg_match('/^--mois-anonyme=(\d+)$/', $arg, $m))   $moisAnonyme  = (int)$m[1];
    if ($arg === '--dry-run') $dryRun = true;
}

echo "[eStock CLI Purge données clients] Inactivité > {$moisInactivite} mois, anonymisation > {$moisAnonyme} mois"
    . ($dryRun ? ' (DRY-RUN, aucune écriture)' : '') . "\n";

if ($dryRun) {
    $compteAnonymisables = (int)$pdo->query(
        "SELECT COUNT(*) FROM clients
         WHERE anonymise = 0 AND consentement_fidelite = 0
           AND (date_dernier_achat IS NULL OR date_dernier_achat < DATE_SUB(NOW(), INTERVAL {$moisInactivite} MONTH))"
    )->fetchColumn();
    $compteSupprimables = (int)$pdo->query(
        "SELECT COUNT(*) FROM clients
         WHERE anonymise = 1
           AND date_anonymisation < DATE_SUB(NOW(), INTERVAL {$moisAnonyme} MONTH)"
    )->fetchColumn();
    echo "  À anonymiser : $compteAnonymisables fiche(s)\n";
    echo "  À supprimer  : $compteSupprimables fiche(s)\n";
    exit(0);
}

$resultat = db_clients_purger($pdo, $moisInactivite, $moisAnonyme);

echo "  Anonymisés   : " . (int)($resultat['anonymises'] ?? 0) . " fiche(s)\n";
echo "  Supprimés    : " . (int)($resultat['supprimes'] ?? 0) . " fiche(s)\n";
if (!empty($resultat['erreurs'])) {
    echo "  Erreurs      : " . count($resultat['erreurs']) . "\n";
    foreach ($resultat['erreurs'] as $err) { echo "    - $err\n"; }
}
suivre_activite('PURGE_CLIENTS', 'Purge CLI données clients : ' . (int)($resultat['anonymises'] ?? 0) . ' anonymisés, '
    . (int)($resultat['supprimes'] ?? 0) . ' supprimés');
echo "[OK] Purge des données clients terminée.\n";
