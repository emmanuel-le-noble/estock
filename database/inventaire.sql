-- ============================================================
-- Module 4 : Inventaire physique (comptage cyclique)
-- À exécuter APRÈS le schéma de base eSotck.
-- ============================================================

-- Sessions d'inventaire (une session = un comptage complet ou partiel)
CREATE TABLE IF NOT EXISTS inventaires (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference       VARCHAR(40)  NOT NULL UNIQUE,          -- ex : INV-2025-01
    magasin_id      INT UNSIGNED NOT NULL DEFAULT 1,
    statut          ENUM('En cours','Validé','Annulé') NOT NULL DEFAULT 'En cours',
    date_debut      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_fin        DATETIME     NULL,
    utilisateur_id  INT UNSIGNED NULL,                     -- créateur
    notes           TEXT         NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inv_magasin FOREIGN KEY (magasin_id)    REFERENCES magasins(id) ON DELETE RESTRICT,
    CONSTRAINT fk_inv_user    FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    INDEX idx_inv_statut (statut),
    INDEX idx_inv_magasin (magasin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lignes de comptage (une ligne par article scanné / compté)
CREATE TABLE IF NOT EXISTS inventaire_lignes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventaire_id   INT UNSIGNED NOT NULL,
    article_id      INT UNSIGNED NOT NULL,
    quantite_theorique INT       NOT NULL DEFAULT 0,   -- stock système au moment du comptage
    quantite_comptee   INT       NOT NULL DEFAULT 0,   -- quantité physiquement comptée
    ecart           INT          GENERATED ALWAYS AS (quantite_comptee - quantite_theorique) STORED,
    notes           VARCHAR(255) NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_invl_inv     FOREIGN KEY (inventaire_id) REFERENCES inventaires(id)  ON DELETE CASCADE,
    CONSTRAINT fk_invl_article FOREIGN KEY (article_id)    REFERENCES articles(id)     ON DELETE RESTRICT,
    UNIQUE KEY uq_inv_article (inventaire_id, article_id),
    INDEX idx_invl_article (article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions inventaire
INSERT IGNORE INTO permissions (cle_permission, description, categorie)
VALUES
    ('inventaire_gerer',    'Créer, modifier et valider un inventaire physique', 'Stock'),
    ('inventaire_consulter','Consulter les inventaires existants',                'Stock');

-- Mapping rôles -> permissions inventaire
INSERT IGNORE INTO role_permissions (role_nom, permission_id)
SELECT r.role_nom, p.id
FROM (
    SELECT 'Directeur'  AS role_nom UNION
    SELECT 'Admin'      AS role_nom UNION
    SELECT 'Magasinier' AS role_nom
) r
CROSS JOIN permissions p
WHERE p.cle_permission IN ('inventaire_gerer', 'inventaire_consulter');
