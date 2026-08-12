-- ============================================================
-- Module 10 : Sécurité complémentaire (2FA TOTP)
-- MySQL 8.0 : ALTER TABLE ... ADD COLUMN IF NOT EXISTS n'est pas supporté.
-- On utilise une approche conditionnelle via INFORMATION_SCHEMA.
-- ============================================================

SET @dbname = DATABASE();

-- Ajouter totp_secret si absent
SET @col1 = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'utilisateurs' AND COLUMN_NAME = 'totp_secret'
);
SET @sql1 = IF(@col1 = 0,
    'ALTER TABLE `utilisateurs` ADD COLUMN `totp_secret` VARCHAR(32) NULL AFTER `mot_de_passe`',
    'SELECT 1'
);
PREPARE stmt1 FROM @sql1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

-- Ajouter totp_actif si absent
SET @col2 = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'utilisateurs' AND COLUMN_NAME = 'totp_actif'
);
SET @sql2 = IF(@col2 = 0,
    'ALTER TABLE `utilisateurs` ADD COLUMN `totp_actif` TINYINT(1) NOT NULL DEFAULT 0 AFTER `totp_secret`',
    'SELECT 1'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
