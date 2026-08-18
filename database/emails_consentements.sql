-- ============================================================
-- emails_consentements.sql — File d'attente e-mails : consentement
--
-- Règles de bonnes pratiques pour la prospection par e-mail :
--   * La prospection directe par e-mail exige un CONSENTEMENT
--     PRÉALABLE (opt-in) du destinataire.
--   * Droit d'opposition : lien de désinscription fonctionnel
--     dans chaque e-mail de prospection.
--   * Limitation de la conservation : purge des e-mails traités
--     au-delà du délai paramétré.
--   * Droit à l'effacement : suppression des données d'un
--     destinataire (file + consentements).
--   * Tokens de désinscription aléatoires non devinables, aucune
--     donnée sensible en base d'erreurs.
--
-- ATTENTION : idempotence garantie par database/apply_migrations.php.
-- ============================================================

-- Type d'e-mail : détermine le régime de consentement applicable.
--   * 'transactionnel' : confirmations, solde de compte (pas de prospection)
--   * 'alerte'         : notifications de gestion internes
--   * 'marketing'      : PROSPECTION DIRECTE → opt-in obligatoire
ALTER TABLE `emails_queue`
    ADD COLUMN `type` ENUM('transactionnel','alerte','marketing')
        NOT NULL DEFAULT 'alerte' AFTER `destinataire`;

-- Consentements e-mail (traçabilité prouvée du consentement / de l'opposition)
CREATE TABLE IF NOT EXISTS `emails_consentements` (
    `email`               VARCHAR(190) NOT NULL PRIMARY KEY,
    `opt_in_marketing`    TINYINT(1)   NOT NULL DEFAULT 0,
    `date_consentement`   DATETIME     NULL,
    `source_consentement` VARCHAR(100) NOT NULL DEFAULT '',
    `opposition_globale`  TINYINT(1)   NOT NULL DEFAULT 0,
    `date_opposition`     DATETIME     NULL,
    `token_desinscription` CHAR(64)    NOT NULL,
    `date_creation`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_ec_token` (`token_desinscription`),
    KEY `idx_ec_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;