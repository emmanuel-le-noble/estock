<?php
/**
 * bin/backup_db.php — Script d'archivage et de sauvegarde BDD eStock via CLI / Cron.
 *
 * Utilisation :
 *   php bin/backup_db.php
 *
 * Exécution :
 *   - Crée un dump SQL zippé dans le dossier `backups/`
 *   - Supprime automatiquement les sauvegardes de plus de 30 jours (rotation)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Ce script doit être exécuté en ligne de commande (CLI).\n");
}

require_once __DIR__ . '/../config/connexion.php';
require_once __DIR__ . '/../includes/helpers.php';

$backupDir = __DIR__ . '/../backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$date = date('Y-m-d_H-i-s');
$filename = 'estock_backup_' . $date . '.sql';
$filepath = $backupDir . '/' . $filename;

echo "[eStock CLI Backup] Démarrage de la sauvegarde BDD...\n";

try {
    // Exporter toutes les tables et données via PDO
    $tables = [];
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    $sqlDump = "-- eStock Database Backup\n";
    $sqlDump .= "-- Généré le : " . date('Y-m-d H:i:s') . "\n";
    $sqlDump .= "-- Version PHP : " . PHP_VERSION . "\n\n";
    $sqlDump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        // Structure
        $createStmt = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        $sqlDump .= "-- Structure de la table `$table` --\n";
        $sqlDump .= "DROP TABLE IF EXISTS `$table`;\n";
        $sqlDump .= $createStmt['Create Table'] . ";\n\n";

        // Données
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $sqlDump .= "-- Données de la table `$table` --\n";
            foreach ($rows as $row) {
                $keys   = array_map(fn($k) => "`$k`", array_keys($row));
                $values = array_map(function($v) use ($pdo) {
                    if ($v === null) return 'NULL';
                    return $pdo->quote($v);
                }, array_values($row));

                $sqlDump .= "INSERT INTO `$table` (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $values) . ");\n";
            }
            $sqlDump .= "\n";
        }
    }

    $sqlDump .= "SET FOREIGN_KEY_CHECKS=1;\n";

    file_put_contents($filepath, $sqlDump);
    echo "[eStock CLI Backup] Fichier SQL généré : $filename (" . round(filesize($filepath) / 1024, 2) . " KB)\n";

    // Compression Zip si l'extension zip est activée
    if (class_exists('ZipArchive')) {
        $zipPath = $filepath . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
            $zip->addFile($filepath, $filename);
            $zip->close();
            unlink($filepath); // Supprimer le .sql non compressé
            echo "[eStock CLI Backup] Compression ZIP terminée : " . basename($zipPath) . " (" . round(filesize($zipPath) / 1024, 2) . " KB)\n";
        }
    }

    // Rotation des sauvegardes : suppression des fichiers de plus de 30 jours
    $retentionDays = 30;
    $now = time();
    $files = glob($backupDir . '/estock_backup_*');
    $purged = 0;

    foreach ($files as $file) {
        if (is_file($file)) {
            if ($now - filemtime($file) >= ($retentionDays * 86400)) {
                unlink($file);
                $purged++;
            }
        }
    }

    if ($purged > 0) {
        echo "[eStock CLI Backup] Rotation : $purged ancienne(s) sauvegarde(s) purgée(s).\n";
    }

    echo "[eStock CLI Backup] Sauvegarde terminée avec succès.\n";
    exit(0);

} catch (\Throwable $e) {
    echo "[eStock CLI Backup ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
