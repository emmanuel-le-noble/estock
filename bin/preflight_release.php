<?php
/**
 * Contrôle non destructif avant remise d'une installation eStock.
 * Usage : php bin/preflight_release.php
 * Code retour : 0 = prêt, 1 = bloquant, 2 = avertissement à traiter.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Ce script doit être exécuté en ligne de commande.\n";
    exit(1);
}

require_once __DIR__ . '/../config/connexion.php';

$errors = [];
$warnings = [];

foreach (['pdo_mysql', 'openssl', 'mbstring', 'json', 'session'] as $extension) {
    if (!extension_loaded($extension)) {
        $errors[] = "Extension PHP absente : $extension";
    }
}

if (APP_ENV !== 'production') {
    $errors[] = 'APP_ENV doit être défini à production avant livraison.';
}
if (DB_USER === 'root') {
    $errors[] = 'Le compte MySQL root ne doit pas être utilisé chez un client.';
}

$requiredTables = [
    'articles', 'stock_magasins', 'factures', 'lignes_facture',
    'utilisateurs', 'magasins', 'parametres', 'logs_activite',
];
foreach ($requiredTables as $table) {
    // SHOW TABLES ne prend pas de placeholder avec tous les pilotes MySQL.
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $stmt->execute([$table]);
    if (!$stmt->fetchColumn()) {
        $errors[] = "Table obligatoire absente : $table";
    }
}

// Les comptes de démonstration ne doivent jamais être activés chez un client.
$stmt = $pdo->prepare("SELECT login FROM utilisateurs WHERE actif = 1 AND login IN ('admin', 'magasin', 'vendeur')");
$stmt->execute();
$activeDefaults = $stmt->fetchAll(PDO::FETCH_COLUMN);
if ($activeDefaults) {
    $errors[] = 'Compte(s) de démonstration actif(s) : ' . implode(', ', $activeDefaults);
}

$stmt = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE actif = 1 AND role = 'chef équipe'");
if ((int)$stmt->fetchColumn() === 0) {
    $errors[] = 'Aucun compte Directeur actif : exécutez bin/create_admin.php.';
}

$backupDir = __DIR__ . '/../backups';
$backups = is_dir($backupDir) ? glob($backupDir . '/estock_backup_*') : [];
if (empty($backups)) {
    $warnings[] = 'Aucune sauvegarde trouvée : exécutez bin/backup_db.php après la remise.';
} else {
    $latest = max(array_map('filemtime', $backups));
    if ($latest < time() - 86400) {
        $warnings[] = 'La dernière sauvegarde a plus de 24 heures.';
    }
}

echo "=== eStock — contrôle pré-livraison ===\n";
echo 'Environnement : ' . APP_ENV . "\n";
echo 'Base : ' . DB_NAME . "\n\n";

foreach ($errors as $error) {
    echo "[BLOQUANT] $error\n";
}
foreach ($warnings as $warning) {
    echo "[AVERTISSEMENT] $warning\n";
}

if (!$errors && !$warnings) {
    echo "[OK] Installation prête pour remise.\n";
    exit(0);
}
if ($errors) {
    exit(1);
}
exit(2);
