-- ============================================================
-- Module 3 : TVA Multiple
-- ============================================================

ALTER TABLE `articles` ADD COLUMN `taux_tva` DECIMAL(5,2) DEFAULT NULL AFTER `prix_vente`;
ALTER TABLE `lignes_facture` ADD COLUMN `taux_tva` DECIMAL(5,2) DEFAULT NULL AFTER `remise_pct`;
