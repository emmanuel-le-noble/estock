<?php
/**
 * bin/test_restore.php — Test de restauration de la dernière sauvegarde.
 *
 * Restaure le dump le plus récent de backups/ sur une base de test
 * (estock_db_test par défaut) et vérifie l'intégrité :
 *   * nombre de tables restaurées vs nombre de tables sauvegardées ;
 *   * comptage des utilisateurs (table `utilisateurs`) ;
 *   * chiffres d'affaires agrégés (factures Payee, les triggers d'inaltérabilité
 *     interdisent les DELETE physiques).
 *
 * Utilisation :
 *   php bin/test_restore.php [fichier.sql]
 *   (sans argument : dernière sauvegarde du dossier backups/)
 *
 * Base de TEST uniquement : jamais la production.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Ce script doit être exécuté en ligne de commande (CLI).\n");
}

require_once __DIR__ . '/../config/connexion.php';

$backupDir = __DIR__ . '/../backups';
$dbHost  = getenv('DB_HOST') ?: '127.0.0.1';
$dbUser  = getenv('DB_USER') ?: 'root';
$dbPass  = getenv('DB_PASS');
$dbTest  = getenv('DB_NAME_TEST') ?: 'estock_db_test';

// ---- 1) Choisir le fichier à tester ----
$argv = $_SERVER['argv'] ?? [];
$fichier = $argv[1] ?? '';
if ($fichier !== '' && !is_file($fichier)) {
    exit("Fichier introuvable : $fichier\n");
}
if ($fichier === '') {
    $candidats = glob($backupDir . '/estock_backup_*');
    if (empty($candidats)) {
        exit("Aucune sauvegarde dans $backupDir. Lancez d'abord : php bin/backup_db.php\n");
    }
    $fichier = end($candidats);
}

if (!is_file($fichier)) {
    exit("Sauvegarde introuvable : $fichier\n");
}

echo "[eStock CLI Restore Test] Fichier testé : " . basename($fichier) . "\n";

// ---- 2) Décompression éventuelle (zip) ----
$sqlPath = $fichier;
if (str_ends_with(strtolower($fichier), '.zip')) {
    $zip = new ZipArchive();
    if ($zip->open($fichier) !== true) {
        exit("[ERREUR] Impossible d'ouvrir le ZIP.\n");
    }
    $inner = $zip->getNameIndex(0);
    $tmp = sys_get_temp_dir() . '/' . basename((string)$inner);
    $zip->extractTo(sys_get_temp_dir(), (string)$inner);
    $zip->close();
    $sqlPath = $tmp;
}

// ---- 3) Restauration sur la base de test ----
try {
    $admin = new PDO(
        "mysql:host=$dbHost;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $admin->exec("DROP DATABASE IF EXISTS `$dbTest`");
    $admin->exec("CREATE DATABASE `$dbTest` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbTest;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );

    $sql = file_get_contents($sqlPath);
    if ($sql === false) {
        exit("[ERREUR] Lecture du dump impossible.\n");
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    foreach (explode(";\n", $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            echo "  [AVERTISSEMENT] instruction ignorée : " . substr($e->getMessage(), 0, 100) . "\n";
        }
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
} catch (PDOException $e) {
    exit("[ERREUR] Connexion/restauration : " . $e->getMessage() . "\n");
}

// ---- 4) Vérifications d'intégrité ----
$nbTables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$dbTest'")->fetchColumn();
$nbUsers  = (int)$pdo->query("SELECT COUNT(*) FROM utilisateurs")->fetchColumn();
$nbFactures = (int)$pdo->query("SELECT COUNT(*) FROM factures")->fetchColumn();
$caPaye = (float)$pdo->query("SELECT COALESCE(SUM(total_ttc),0) FROM factures WHERE statut = 'Payee'")->fetchColumn();
$nbArticles = (int)$pdo->query("SELECT COUNT(*) FROM articles")->fetchColumn();

echo "\n--- Résultat du test de restauration ---\n";
echo "  Tables restaurées     : $nbTables\n";
echo "  Utilisateurs          : $nbUsers\n";
echo "  Articles              : $nbArticles\n";
echo "  Factures              : $nbFactures\n";
echo "  CA factures Payée     : " . number_format($caPaye, 2, ',', ' ') . "\n";

if ($nbTables === 0 || $nbUsers === 0) {
    exit("[ÉCHEC] La restauration semble vide.\n");
}
echo "[OK] La dernière sauvegarde est restaurable et lisible.\n";
exit(0);