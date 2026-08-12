<?php
/**
 * apply_migrations.php — Application des migrations BDD eStock (version robuste).
 * Gère les instructions multi-lignes et les PREPARE/EXECUTE via PHP natif.
 */
$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=estock_db;charset=utf8mb4',
    'root', 'admin',
    [
        PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE       => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]
);

echo "=== Application des migrations BDD eStock ===\n\n";

// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------
function run_sql(PDO $pdo, string $sql, string $label): void {
    $sql = trim($sql);
    if ($sql === '') return;
    try {
        $pdo->exec($sql . ';');
        echo "  ✅ $label\n";
    } catch (\PDOException $e) {
        $msg = $e->getMessage();
        if (
            str_contains($msg, 'Duplicate column name') ||
            str_contains($msg, 'already exists')        ||
            str_contains($msg, 'Duplicate entry')       ||
            str_contains($msg, 'Duplicate key name')
        ) {
            echo "  [SKIP déjà appliqué] $label\n";
        } else {
            echo "  ❌ ERREUR ($label): $msg\n";
        }
    }
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

// ================================================================
// MIGRATION : categories.sql (Module 2)
// ================================================================
echo "[MIGRATION] categories.sql\n";

if (!table_exists($pdo, 'categories')) {
    run_sql($pdo, "
        CREATE TABLE `categories` (
            `id`          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `nom`         VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
            `description` TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `actif`       TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "CREATE TABLE categories");
} else {
    echo "  [SKIP] categories existe déjà\n";
}

if (!column_exists($pdo, 'articles', 'categorie_id')) {
    run_sql($pdo,
        "ALTER TABLE `articles` ADD COLUMN `categorie_id` INT DEFAULT NULL AFTER `fournisseur_id`",
        "ADD COLUMN articles.categorie_id"
    );
} else {
    echo "  [SKIP] articles.categorie_id existe déjà\n";
}
echo "\n";

// ================================================================
// MIGRATION : tva_multiple.sql (Module 3)
// ================================================================
echo "[MIGRATION] tva_multiple.sql\n";

if (!column_exists($pdo, 'articles', 'taux_tva')) {
    run_sql($pdo,
        "ALTER TABLE `articles` ADD COLUMN `taux_tva` DECIMAL(5,2) DEFAULT NULL AFTER `prix_vente`",
        "ADD COLUMN articles.taux_tva"
    );
} else {
    echo "  [SKIP] articles.taux_tva existe déjà\n";
}

if (!column_exists($pdo, 'lignes_facture', 'taux_tva')) {
    run_sql($pdo,
        "ALTER TABLE `lignes_facture` ADD COLUMN `taux_tva` DECIMAL(5,2) DEFAULT NULL AFTER `remise_pct`",
        "ADD COLUMN lignes_facture.taux_tva"
    );
} else {
    echo "  [SKIP] lignes_facture.taux_tva existe déjà\n";
}
echo "\n";

// ================================================================
// MIGRATION : inventaire.sql (Module 4)
// ================================================================
echo "[MIGRATION] inventaire.sql\n";

if (!table_exists($pdo, 'inventaires')) {
    run_sql($pdo, "
        CREATE TABLE `inventaires` (
            id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            reference       VARCHAR(40) NOT NULL,
            magasin_id      INT NOT NULL DEFAULT 1,
            statut          ENUM('En cours','Validé','Annulé') NOT NULL DEFAULT 'En cours',
            date_debut      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            date_fin        DATETIME NULL,
            utilisateur_id  INT NULL,
            notes           TEXT NULL,
            created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_inv_ref (reference),
            CONSTRAINT fk_inv_magasin  FOREIGN KEY (magasin_id)     REFERENCES magasins(id)     ON DELETE RESTRICT,
            CONSTRAINT fk_inv_user     FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE SET NULL,
            INDEX idx_inv_magasin (magasin_id),
            INDEX idx_inv_statut  (statut)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "CREATE TABLE inventaires");
} else {
    echo "  [SKIP] inventaires existe déjà\n";
}

if (!table_exists($pdo, 'inventaire_lignes')) {
    run_sql($pdo, "
        CREATE TABLE `inventaire_lignes` (
            id                 INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            inventaire_id      INT NOT NULL,
            article_id         INT NOT NULL,
            quantite_theorique INT NOT NULL DEFAULT 0,
            quantite_comptee   INT NOT NULL DEFAULT 0,
            ecart              INT NOT NULL DEFAULT 0,
            notes              VARCHAR(255) NULL,
            created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_invl_inventaire FOREIGN KEY (inventaire_id) REFERENCES inventaires(id) ON DELETE CASCADE,
            CONSTRAINT fk_invl_article    FOREIGN KEY (article_id)    REFERENCES articles(id)    ON DELETE RESTRICT,
            UNIQUE KEY uk_invl_art (inventaire_id, article_id),
            INDEX idx_invl_inventaire (inventaire_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "CREATE TABLE inventaire_lignes");
} else {
    echo "  [SKIP] inventaire_lignes existe déjà\n";
}

$pdo->exec("INSERT IGNORE INTO permissions (cle_permission, description, categorie) VALUES
    ('inventaire_gerer',    'Créer et valider des sessions d''inventaire physique', 'Logistique'),
    ('inventaire_consulter','Consulter les sessions d''inventaire physique',        'Logistique')");

$pdo->exec("INSERT IGNORE INTO role_permissions (role_nom, permission_id)
    SELECT r.role_nom, p.id FROM
    (SELECT 'Directeur' AS role_nom UNION SELECT 'Admin' UNION SELECT 'Magasinier') r
    CROSS JOIN permissions p
    WHERE p.cle_permission IN ('inventaire_gerer','inventaire_consulter')");

echo "  ✅ Permissions inventaire\n\n";

// ================================================================
// MIGRATION : retours.sql (Module 5)
// ================================================================
echo "[MIGRATION] retours.sql\n";

if (!table_exists($pdo, 'retours_factures')) {
    run_sql($pdo, "
        CREATE TABLE `retours_factures` (
            id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            numero_retour   VARCHAR(40) NOT NULL,
            facture_id      INT NOT NULL,
            magasin_id      INT NOT NULL DEFAULT 1,
            utilisateur_id  INT NULL,
            montant_total   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            motif           VARCHAR(255) NULL,
            date_retour     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            statut          ENUM('Valide','Annule') NOT NULL DEFAULT 'Valide',
            created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_ret_num (numero_retour),
            CONSTRAINT fk_ret_facture FOREIGN KEY (facture_id)     REFERENCES factures(id)     ON DELETE RESTRICT,
            CONSTRAINT fk_ret_magasin FOREIGN KEY (magasin_id)     REFERENCES magasins(id)     ON DELETE RESTRICT,
            CONSTRAINT fk_ret_user    FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE SET NULL,
            INDEX idx_ret_facture (facture_id),
            INDEX idx_ret_magasin (magasin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "CREATE TABLE retours_factures");
} else {
    echo "  [SKIP] retours_factures existe déjà\n";
}

if (!table_exists($pdo, 'lignes_retour')) {
    run_sql($pdo, "
        CREATE TABLE `lignes_retour` (
            id               INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            retour_id        INT NOT NULL,
            ligne_facture_id INT NOT NULL,
            article_id       INT NOT NULL,
            quantite         INT NOT NULL DEFAULT 1,
            prix_unitaire    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_lret_ret     FOREIGN KEY (retour_id)        REFERENCES retours_factures(id) ON DELETE CASCADE,
            CONSTRAINT fk_lret_lfact   FOREIGN KEY (ligne_facture_id) REFERENCES lignes_facture(id)   ON DELETE RESTRICT,
            CONSTRAINT fk_lret_article FOREIGN KEY (article_id)       REFERENCES articles(id)         ON DELETE RESTRICT,
            INDEX idx_lret_retour (retour_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "CREATE TABLE lignes_retour");
} else {
    echo "  [SKIP] lignes_retour existe déjà\n";
}

$pdo->exec("INSERT IGNORE INTO permissions (cle_permission, description, categorie) VALUES
    ('retours_gerer',    'Créer et traiter des retours articles SAV', 'Ventes'),
    ('retours_consulter','Consulter l''historique des retours',       'Ventes')");

$pdo->exec("INSERT IGNORE INTO role_permissions (role_nom, permission_id)
    SELECT r.role_nom, p.id FROM
    (SELECT 'Directeur' AS role_nom UNION SELECT 'Admin' UNION SELECT 'Vendeur') r
    CROSS JOIN permissions p
    WHERE p.cle_permission IN ('retours_gerer','retours_consulter')");

echo "  ✅ Permissions retours\n\n";

// ================================================================
// MIGRATION : promotions.sql (Module 6)
// ================================================================
echo "[MIGRATION] promotions.sql\n";

if (!table_exists($pdo, 'promotions')) {
    run_sql($pdo, "
        CREATE TABLE `promotions` (
            id                  INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            nom                 VARCHAR(150) NOT NULL,
            code_promo          VARCHAR(40) NULL,
            type_reduction      ENUM('pourcentage','montant_fixe') NOT NULL DEFAULT 'pourcentage',
            valeur              DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            article_id          INT NULL,
            categorie_id        INT NULL,
            montant_min_achat   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            date_debut          DATETIME NULL,
            date_fin            DATETIME NULL,
            limite_utilisations INT NULL,
            nb_utilisations     INT NOT NULL DEFAULT 0,
            actif               TINYINT(1) NOT NULL DEFAULT 1,
            created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_code_promo (code_promo),
            CONSTRAINT fk_promo_article   FOREIGN KEY (article_id)   REFERENCES articles(id)   ON DELETE SET NULL,
            CONSTRAINT fk_promo_categorie FOREIGN KEY (categorie_id) REFERENCES categories(id) ON DELETE SET NULL,
            INDEX idx_promo_code  (code_promo),
            INDEX idx_promo_dates (date_debut, date_fin),
            INDEX idx_promo_actif (actif)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", "CREATE TABLE promotions");
} else {
    echo "  [SKIP] promotions existe déjà\n";
}

$pdo->exec("INSERT IGNORE INTO permissions (cle_permission, description, categorie) VALUES
    ('promotions_gerer',    'Créer, modifier et désactiver des codes promo', 'Ventes'),
    ('promotions_consulter','Consulter les promotions et codes promo',       'Ventes')");

$pdo->exec("INSERT IGNORE INTO role_permissions (role_nom, permission_id)
    SELECT r.role_nom, p.id FROM
    (SELECT 'Directeur' AS role_nom UNION SELECT 'Admin' UNION SELECT 'Vendeur') r
    CROSS JOIN permissions p
    WHERE p.cle_permission IN ('promotions_gerer','promotions_consulter')");

echo "  ✅ Permissions promotions\n\n";

// ================================================================
// MIGRATION : securite_comp.sql (Module 10) — 2FA TOTP columns
// ================================================================
echo "[MIGRATION] securite_comp.sql\n";

if (!column_exists($pdo, 'utilisateurs', 'totp_secret')) {
    run_sql($pdo,
        "ALTER TABLE `utilisateurs` ADD COLUMN `totp_secret` VARCHAR(32) NULL AFTER `mot_de_passe`",
        "ADD COLUMN utilisateurs.totp_secret"
    );
} else {
    echo "  [SKIP] utilisateurs.totp_secret existe déjà\n";
}

if (!column_exists($pdo, 'utilisateurs', 'totp_actif')) {
    run_sql($pdo,
        "ALTER TABLE `utilisateurs` ADD COLUMN `totp_actif` TINYINT(1) NOT NULL DEFAULT 0 AFTER `totp_secret`",
        "ADD COLUMN utilisateurs.totp_actif"
    );
} else {
    echo "  [SKIP] utilisateurs.totp_actif existe déjà\n";
}
echo "\n";

// ================================================================
// VÉRIFICATION FINALE
// ================================================================
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo "=== Tables dans estock_db ===\n";
echo implode(", ", $tables) . "\n\n";
echo "=== Migration terminée ===\n";
