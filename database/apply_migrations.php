<?php
/**
 * apply_migrations.php — Application des migrations BDD eStock (version robuste).
 *
 * Gère les instructions multi-lignes, les triggers BEGIN…END (via
 * database/sql_splitter.php) et les PREPARE/EXECUTE via PHP natif.
 *
 * Usage :
 *   php database/apply_migrations.php
 *
 * Toutes les migrations sont IDEMPOTENTES : elles peuvent être relancées
 * sans risque sur une base déjà à jour.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Ce script doit être exécuté en ligne de commande (CLI).\n");
}

require_once __DIR__ . '/../config/connexion.php';
require_once __DIR__ . '/sql_splitter.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    exit("Erreur : connexion PDO indisponible.\n");
}

echo "=== Application des migrations BDD eStock (" . DB_NAME . ") ===\n\n";

// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------
function run_sql(PDO $pdo, string $sql, string $label): void {
    $sql = trim($sql);
    if ($sql === '') return;
    try {
        $pdo->exec($sql);
        echo "  OK $label\n";
    } catch (\PDOException $e) {
        $msg = $e->getMessage();
        if (
            str_contains($msg, 'Duplicate column name') ||
            str_contains($msg, 'already exists')        ||
            str_contains($msg, 'Duplicate entry')       ||
            str_contains($msg, 'Duplicate key name')    ||
            str_contains($msg, 'check that column/key exists')
        ) {
            echo "  [SKIP déjà appliqué] $label\n";
        } else {
            echo "  ERREUR ($label): $msg\n";
        }
    }
}

/** Applique un fichier .sql idempotent (découpage correct des triggers). */
function apply_sql_file(PDO $pdo, string $file, string $label): int {
    if (!is_file($file)) {
        echo "  [ABSENT] $label ($file)\n";
        return 0;
    }
    $sql = file_get_contents($file);
    $stmts = split_sql_statements($sql);
    $ok = 0;
    foreach ($stmts as $stmt) {
        try {
            $pdo->exec($stmt);
            $ok++;
        } catch (\PDOException $e) {
            $msg = $e->getMessage();
            if (
                str_contains($msg, 'Duplicate column name') ||
                str_contains($msg, 'already exists')        ||
                str_contains($msg, 'Duplicate entry')       ||
                str_contains($msg, 'Duplicate key name')    ||
                str_contains($msg, 'check that column/key exists') ||
                str_contains($msg, 'error in your SQL syntax')
            ) {
                echo "  [SKIP ou INSTRUCTION NON APPLICABLE] $label : " . substr($msg, 0, 120) . "\n";
            } else {
                echo "  ERREUR ($label) : " . $msg . "\n";
            }
        }
    }
    echo "  -> $label : $ok instruction(s) OK\n";
    return $ok;
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

function trigger_exists(PDO $pdo, string $trigger): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.TRIGGERS
        WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?
    ");
    $stmt->execute([$trigger]);
    return (int)$stmt->fetchColumn() > 0;
}

