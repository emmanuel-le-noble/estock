-- ============================================================
-- Module 5 : Retours & avoirs (SAV)
-- À exécuter APRÈS le schéma de base eSotck.
-- ============================================================

CREATE TABLE IF NOT EXISTS retours_factures (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero_retour   VARCHAR(40)  NOT NULL UNIQUE,          -- ex : RET-2025-0001
    facture_id      INT UNSIGNED NOT NULL,
    magasin_id      INT UNSIGNED NOT NULL DEFAULT 1,
    utilisateur_id  INT UNSIGNED NULL,                     -- agent ayant effectué le retour
    montant_total   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    motif           VARCHAR(255)  NULL,
    date_retour     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    statut          ENUM('Valide','Annule') NOT NULL DEFAULT 'Valide',
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ret_facture FOREIGN KEY (facture_id) REFERENCES factures(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ret_magasin FOREIGN KEY (magasin_id)  REFERENCES magasins(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ret_user    FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs(id) ON DELETE SET NULL,
    INDEX idx_ret_facture (facture_id),
    INDEX idx_ret_magasin (magasin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lignes_retour (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    retour_id        INT UNSIGNED NOT NULL,
    ligne_facture_id INT UNSIGNED NOT NULL,
    article_id       INT UNSIGNED NOT NULL,
    quantite         INT          NOT NULL DEFAULT 1,
    prix_unitaire    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_lret_ret     FOREIGN KEY (retour_id)        REFERENCES retours_factures(id) ON DELETE CASCADE,
    CONSTRAINT fk_lret_lfact   FOREIGN KEY (ligne_facture_id) REFERENCES lignes_facture(id)   ON DELETE RESTRICT,
    CONSTRAINT fk_lret_article FOREIGN KEY (article_id)       REFERENCES articles(id)         ON DELETE RESTRICT,
    INDEX idx_lret_retour (retour_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions SAV
INSERT IGNORE INTO permissions (cle_permission, description, categorie)
VALUES
    ('retours_gerer',    'Créer et traiter des retours d\'articles et avoirs SAV', 'Ventes'),
    ('retours_consulter','Consulter l\'historique des retours d\'articles',       'Ventes');

-- Mapping rôles -> permissions retours
INSERT IGNORE INTO role_permissions (role_nom, permission_id)
SELECT r.role_nom, p.id
FROM (
    SELECT 'Directeur'  AS role_nom UNION
    SELECT 'Admin'      AS role_nom UNION
    SELECT 'Vendeur'    AS role_nom
) r
CROSS JOIN permissions p
WHERE p.cle_permission IN ('retours_gerer', 'retours_consulter');
