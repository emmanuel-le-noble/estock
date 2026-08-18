-- ============================================================
-- donnees_clients.sql — Protection des données clients
--
-- Bonnes pratiques de protection des données personnelles :
--
--  1. Table `clients` : données des clients avec consentement
--     explicite pour le programme de fidélité (B2C) et
--     identification fiscale B2B (nif, rccm ajoutés par
--     conformite_togo.sql).
--  2. Champs d'anonymisation : les données comptables étant
--     conservées (obligation fiscale), l'effacement est réalisé
--     par ANONYMISATION et non par suppression.
--  3. Champs de purge automatique (politique de conservation).
--
-- ATTENTION : idempotence garantie par database/apply_migrations.php.
-- ============================================================

CREATE TABLE IF NOT EXISTS `clients` (
    `id`                    INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `nom`                   VARCHAR(150)  NULL COMMENT 'Nom / prénom du client (B2C)',
    `raison_sociale`        VARCHAR(200)  NULL COMMENT 'Raison sociale (B2B)',
    `siret`                 VARCHAR(20)   NULL,
    `adresse`               VARCHAR(255)  NULL,
    `telephone`             VARCHAR(30)   NULL,
    `email`                 VARCHAR(150)  NULL,
    `date_naissance`        DATE          NULL COMMENT 'Date de naissance (optionnel, fidélité)',
    `code_fidelite`         VARCHAR(40)   NULL COMMENT 'Code barre de la carte de fidélité',
    `points_fidelite`       INT           NOT NULL DEFAULT 0,
    -- Consentement programme de fidélité
    `consentement_fidelite` TINYINT(1)    NOT NULL DEFAULT 0,
    `date_consentement`     DATETIME      NULL,
    `source_consentement`   VARCHAR(100)  NULL COMMENT 'Où le consentement a été recueilli',
    `date_creation`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_dernier_achat`    DATETIME      NULL,
    -- Anonymisation (sans rupture des écritures comptables)
    `anonymise`             TINYINT(1)    NOT NULL DEFAULT 0,
    `date_anonymisation`    DATETIME      NULL,
    UNIQUE KEY `uk_client_code_fidelite` (`code_fidelite`),
    KEY `idx_client_email` (`email`),
    KEY `idx_client_nom` (`nom`),
    KEY `idx_client_anonymise` (`anonymise`),
    KEY `idx_client_dernier_achat` (`date_dernier_achat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registre des consentements (traçabilité)
CREATE TABLE IF NOT EXISTS `consentements_log` (
    `id`                 INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `client_id`          INT          NOT NULL,
    `type_consentement`  VARCHAR(50)  NOT NULL COMMENT 'fidelite / prospection',
    `consenti`           TINYINT(1)   NOT NULL DEFAULT 1,
    `date_action`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip_source`          VARCHAR(45)  NULL,
    `utilisateur_id`     INT          NULL COMMENT 'Vendeur ayant recueilli le consentement',
    KEY `idx_cons_client` (`client_id`),
    KEY `idx_cons_date` (`date_action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;