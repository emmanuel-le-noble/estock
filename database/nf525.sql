-- ============================================================
-- nf525.sql — Certification anti-fraude TVA (loi 2016-1918, art. 88 —
-- arrêté du 20/10/2016, norme NF 525)
--
--  1. Chaînage cryptographique des factures (hash incluant le hash
--     de la facture précédente) + horodatage certifié (UTC ISO 8601).
--  2. Trace d'annulation (soft-delete) : jamais de suppression physique.
--  3. Journal des clôtures de caisse chaîné et immuable.
--  4. Archivage périodique obligatoire (export signé, ≥ 6 ans).
--  5. Triggers MySQL interdisant toute modification ou suppression
--     de facture validée (défense en profondeur au niveau SGBD).
--
-- ATTENTION : les instructions sont IDEMPOTENTES uniquement via
-- database/apply_migrations.php (vérifications column_exists /
-- table_exists / trigger_exists). L'import manuel doit se faire
-- sur une base qui n'a pas encore appliqué ces migrations, ou via
-- le script (recommandé).
-- ============================================================

-- ------------------------------------------------------------
-- 1. Chaînage des factures + données B2B client
-- ------------------------------------------------------------
ALTER TABLE `factures`
    ADD COLUMN `hash_chaine`            VARCHAR(64)  NULL AFTER `statut`,
    ADD COLUMN `hash_chaine_precedent`  VARCHAR(64)  NULL AFTER `hash_chaine`,
    ADD COLUMN `horodatage_certifie`    VARCHAR(32)  NULL AFTER `hash_chaine_precedent`;

-- Trace d'annulation (soft-delete)
ALTER TABLE `factures`
    ADD COLUMN `date_annulation`    DATETIME     NULL AFTER `horodatage_certifie`,
    ADD COLUMN `motif_annulation`   VARCHAR(255) NULL AFTER `date_annulation`,
    ADD COLUMN `annulee_par`        INT          NULL AFTER `motif_annulation`;

-- Informations client (facturation B2B légale + RGPD)
ALTER TABLE `factures`
    ADD COLUMN `client_id`             INT           NULL AFTER `annulee_par`,
    ADD COLUMN `client_raison_sociale` VARCHAR(200)  NULL AFTER `client_id`,
    ADD COLUMN `client_siret`          VARCHAR(20)   NULL AFTER `client_raison_sociale`,
    ADD COLUMN `client_adresse`        VARCHAR(255)  NULL AFTER `client_siret`,
    ADD COLUMN `client_nom`            VARCHAR(150)  NULL AFTER `client_adresse`;

ALTER TABLE `factures` ADD KEY `idx_fact_client` (`client_id`);

-- ------------------------------------------------------------
-- 2. Journal des clôtures de caisse (chaîné + trace)
-- ------------------------------------------------------------
ALTER TABLE `clotures_caisse`
    ADD COLUMN `hash_chaine`           VARCHAR(64) NULL AFTER `statut`,
    ADD COLUMN `hash_chaine_precedent` VARCHAR(64) NULL AFTER `hash_chaine`,
    ADD COLUMN `empreinte_journal`     VARCHAR(64) NULL AFTER `hash_chaine_precedent`,
    ADD COLUMN `horodatage_certifie`   VARCHAR(32) NULL AFTER `empreinte_journal`;

