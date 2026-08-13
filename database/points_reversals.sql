-- ============================================================
-- points_reversals.sql — Cycle de vie complet des points fidélité
--
--  1. Clé unique composite (client_id, facture_id, type_operation) :
--     - GAIN  : 1 attribution par facture (idempotent, uk existante préservée)
--     - ANNULE: 1 annulation/retour par facture (sans collision avec le GAIN)
--     - UTILISATION : facture_id NULL, plusieurs par client autorisés
--  2. Traçabilité de la remise fidélité sur la facture :
--     - remise_fidelite : montant TTC déduit (points convertis)
--     - points_utilises : nombre de points consommés (pour restitution lors
--       d'une annulation ou d'un retour)
--
-- Idempotent via database/apply_migrations.php (erreurs traitée en SKIP).
-- ============================================================

ALTER TABLE `historique_points` DROP INDEX `uk_hp_facture`;
ALTER TABLE `historique_points` ADD UNIQUE KEY `uk_hp_client_facture_type` (`client_id`, `facture_id`, `type_operation`);

ALTER TABLE `factures` ADD COLUMN `remise_fidelite` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `tva_taux`;
ALTER TABLE `factures` ADD COLUMN `points_utilises` INT NOT NULL DEFAULT 0 AFTER `remise_fidelite`;