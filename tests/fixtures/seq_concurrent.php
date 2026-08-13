<?php
$root = dirname(__DIR__, 2);
putenv('DB_NAME=' . (getenv('DB_NAME_TEST') ?: 'estock_db_test'));
require_once $root . '/config/connexion.php';
$cle = 'seq:test:odku';
for ($i = 0; $i < 5; $i++) {
    $pdo->prepare("INSERT INTO sequences (cle, valeur) VALUES (?, 1)
                   ON DUPLICATE KEY UPDATE valeur = LAST_INSERT_ID(valeur + 1), date_maj = CURRENT_TIMESTAMP")->execute([$cle]);
    echo $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn(), "\n";
}