function seed_params(PDO $pdo, array $params): void {
    $stmt = $pdo->prepare(
        "INSERT INTO parametres (cle, valeur, categorie, ordre, description)
         VALUES (:cle, :val, :cat, :ordre, :desc)
         ON DUPLICATE KEY UPDATE categorie = VALUES(categorie), description = VALUES(description)"
    );
    $ordre = 1000;
    foreach ($params as $cle => $spec) {
        $stmt->execute([
            ':cle'   => $cle,
            ':val'   => $spec['valeur'],
            ':cat'   => $spec['categorie'],
            ':ordre' => $spec['ordre'] ?? $ordre++,
            ':desc'  => $spec['description'] ?? '',
        ]);
    }
    echo "  OK parametres seedés\n";
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

echo "  OK Permissions inventaire\n\n";

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

echo "  OK Permissions retours\n\n";

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

echo "  OK Permissions promotions\n\n";

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
// MIGRATION : workflow_validation_achats — Statut En_Attente + approbation Directeur
// ================================================================
echo "[MIGRATION] workflow_validation_achats\n";

if (table_exists($pdo, 'commandes_fournisseur')) {
    $stmt = $pdo->query("SHOW COLUMNS FROM commandes_fournisseur LIKE 'statut'");
    $col = $stmt->fetch();
    if ($col && !str_contains((string)$col['Type'], 'En_Attente')) {
        run_sql($pdo,
            "ALTER TABLE `commandes_fournisseur` MODIFY `statut` ENUM('Brouillon','En_Attente','Envoyee','Recue_Partielle','Recue','Annulee') NOT NULL DEFAULT 'Brouillon'",
            "ALTER commandes_fournisseur.statut + En_Attente"
        );
    } else {
        echo "  [SKIP] commandes_fournisseur.statut contient déjà En_Attente\n";
    }
} else {
    echo "  [SKIP] table commandes_fournisseur absente (fichier commandes_fournisseur.sql requis)\n";
}

$pdo->exec("INSERT IGNORE INTO permissions (cle_permission, description, categorie) VALUES
    ('achats_valider', 'Valider ou rejeter les commandes fournisseurs soumises (approbation)', 'Logistique')");

$pdo->exec("INSERT IGNORE INTO role_permissions (role_nom, permission_id)
    SELECT 'Directeur', id FROM permissions WHERE cle_permission = 'achats_valider'");

echo "  OK Permission achats_valider (Directeur)\n\n";

// ================================================================
// MIGRATION : compteurs_sequences.sql — numérotation atomique NF525
// ================================================================
echo "[MIGRATION] compteurs_sequences.sql\n";
apply_sql_file($pdo, __DIR__ . '/compteurs_sequences.sql', 'compteurs_sequences');
echo "\n";

// ================================================================
// MIGRATION : nf525.sql — certification anti-fraude TVA
// ================================================================
echo "[MIGRATION] nf525.sql\n";
apply_sql_file($pdo, __DIR__ . '/nf525.sql', 'nf525');

// Paramètres liés à la conformité
seed_params($pdo, [
    'facture_prefixe'             => ['valeur' => 'FAC', 'categorie' => 'financier', 'ordre' => 90,  'description' => 'Préfixe des numéros de facture'],
    'facture_mentions_penalites'  => ['valeur' => '1',  'categorie' => 'boutique',  'ordre' => 90,  'description' => 'Afficher les mentions de pénalités de retard sur la facture PDF'],
    'tva_intracom_boutique'       => ['valeur' => '',   'categorie' => 'boutique',  'ordre' => 95,  'description' => 'Numéro de TVA intracommunautaire de la boutique'],
    'siret_boutique'              => ['valeur' => '',   'categorie' => 'boutique',  'ordre' => 100, 'description' => 'SIRET de la boutique (facturation B2B)'],
    'archives_conservation_annees'=> ['valeur' => '6',  'categorie' => 'financier', 'ordre' => 100, 'description' => 'Durée de conservation des archives de caisse (années)'],
]);
echo "\n";

// ================================================================
// MIGRATION : rgpd.sql — conformité RGPD (clients, consentements)
// ================================================================
echo "[MIGRATION] rgpd.sql\n";
apply_sql_file($pdo, __DIR__ . '/rgpd.sql', 'rgpd');

seed_params($pdo, [
    'rgpd_retention_clients_mois' => ['valeur' => '36', 'categorie' => 'financier', 'ordre' => 110, 'description' => 'Durée de rétention des données clients sans activité (mois, puis anonymisation)'],
    'rgpd_contact_email'          => ['valeur' => '',   'categorie' => 'financier', 'ordre' => 111, 'description' => 'Contact RGPD / délégué à la protection des données'],
]);
echo "\n";

// ================================================================
// MIGRATION : fidelite.sql — programme de fidélité
// ================================================================
echo "[MIGRATION] fidelite.sql\n";
apply_sql_file($pdo, __DIR__ . '/fidelite.sql', 'fidelite');

seed_params($pdo, [
    'fidelite_actif'             => ['valeur' => '0', 'categorie' => 'financier', 'ordre' => 120, 'description' => 'Activer le programme de fidélité'],
    'fidelite_points_par_devise' => ['valeur' => '100', 'categorie' => 'financier', 'ordre' => 121, 'description' => 'Unités de devise dépensées pour gagner 1 point (ex : 100 = 1 pt / 100 FCFA)'],
    'fidelite_valeur_point'      => ['valeur' => '1', 'categorie' => 'financier', 'ordre' => 122, 'description' => 'Valeur en devise d\'un point (1 point = montant × 100)'],
    'fidelite_min_points_usage'  => ['valeur' => '10', 'categorie' => 'financier', 'ordre' => 123, 'description' => 'Seuil minimal de points pour utiliser une réduction fidélité'],
]);
echo "\n";

// ================================================================
// MIGRATION : points_reversals.sql — cycle de vie des points fidélité
// ================================================================
echo "[MIGRATION] points_reversals.sql\n";
apply_sql_file($pdo, __DIR__ . '/points_reversals.sql', 'points_reversals');
echo "\n";

// ================================================================
// MIGRATION : unite_mesure.sql — vente au poids
// ================================================================
echo "[MIGRATION] unite_mesure.sql\n";
apply_sql_file($pdo, __DIR__ . '/unite_mesure.sql', 'unite_mesure');
echo "\n";

// ================================================================
// MIGRATION : valorisation_stock.sql — CUMP / FIFO
// ================================================================
echo "[MIGRATION] valorisation_stock.sql\n";
apply_sql_file($pdo, __DIR__ . '/valorisation_stock.sql', 'valorisation_stock');

seed_params($pdo, [
    'valorisation_methode' => ['valeur' => 'CUMP', 'categorie' => 'financier', 'ordre' => 130, 'description' => 'Méthode de valorisation du stock : CUMP ou FIFO'],
]);
echo "\n";

// ================================================================
// MIGRATION : multi_devises.sql — devises fournisseurs
// ================================================================
echo "[MIGRATION] multi_devises.sql\n";
apply_sql_file($pdo, __DIR__ . '/multi_devises.sql', 'multi_devises');
echo "\n";

// ================================================================
// MIGRATION : emails_queue.sql — file d'attente des e-mails
// ================================================================
echo "[MIGRATION] emails_queue.sql\n";
apply_sql_file($pdo, __DIR__ . '/emails_queue.sql', 'emails_queue');

// ================================================================
// MIGRATION : permissions conformité (archives, clients, export FEC)
// ================================================================
echo "[MIGRATION] permissions_conformite\n";

$pdo->exec("INSERT IGNORE INTO permissions (cle_permission, description, categorie) VALUES
    ('conformite_archives',  'Générer et consulter les archives de caisse (NF525)', 'config'),
    ('conformite_export_fec', 'Exporter les écritures comptables (FEC)',            'config'),
    ('clients_consulter',    'Consulter les clients (fidélité, RGPD)',              'ventes'),
    ('clients_gerer',        'Créer / modifier les clients et consentements',       'ventes')");

$pdo->exec("INSERT IGNORE INTO role_permissions (role_nom, permission_id)
    SELECT r.role_nom, p.id FROM
    (SELECT 'Directeur' AS role_nom UNION SELECT 'Admin') r
    CROSS JOIN permissions p
    WHERE p.cle_permission IN ('conformite_archives','conformite_export_fec','clients_consulter','clients_gerer')");

$pdo->exec("INSERT IGNORE INTO role_permissions (role_nom, permission_id)
    SELECT 'Vendeur', id FROM permissions WHERE cle_permission IN ('clients_consulter','clients_gerer')");

echo "  OK Permissions conformité & clients\n\n";

// ================================================================
// VÉRIFICATION FINALE
// ================================================================
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo "=== Tables dans " . DB_NAME . " ===\n";
echo implode(", ", $tables) . "\n\n";
echo "=== Migration terminée ===\n";