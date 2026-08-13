<?php
/**
 * Bootstrap PHPUnit — eStock.
 *
 * Charge l'autoload Composer, puis l'environnement applicatif
 * (config/connexion.php charge .env, PDO, Twig, helpers).
 * Les tests d'intégration utilisent la base déclarée dans DB_NAME_TEST
 * (défaut : estock_db_test), jamais la base de production.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/connexion.php';
require_once __DIR__ . '/../includes/db_functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../database/sql_splitter.php';

if (!defined('TESTS_ROOT')) {
    define('TESTS_ROOT', __DIR__);
}

/**
 * Connexion dédiée aux tests d'intégration (base estock_db_test).
 * Crée la base et le schéma de référence au premier appel (idempotent).
 */
function test_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS');
    $name = getenv('DB_NAME_TEST') ?: 'estock_db_test';

    if ($pass === false || $pass === '') {
        throw new RuntimeException('DB_PASS doit être défini pour lancer les tests d\'intégration.');
    }

    $admin = new PDO(
        "mysql:host=$host;charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $admin->exec("DROP DATABASE IF EXISTS `$name`");
    $admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = new PDO(
        "mysql:host=$host;dbname=$name;charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    return $pdo;
}

/**
 * (Ré)initialiser le schéma de test : dump de référence + migrations clés.
 * Destructif pour la base de test uniquement — jamais appelé en prod.
 */
function test_db_schema(): void {
    $pdo = test_db();
    $root = dirname(__DIR__);

    // Dump de référence (DROP + CREATE + données de démo).
    // Les instructions session/transaction (SET…) peuvent échouer sur un
    // environnement distant : elles sont ignorées, les CREATE/DATA sont stricts.
    $sql = file_get_contents($root . '/database/estock_db (1).sql');
    if ($sql === false) {
        throw new RuntimeException('Dump de référence introuvable : database/estock_db (1).sql');
    }
    foreach (split_sql_statements($sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        if (preg_match('/^(SET\s|START TRANSACTION|COMMIT)/i', $stmt)) continue;
        $pdo->exec($stmt);
    }

    // Migrations clés (séquences, NF525, RGPD, fidélité, valorisation)
    foreach ([
        'compteurs_sequences.sql',
        'nf525.sql',
        'rgpd.sql',
        'fidelite.sql',
        'points_reversals.sql',
        'valorisation_stock.sql',
        'unite_mesure.sql',
    ] as $file) {
        $m = file_get_contents($root . '/database/' . $file);
        if ($m === false) continue;
        foreach (split_sql_statements($m) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') continue;
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                // Idempotence : les colonnes/index déjà présents ne sont pas bloquants.
            }
        }
    }
}

/**
 * Exécute un script CLI dans un processus enfant (concurrence réelle).
 * Retourne la sortie standard.
 */
function run_child_php(string $scriptPath, string $args = ''): array {
    $php = PHP_BINARY;
    $root = dirname(__DIR__);
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($scriptPath) . ' ' . $args;
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return ['code' => $code, 'sortie' => implode("\n", $out)];
}