-- ------------------------------------------------------------
-- 3. Archivage périodique des données de caisse (NF525 §archivage)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `archives_caisse` (
    `id`              INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `magasin_id`      INT           NULL,
    `periode_debut`   DATE          NOT NULL,
    `periode_fin`     DATE          NOT NULL,
    `nb_factures`     INT           NOT NULL DEFAULT 0,
    `nb_paiements`    INT           NOT NULL DEFAULT 0,
    `total_ht`        DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `total_tva`       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `total_ttc`       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `hash_sommet`     VARCHAR(64)   NOT NULL,
    `signature`       VARCHAR(128)  NOT NULL,
    `fichier_archive` VARCHAR(255)  NULL,
    `date_archivage`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `conserve_jusqua` DATE          NOT NULL,
    `statut`          ENUM('CONSERVE','EXPURGE') NOT NULL DEFAULT 'CONSERVE',
    KEY `idx_arch_magasin` (`magasin_id`),
    KEY `idx_arch_periode` (`periode_debut`, `periode_fin`),
    KEY `idx_arch_statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. Triggers d'inaltérabilité (défense au niveau SGBD)
-- ------------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_factures_immutable_update`;
CREATE TRIGGER `trg_factures_immutable_update` BEFORE UPDATE ON `factures` FOR EACH ROW
BEGIN
    IF OLD.numero_facture <> NEW.numero_facture THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NF525: le numero de facture est immuable';
    END IF;
    IF OLD.total_ht <> NEW.total_ht OR OLD.total_ttc <> NEW.total_ttc
       OR OLD.montant_paye <> NEW.montant_paye OR OLD.monnaie_rendue <> NEW.monnaie_rendue THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NF525: les montants d une facture validee sont immuables';
    END IF;
    IF OLD.hash_chaine IS NOT NULL AND OLD.hash_chaine <> NEW.hash_chaine THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NF525: le hash de chaine d une facture est immuable';
    END IF;
    IF OLD.statut = 'Payee' AND NEW.statut NOT IN ('Payee', 'Annulee') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NF525: transition de statut invalide';
    END IF;
    IF OLD.statut = 'Annulee' AND NEW.statut = 'Payee' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NF525: une facture annulee est definitive';
    END IF;
    IF OLD.statut = 'Payee' AND NEW.statut = 'Annulee' AND NEW.date_annulation IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NF525: une annulation exige date et motif';
    END IF;
END;

DROP TRIGGER IF EXISTS `trg_factures_immutable_delete`;
CREATE TRIGGER `trg_factures_immutable_delete` BEFORE DELETE ON `factures` FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NF525: suppression physique de facture interdite';
END;

-- Lignes de facture : aucune modification / suppression physique
DROP TRIGGER IF EXISTS `trg_lignes_facture_immutable_update`;
CREATE TRIGGER `trg_lignes_facture_immutable_update` BEFORE UPDATE ON `lignes_facture` FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NF525: les lignes de facture sont immuables';
END;

DROP TRIGGER IF EXISTS `trg_lignes_facture_immutable_delete`;
CREATE TRIGGER `trg_lignes_facture_immutable_delete` BEFORE DELETE ON `lignes_facture` FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NF525: suppression physique de ligne de facture interdite';
END;

-- Paiements : aucune modification / suppression physique
DROP TRIGGER IF EXISTS `trg_paiements_immutable_update`;
CREATE TRIGGER `trg_paiements_immutable_update` BEFORE UPDATE ON `paiements_facture` FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NF525: les paiements sont immuables';
END;

DROP TRIGGER IF EXISTS `trg_paiements_immutable_delete`;
CREATE TRIGGER `trg_paiements_immutable_delete` BEFORE DELETE ON `paiements_facture` FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NF525: suppression physique de paiement interdite';
END;

-- Clôtures de caisse : montants et hash immuables
DROP TRIGGER IF EXISTS `trg_clotures_immutable_update`;
CREATE TRIGGER `trg_clotures_immutable_update` BEFORE UPDATE ON `clotures_caisse` FOR EACH ROW
BEGIN
    IF OLD.montant_attendu <> NEW.montant_attendu OR OLD.montant_reel <> NEW.montant_reel
       OR OLD.ecart <> NEW.ecart THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NF525: les montants d une cloture sont immuables';
    END IF;
    IF OLD.hash_chaine IS NOT NULL AND OLD.hash_chaine <> NEW.hash_chaine THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NF525: le hash de chaine d une cloture est immuable';
    END IF;
END;

DROP TRIGGER IF EXISTS `trg_clotures_immutable_delete`;
CREATE TRIGGER `trg_clotures_immutable_delete` BEFORE DELETE ON `clotures_caisse` FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NF525: suppression physique de cloture interdite';
END;