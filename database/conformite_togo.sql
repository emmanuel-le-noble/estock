-- ============================================================
-- conformite_togo.sql — Conformité légale et fiscale togolaise (OTR)
--
--   * NIF (Numéro d'Identification Fiscale) et RCCM (Registre du
--     Commerce et du Crédit Mobilier) : identifiants exigés par l'OTR
--     sur les factures (boutique, magasins, clients B2B).
--   * Régime fiscal flexible : TVA classique (18 % par défaut) ou
--     TPU (Taxe Professionnelle Unique) — factures hors taxes avec
--     mention « TVA non applicable ».
--   * statut_transmission : préparation à la facturation électronique
--     OTR (non_transmise / transmise / non_applicable) — inactif,
--     prêt pour un futur connecteur.
--
-- ATTENTION : idempotence garantie par database/apply_migrations.php.
-- ============================================================

-- ============================================================
-- 1. IDENTIFICATION FISCALE
-- ============================================================

-- Magasins : identifiants fiscaux de chaque point de vente
ALTER TABLE `magasins`
    ADD COLUMN `nif`  VARCHAR(30) NULL DEFAULT NULL AFTER `code_postal`,
    ADD COLUMN `rccm` VARCHAR(30) NULL DEFAULT NULL AFTER `nif`;

-- Clients B2B : identification fiscale (remplace l'usage du SIRET)
ALTER TABLE `clients`
    ADD COLUMN `nif`  VARCHAR(30) NULL DEFAULT NULL AFTER `raison_sociale`,
    ADD COLUMN `rccm` VARCHAR(30) NULL DEFAULT NULL AFTER `nif`;

-- Factures : statut de transmission OTR (prêt pour e-facturation)
ALTER TABLE `factures`
    ADD COLUMN `statut_transmission` ENUM('non_transmise','transmise','non_applicable')
        NOT NULL DEFAULT 'non_transmise' AFTER `statut`;