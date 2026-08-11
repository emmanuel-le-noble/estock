-- database/tva_multiple.sql
-- Migration Module 3 : TVA Multiple par article et par ligne de facture

-- Colonne taux_tva sur articles (si NULL, utilise le taux global de parametres.php)
SET @dbname = DATABASE();
SET @tablename = "articles";
SET @columnname = "taux_tva";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname
  ) > 0,
  "SELECT 1",
  "ALTER TABLE articles ADD COLUMN taux_tva DECIMAL(5,2) DEFAULT NULL AFTER prix_vente;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Colonne taux_tva sur lignes_facture pour conservation du taux à la vente
SET @tablename = "lignes_facture";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname
  ) > 0,
  "SELECT 1",
  "ALTER TABLE lignes_facture ADD COLUMN taux_tva DECIMAL(5,2) DEFAULT NULL AFTER remise_pct;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
