-- ============================================================
-- unite_mesure.sql — Vente au poids / unités de mesure
--
--   * unite_mesure : unité de vente de l'article
--     (UNITE, KILOGRAMME, GRAMME, LITRE, MILLILITRE, METRE, ...)
--   * vente_au_poids : mode de saisie poids en caisse
--   * poids_precision : nombre de décimales autorisées (3 = gramme)
--
-- ATTENTION : idempotence garantie par database/apply_migrations.php.
-- ============================================================

ALTER TABLE `articles`
    ADD COLUMN `unite_mesure`    VARCHAR(20)  NOT NULL DEFAULT 'UNITE' AFTER `prix_vente`,
    ADD COLUMN `vente_au_poids`  TINYINT(1)   NOT NULL DEFAULT 0 AFTER `unite_mesure`,
    ADD COLUMN `poids_precision` TINYINT      NOT NULL DEFAULT 3 AFTER `vente_au_poids`;

-- Champs quantités : la vente au poids travaille côté stock en
-- grammes entiers internes (1 kg = 1000) ; la colonne ci-dessous
-- conserve la quantité pesée affichée sur la facture.
ALTER TABLE `lignes_facture`
    ADD COLUMN `quantite_poids` DECIMAL(10,3) NULL AFTER `quantite`;