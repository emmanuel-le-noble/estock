-- ============================================================
-- emails_queue.sql — File d'attente des e-mails (Partie 4)
--
-- Les e-mails ne bloquent plus la requête utilisateur : ils sont
-- insérés ici puis envoyés par le worker CLI (bin/emails_worker.php).
--
-- Idempotent : CREATE TABLE IF NOT EXISTS.
-- ============================================================

CREATE TABLE IF NOT EXISTS `emails_queue` (
    `id`               INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `destinataire`     VARCHAR(190)  NOT NULL,
    `sujet`            VARCHAR(190)  NOT NULL,
    `corps_html`       TEXT          NOT NULL,
    `statut`           ENUM('EN_ATTENTE','ENVOYE','ECHEC') NOT NULL DEFAULT 'EN_ATTENTE',
    `nb_tentatives`    TINYINT       NOT NULL DEFAULT 0,
    `prochaine_tentative` DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `erreur`           TEXT          NULL,
    `date_creation`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_envoi`       DATETIME      NULL,
    KEY `idx_eq_statut` (`statut`, `prochaine_tentative`),
    KEY `idx_eq_date` (`date_creation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;