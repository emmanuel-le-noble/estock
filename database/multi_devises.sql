-- ============================================================
-- multi_devises.sql — Devises fournisseurs & commandes
--
--   * fournisseurs.devise : devise de facturation du fournisseur
--   * commandes_fournisseur.devise + taux_change : conversion en
--     devise boutique à la réception (comptabilité)
--
-- ATTENTION : idempotence garantie par database/apply_migrations.php.
-- ============================================================

ALTER TABLE `fournisseurs`
    ADD COLUMN `devise` VARCHAR(3) NOT NULL DEFAULT 'XOF' AFTER `telephone`;

ALTER TABLE `commandes_fournisseur`
    ADD COLUMN `devise`      VARCHAR(3)     NOT NULL DEFAULT 'XOF' AFTER `date_commande`,
    ADD COLUMN `taux_change` DECIMAL(12,6)  NOT NULL DEFAULT 1.000000 AFTER `devise`;