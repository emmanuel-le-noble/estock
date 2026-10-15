<?php
/**
 * Crée le premier compte Directeur après l'installation de la base.
 * Usage : php bin/create_admin.php "Nom complet" login "mot-de-passe-fort" [magasin_id]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Ce script doit être exécuté en ligne de commande.\n";
    exit(1);
}

$args = $_SERVER['argv'] ?? [];
if (count($args) < 4 || count($args) > 5) {
    fwrite(STDERR, "Usage : php bin/create_admin.php \"Nom complet\" login \"mot-de-passe-fort\" [magasin_id]\n");
    exit(64);
}

$nom = trim((string)$args[1]);
$login = trim((string)$args[2]);
$password = (string)$args[3];
$magasinId = isset($args[4]) ? (int)$args[4] : 1;

if ($nom === '' || !preg_match('/^[A-Za-z0-9._-]{3,80}$/', $login)) {
    fwrite(STDERR, "Nom ou identifiant invalide. L'identifiant doit contenir 3 à 80 caractères alphanumériques, . _ ou -.\n");
    exit(64);
}
if (mb_strlen($password) < 12) {
    fwrite(STDERR, "Le mot de passe doit contenir au moins 12 caractères.\n");
    exit(64);
}
if ($magasinId <= 0) {
    fwrite(STDERR, "Identifiant de magasin invalide.\n");
    exit(64);
}

require_once __DIR__ . '/../config/connexion.php';

try {
    $check = $pdo->prepare('SELECT id FROM magasins WHERE id = ? AND actif = 1');
    $check->execute([$magasinId]);
    if (!$check->fetchColumn()) {
        fwrite(STDERR, "Magasin introuvable ou inactif.\n");
        exit(1);
    }
    if (db_user_login_exists($pdo, $login)) {
        fwrite(STDERR, "Cet identifiant existe déjà. Choisissez-en un autre.\n");
        exit(1);
    }

    $id = db_user_insert($pdo, $nom, $login, password_hash($password, PASSWORD_DEFAULT), ROLE_DIRECTEUR, 1, $magasinId);
    echo "Compte Directeur créé (ID $id). Conservez le mot de passe hors des scripts et dépôts.\n";
} catch (Throwable $e) {
    error_log('create_admin: ' . $e->getMessage());
    fwrite(STDERR, "Création du compte impossible. Consultez le journal technique.\n");
    exit(1);
}
