-- ============================================================
-- Module 6 : Remises et promotions (Codes promo & Remises ciblées)
-- À exécuter APRÈS le schéma de base eSotck.
-- ============================================================

CREATE TABLE IF NOT EXISTS promotions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nom                 VARCHAR(150) NOT NULL,
    code_promo          VARCHAR(40)  NULL UNIQUE,           -- ex : SUMMER20, NOEL500 (null si promo auto)
    type_reduction      ENUM('pourcentage','montant_fixe') NOT NULL DEFAULT 'pourcentage',
    valeur              DECIMAL(10,2) NOT NULL,              -- ex : 20.00 (20%) ou 500.00 (500 FCFA)
    article_id          INT UNSIGNED NULL,                  -- optionnel : restreint à un article
    categorie_id        INT UNSIGNED NULL,                  -- optionnel : restreint à une catégorie
    montant_min_achat   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    date_debut          DATETIME     NULL,
    date_fin            DATETIME     NULL,
    limite_utilisations INT UNSIGNED NULL,                  -- null = illimité
    nb_utilisations     INT UNSIGNED NOT NULL DEFAULT 0,
    actif               TINYINT(1)   NOT NULL DEFAULT 1,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_promo_article   FOREIGN KEY (article_id)   REFERENCES articles(id)   ON DELETE SET NULL,
    CONSTRAINT fk_promo_categorie FOREIGN KEY (categorie_id) REFERENCES categories(id) ON DELETE SET NULL,
    INDEX idx_promo_code (code_promo),
    INDEX idx_promo_dates (date_debut, date_fin),
    INDEX idx_promo_actif (actif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions promotions
INSERT IGNORE INTO permissions (cle_permission, description, categorie)
VALUES
    ('promotions_gerer',    'Créer, modifier et désactiver des codes promo et promotions', 'Ventes'),
    ('promotions_consulter','Consulter les règles de promotions et codes promo',            'Ventes');

-- Mapping rôles -> permissions promotions
INSERT IGNORE INTO role_permissions (role_nom, permission_id)
SELECT r.role_nom, p.id
FROM (
    SELECT 'Directeur' AS role_nom UNION
    SELECT 'Admin'     AS role_nom UNION
    SELECT 'Vendeur'   AS role_nom
) r
CROSS JOIN permissions p
WHERE p.cle_permission IN ('promotions_gerer', 'promotions_consulter');
