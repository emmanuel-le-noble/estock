-- ============================================================
-- fidelite.sql — Programme de fidélité (points + réductions)
--
--  * Attribution de points à la validation de facture (1 nb_points
--    par devise_entière, paramétrable).
--  * Historique immuable des opérations de points.
--  * Réduction fidélité appliquable en caisse.
--
-- Idempotent : CREATE TABLE IF NOT EXISTS.
-- ============================================================

-- Historique des opérations de points (traçabilité RGPD + comptable)
CREATE TABLE IF NOT EXISTS `historique_points` (
    `id`             INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `client_id`      INT           NOT NULL,
    `facture_id`     INT           NULL,
    `points`         INT           NOT NULL COMMENT 'Positif = gain, négatif = utilisation',
    `type_operation` ENUM('GAIN','UTILISATION','EXPIRATION','AJUSTEMENT','ANNULE') NOT NULL,
    `commentaire`    VARCHAR(255)  NULL,
    `date_operation` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `utilisateur_id` INT           NULL,
    KEY `idx_hp_client` (`client_id`),
    UNIQUE KEY `uk_hp_facture` (`facture_id`) COMMENT 'Idempotence : 1 facture = 1 attribution de points',
    KEY `idx_hp_date` (`date_operation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;