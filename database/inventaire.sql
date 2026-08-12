-- ============================================================
-- Module 4 : Inventaire physique (Comptage cyclique)
-- ============================================================

CREATE TABLE IF NOT EXISTS `inventaires` (
    id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reference       VARCHAR(40)  NOT NULL UNIQUE,
    magasin_id      INT NOT NULL DEFAULT 1,
    statut          ENUM('En cours','Validé','Annulé') NOT NULL DEFAULT 'En cours',
    date_debut      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_fin        DATETIME NULL,
    utilisateur_id  INT NULL,
    notes           TEXT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inv_magasin  FOREIGN KEY (magasin_id)     REFERENCES magasins(id)    ON DELETE RESTRICT,
    CONSTRAINT fk_inv_user     FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    INDEX idx_inv_magasin (magasin_id),
    INDEX idx_inv_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventaire_lignes` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions RBAC inventaire
INSERT IGNORE INTO permissions (cle_permission, description, categorie) VALUES
    ('inventaire_gerer',    'Créer et valider des sessions d\'inventaire physique', 'Logistique'),
    ('inventaire_consulter','Consulter les sessions d\'inventaire physique',        'Logistique');

INSERT IGNORE INTO role_permissions (role_nom, permission_id)
SELECT r.role_nom, p.id
FROM (
    SELECT 'Directeur'  AS role_nom UNION
    SELECT 'Admin'      AS role_nom UNION
    SELECT 'Magasinier' AS role_nom
) r
CROSS JOIN permissions p
WHERE p.cle_permission IN ('inventaire_gerer', 'inventaire_consulter');
