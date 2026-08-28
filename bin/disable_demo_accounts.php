<?php
/**
 * Désactive les anciens comptes de démonstration d'une installation existante.
 * Usage : php bin/disable_demo_accounts.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Ce script doit être exécuté en ligne de commande.\n";
    exit(1);
}

require_once __DIR__ . '/../config/connexion.php';

try {
    // Ne jamais désactiver le dernier Directeur accessible.
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM utilisateurs
         WHERE actif = 1 AND role = 'chef équipe'
           AND login NOT IN ('admin', 'magasin', 'vendeur')"
    );
    if ((int)$stmt->fetchColumn() === 0) {
        fwrite(STDERR, "Créez d'abord un nouveau compte Directeur actif avec bin/create_admin.php.\n");
        exit(1);
    }

    $pdo->beginTransaction();
    $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        "UPDATE utilisateurs
         SET actif = 0, mot_de_passe = ?
         WHERE login IN ('admin', 'magasin', 'vendeur') AND actif = 1"
    );
    $stmt->execute([$passwordHash]);
    $disabled = $stmt->rowCount();
    $pdo->commit();

    echo "$disabled compte(s) de démonstration désactivé(s).\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('disable_demo_accounts: ' . $e->getMessage());
    fwrite(STDERR, "Désactivation impossible. Consultez le journal technique.\n");
    exit(1);
}
