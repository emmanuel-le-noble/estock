-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1:3306
-- Généré le : sam. 22 août 2026 à 19:16
-- Version du serveur : 8.0.31
-- Version de PHP : 8.2.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `estock_db`
--

-- --------------------------------------------------------

--
-- Structure de la table `archives_caisse`
--

DROP TABLE IF EXISTS `archives_caisse`;
CREATE TABLE IF NOT EXISTS `archives_caisse` (
  `id` int NOT NULL AUTO_INCREMENT,
  `magasin_id` int DEFAULT NULL,
  `periode_debut` date NOT NULL,
  `periode_fin` date NOT NULL,
  `nb_factures` int NOT NULL DEFAULT '0',
  `nb_paiements` int NOT NULL DEFAULT '0',
  `total_ht` decimal(14,2) NOT NULL DEFAULT '0.00',
  `total_tva` decimal(14,2) NOT NULL DEFAULT '0.00',
  `total_ttc` decimal(14,2) NOT NULL DEFAULT '0.00',
  `hash_sommet` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `signature` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fichier_archive` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_archivage` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `conserve_jusqua` date NOT NULL,
  `statut` enum('CONSERVE','EXPURGE') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CONSERVE',
  PRIMARY KEY (`id`),
  KEY `idx_arch_magasin` (`magasin_id`),
  KEY `idx_arch_periode` (`periode_debut`,`periode_fin`),
  KEY `idx_arch_statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `articles`
--

DROP TABLE IF EXISTS `articles`;
CREATE TABLE IF NOT EXISTS `articles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code_barre` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nom` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prix_achat` decimal(12,2) NOT NULL DEFAULT '0.00',
  `cump` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `prix_vente` decimal(12,2) NOT NULL DEFAULT '0.00',
  `unite_mesure` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UNITE',
  `vente_au_poids` tinyint(1) NOT NULL DEFAULT '0',
  `poids_precision` tinyint NOT NULL DEFAULT '3',
  `taux_tva` decimal(5,2) DEFAULT NULL,
  `quantite_stock` int NOT NULL DEFAULT '0',
  `valeur_stock` decimal(14,2) NOT NULL DEFAULT '0.00',
  `seuil_alerte` int NOT NULL DEFAULT '5',
  `emplacement` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fournisseur_id` int DEFAULT NULL,
  `categorie_id` int DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code_barre` (`code_barre`),
  KEY `idx_art_code` (`code_barre`),
  KEY `idx_art_nom` (`nom`),
  KEY `fk_art_fournisseur` (`fournisseur_id`),
  KEY `idx_art_low_stock` (`actif`,`quantite_stock`,`seuil_alerte`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `article_couts`
--

DROP TABLE IF EXISTS `article_couts`;
CREATE TABLE IF NOT EXISTS `article_couts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `article_id` int NOT NULL,
  `magasin_id` int NOT NULL DEFAULT '1',
  `quantite` int NOT NULL DEFAULT '0',
  `cout_unitaire` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `date_entree` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reference` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Référence d''entrée (commandes, mouvements)',
  PRIMARY KEY (`id`),
  KEY `idx_acc_article` (`article_id`,`magasin_id`),
  KEY `idx_acc_date` (`date_entree`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `article_lots`
--

DROP TABLE IF EXISTS `article_lots`;
CREATE TABLE IF NOT EXISTS `article_lots` (
  `id` int NOT NULL AUTO_INCREMENT,
  `article_id` int NOT NULL,
  `magasin_id` int NOT NULL,
  `numero_lot` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantite` int NOT NULL DEFAULT '0',
  `date_peremption` date DEFAULT NULL,
  `date_reception` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cree_le` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_lot_article_magasin` (`article_id`,`magasin_id`,`numero_lot`),
  KEY `idx_lot_fefo` (`article_id`,`magasin_id`,`date_peremption`,`quantite`),
  KEY `idx_lot_magasin` (`magasin_id`,`date_peremption`),
  KEY `idx_lot_article` (`article_id`,`magasin_id`),
  KEY `idx_lot_alerte` (`date_peremption`,`quantite`,`magasin_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `categories`
--

DROP TABLE IF EXISTS `categories`;
CREATE TABLE IF NOT EXISTS `categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `clients`
--

DROP TABLE IF EXISTS `clients`;
CREATE TABLE IF NOT EXISTS `clients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nom / prénom du client (B2C)',
  `raison_sociale` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Raison sociale (B2B)',
  `nif` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rccm` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `siret` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adresse` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telephone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_naissance` date DEFAULT NULL COMMENT 'Date de naissance (optionnel, fidélité)',
  `code_fidelite` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Code barre de la carte de fidélité',
  `points_fidelite` int NOT NULL DEFAULT '0',
  `consentement_fidelite` tinyint(1) NOT NULL DEFAULT '0',
  `date_consentement` datetime DEFAULT NULL,
  `source_consentement` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Où le consentement a été recueilli',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_dernier_achat` datetime DEFAULT NULL,
  `anonymise` tinyint(1) NOT NULL DEFAULT '0',
  `date_anonymisation` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_client_code_fidelite` (`code_fidelite`),
  KEY `idx_client_email` (`email`),
  KEY `idx_client_nom` (`nom`),
  KEY `idx_client_anonymise` (`anonymise`),
  KEY `idx_client_dernier_achat` (`date_dernier_achat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `clotures_caisse`
--

DROP TABLE IF EXISTS `clotures_caisse`;
CREATE TABLE IF NOT EXISTS `clotures_caisse` (
  `id` int NOT NULL AUTO_INCREMENT,
  `magasin_id` int NOT NULL,
  `utilisateur_id` int NOT NULL,
  `date_cloture` date NOT NULL,
  `montant_attendu` decimal(12,2) NOT NULL DEFAULT '0.00',
  `montant_reel` decimal(12,2) NOT NULL DEFAULT '0.00',
  `ecart` decimal(12,2) NOT NULL DEFAULT '0.00',
  `statut` enum('VALIDE','ANNULEE') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'VALIDE',
  `hash_chaine` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hash_chaine_precedent` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `empreinte_journal` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `horodatage_certifie` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cloture_jour` (`magasin_id`,`utilisateur_id`,`date_cloture`),
  KEY `fk_cloture_user` (`utilisateur_id`),
  KEY `idx_cloture_date` (`date_cloture`),
  KEY `idx_cloture_magasin` (`magasin_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


--
-- Déclencheurs `clotures_caisse`
--
DROP TRIGGER IF EXISTS `trg_clotures_immutable_delete`;
DELIMITER $$
CREATE TRIGGER `trg_clotures_immutable_delete` BEFORE DELETE ON `clotures_caisse` FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INTEGRITE: suppression physique de cloture interdite';
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trg_clotures_immutable_update`;
DELIMITER $$
CREATE TRIGGER `trg_clotures_immutable_update` BEFORE UPDATE ON `clotures_caisse` FOR EACH ROW BEGIN
    IF OLD.montant_attendu <> NEW.montant_attendu OR OLD.montant_reel <> NEW.montant_reel
       OR OLD.ecart <> NEW.ecart THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INTEGRITE: les montants d une cloture sont immuables';
    END IF;
    IF OLD.hash_chaine IS NOT NULL AND OLD.hash_chaine <> NEW.hash_chaine THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INTEGRITE: le hash de chaine d une cloture est immuable';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Structure de la table `commandes_fournisseur`
--

DROP TABLE IF EXISTS `commandes_fournisseur`;
CREATE TABLE IF NOT EXISTS `commandes_fournisseur` (
  `id` int NOT NULL AUTO_INCREMENT,
  `fournisseur_id` int NOT NULL,
  `magasin_id` int NOT NULL,
  `utilisateur_id` int NOT NULL,
  `statut` enum('Brouillon','En_Attente','Envoyee','Recue_Partielle','Recue','Annulee') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Brouillon',
  `date_commande` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `devise` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'XOF',
  `taux_change` decimal(12,6) NOT NULL DEFAULT '1.000000',
  `date_reception_prevue` date DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cf_fournisseur` (`fournisseur_id`),
  KEY `idx_cf_magasin` (`magasin_id`),
  KEY `idx_cf_user` (`utilisateur_id`),
  KEY `idx_cf_statut` (`statut`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `consentements_log`
--

DROP TABLE IF EXISTS `consentements_log`;
CREATE TABLE IF NOT EXISTS `consentements_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `type_consentement` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'fidelite / prospection',
  `consenti` tinyint(1) NOT NULL DEFAULT '1',
  `date_action` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_source` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `utilisateur_id` int DEFAULT NULL COMMENT 'Vendeur ayant recueilli le consentement',
  PRIMARY KEY (`id`),
  KEY `idx_cons_client` (`client_id`),
  KEY `idx_cons_date` (`date_action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `depenses`
--

DROP TABLE IF EXISTS `depenses`;
CREATE TABLE IF NOT EXISTS `depenses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `magasin_id` int DEFAULT NULL,
  `utilisateur_id` int DEFAULT NULL,
  `titre` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `categorie` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Autre',
  `montant` decimal(12,2) NOT NULL DEFAULT '0.00',
  `date_depense` date NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dep_magasin` (`magasin_id`),
  KEY `idx_dep_categorie` (`categorie`),
  KEY `idx_dep_date` (`date_depense`),
  KEY `fk_dep_user` (`utilisateur_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `emails_consentements`
--

DROP TABLE IF EXISTS `emails_consentements`;
CREATE TABLE IF NOT EXISTS `emails_consentements` (
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `opt_in_marketing` tinyint(1) NOT NULL DEFAULT '0',
  `date_consentement` datetime DEFAULT NULL,
  `source_consentement` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `opposition_globale` tinyint(1) NOT NULL DEFAULT '0',
  `date_opposition` datetime DEFAULT NULL,
  `token_desinscription` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`email`),
  UNIQUE KEY `uq_ec_token` (`token_desinscription`),
  KEY `idx_ec_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `emails_queue`
--

DROP TABLE IF EXISTS `emails_queue`;
CREATE TABLE IF NOT EXISTS `emails_queue` (
  `id` int NOT NULL AUTO_INCREMENT,
  `destinataire` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('transactionnel','alerte','marketing') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'alerte',
  `sujet` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `corps_html` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `statut` enum('EN_ATTENTE','ENVOYE','ECHEC') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'EN_ATTENTE',
  `nb_tentatives` tinyint NOT NULL DEFAULT '0',
  `prochaine_tentative` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `erreur` text COLLATE utf8mb4_unicode_ci,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_envoi` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_eq_statut` (`statut`,`prochaine_tentative`),
  KEY `idx_eq_date` (`date_creation`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `factures`
--

DROP TABLE IF EXISTS `factures`;
CREATE TABLE IF NOT EXISTS `factures` (
  `id` int NOT NULL AUTO_INCREMENT,
  `numero_facture` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_facture` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `utilisateur_id` int DEFAULT NULL,
  `magasin_id` int DEFAULT NULL,
  `cloture_id` int DEFAULT NULL,
  `client_sale_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_ht` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tva_taux` decimal(5,2) NOT NULL DEFAULT '0.00',
  `remise_fidelite` decimal(12,2) NOT NULL DEFAULT '0.00',
  `points_utilises` int NOT NULL DEFAULT '0',
  `total_ttc` decimal(12,2) NOT NULL DEFAULT '0.00',
  `montant_paye` decimal(12,2) NOT NULL DEFAULT '0.00',
  `monnaie_rendue` decimal(12,2) NOT NULL DEFAULT '0.00',
  `statut` enum('Payee','Annulee') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Payee',
  `statut_transmission` enum('non_transmise','transmise','non_applicable') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'non_transmise',
  `hash_chaine` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hash_chaine_precedent` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `horodatage_certifie` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_annulation` datetime DEFAULT NULL,
  `motif_annulation` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `annulee_par` int DEFAULT NULL,
  `client_id` int DEFAULT NULL,
  `client_raison_sociale` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_siret` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_adresse` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_nom` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero_facture` (`numero_facture`),
  UNIQUE KEY `idx_fact_client_sale` (`client_sale_id`),
  KEY `idx_fact_num` (`numero_facture`),
  KEY `idx_fact_date` (`date_facture`),
  KEY `fk_fact_user` (`utilisateur_id`),
  KEY `idx_fact_date_statut` (`date_facture`,`statut`),
  KEY `idx_fact_magasin` (`magasin_id`),
  KEY `idx_fact_cloture` (`cloture_id`),
  KEY `idx_fact_client` (`client_id`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déclencheurs `factures`
--
DROP TRIGGER IF EXISTS `trg_factures_immutable_delete`;
DELIMITER $$
CREATE TRIGGER `trg_factures_immutable_delete` BEFORE DELETE ON `factures` FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INTEGRITE: suppression physique de facture interdite';
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trg_factures_immutable_update`;
DELIMITER $$
CREATE TRIGGER `trg_factures_immutable_update` BEFORE UPDATE ON `factures` FOR EACH ROW BEGIN
    IF OLD.numero_facture <> NEW.numero_facture THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INTEGRITE: le numero de facture est immuable';
    END IF;
    IF OLD.total_ht <> NEW.total_ht OR OLD.total_ttc <> NEW.total_ttc
       OR OLD.montant_paye <> NEW.montant_paye OR OLD.monnaie_rendue <> NEW.monnaie_rendue THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INTEGRITE: les montants d une facture validee sont immuables';
    END IF;
    IF OLD.hash_chaine IS NOT NULL AND OLD.hash_chaine <> NEW.hash_chaine THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INTEGRITE: le hash de chaine d une facture est immuable';
    END IF;
    IF OLD.statut = 'Payee' AND NEW.statut NOT IN ('Payee', 'Annulee') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INTEGRITE: transition de statut invalide';
    END IF;
    IF OLD.statut = 'Annulee' AND NEW.statut = 'Payee' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INTEGRITE: une facture annulee est definitive';
    END IF;
    IF OLD.statut = 'Payee' AND NEW.statut = 'Annulee' AND NEW.date_annulation IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INTEGRITE: une annulation exige date et motif';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Structure de la table `fournisseurs`
--

DROP TABLE IF EXISTS `fournisseurs`;
CREATE TABLE IF NOT EXISTS `fournisseurs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telephone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `devise` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'XOF',
  PRIMARY KEY (`id`),
  KEY `idx_fournisseur_nom` (`nom`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `historique_points`
--

DROP TABLE IF EXISTS `historique_points`;
CREATE TABLE IF NOT EXISTS `historique_points` (
  `id` int NOT NULL AUTO_INCREMENT,
  `client_id` int NOT NULL,
  `facture_id` int DEFAULT NULL,
  `points` int NOT NULL COMMENT 'Positif = gain, négatif = utilisation',
  `type_operation` enum('GAIN','UTILISATION','EXPIRATION','AJUSTEMENT','ANNULE') COLLATE utf8mb4_unicode_ci NOT NULL,
  `commentaire` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_operation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `utilisateur_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_hp_client_facture_type` (`client_id`,`facture_id`,`type_operation`),
  KEY `idx_hp_client` (`client_id`),
  KEY `idx_hp_date` (`date_operation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `inventaires`
--

DROP TABLE IF EXISTS `inventaires`;
CREATE TABLE IF NOT EXISTS `inventaires` (
  `id` int NOT NULL AUTO_INCREMENT,
  `reference` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `magasin_id` int NOT NULL DEFAULT '1',
  `statut` enum('En cours','Validé','Annulé') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'En cours',
  `date_debut` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_fin` datetime DEFAULT NULL,
  `utilisateur_id` int DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_inv_ref` (`reference`),
  KEY `fk_inv_user` (`utilisateur_id`),
  KEY `idx_inv_magasin` (`magasin_id`),
  KEY `idx_inv_statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `inventaire_lignes`
--

DROP TABLE IF EXISTS `inventaire_lignes`;
CREATE TABLE IF NOT EXISTS `inventaire_lignes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `inventaire_id` int NOT NULL,
  `article_id` int NOT NULL,
  `quantite_theorique` int NOT NULL DEFAULT '0',
  `quantite_comptee` int NOT NULL DEFAULT '0',
  `ecart` int NOT NULL DEFAULT '0',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invl_art` (`inventaire_id`,`article_id`),
  KEY `fk_invl_article` (`article_id`),
  KEY `idx_invl_inventaire` (`inventaire_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `lignes_commande_fournisseur`
--

DROP TABLE IF EXISTS `lignes_commande_fournisseur`;
CREATE TABLE IF NOT EXISTS `lignes_commande_fournisseur` (
  `id` int NOT NULL AUTO_INCREMENT,
  `commande_id` int NOT NULL,
  `article_id` int NOT NULL,
  `quantite_commandee` int NOT NULL DEFAULT '1',
  `quantite_recue` int NOT NULL DEFAULT '0',
  `prix_achat_unitaire` decimal(12,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `idx_lcf_commande` (`commande_id`),
  KEY `idx_lcf_article` (`article_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `lignes_facture`
--

DROP TABLE IF EXISTS `lignes_facture`;
CREATE TABLE IF NOT EXISTS `lignes_facture` (
  `id` int NOT NULL AUTO_INCREMENT,
  `facture_id` int NOT NULL,
  `article_id` int NOT NULL,
  `quantite` int NOT NULL DEFAULT '1',
  `quantite_poids` decimal(10,3) DEFAULT NULL,
  `prix_unitaire` decimal(12,2) NOT NULL DEFAULT '0.00',
  `prix_original` decimal(12,2) DEFAULT NULL COMMENT 'Prix unitaire avant remise (null = pas de remise)',
  `remise_pct` decimal(5,2) DEFAULT NULL COMMENT 'Pourcentage de remise appliqué (ex: 30.00 = 30%)',
  `taux_tva` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lf_facture` (`facture_id`),
  KEY `fk_lf_article` (`article_id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déclencheurs `lignes_facture`
--
DROP TRIGGER IF EXISTS `trg_lignes_facture_immutable_delete`;
DELIMITER $$
CREATE TRIGGER `trg_lignes_facture_immutable_delete` BEFORE DELETE ON `lignes_facture` FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INTEGRITE: suppression physique de ligne de facture interdite';
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trg_lignes_facture_immutable_update`;
DELIMITER $$
CREATE TRIGGER `trg_lignes_facture_immutable_update` BEFORE UPDATE ON `lignes_facture` FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INTEGRITE: les lignes de facture sont immuables';
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Structure de la table `lignes_retour`
--

DROP TABLE IF EXISTS `lignes_retour`;
CREATE TABLE IF NOT EXISTS `lignes_retour` (
  `id` int NOT NULL AUTO_INCREMENT,
  `retour_id` int NOT NULL,
  `ligne_facture_id` int NOT NULL,
  `article_id` int NOT NULL,
  `quantite` int NOT NULL DEFAULT '1',
  `prix_unitaire` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_lret_lfact` (`ligne_facture_id`),
  KEY `fk_lret_article` (`article_id`),
  KEY `idx_lret_retour` (`retour_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `login` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_la_ip` (`ip_address`),
  KEY `idx_la_date` (`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `logs_activite`
--

DROP TABLE IF EXISTS `logs_activite`;
CREATE TABLE IF NOT EXISTS `logs_activite` (
  `id` int NOT NULL AUTO_INCREMENT,
  `utilisateur_id` int DEFAULT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_action` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_la_user` (`utilisateur_id`),
  KEY `idx_la_action` (`action`),
  KEY `idx_la_date` (`date_action`)
) ENGINE=InnoDB AUTO_INCREMENT=318 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `magasins`
--

DROP TABLE IF EXISTS `magasins`;
CREATE TABLE IF NOT EXISTS `magasins` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `adresse` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `code_postal` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nif` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rccm` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `mouvements_stock`
--

DROP TABLE IF EXISTS `mouvements_stock`;
CREATE TABLE IF NOT EXISTS `mouvements_stock` (
  `id` int NOT NULL AUTO_INCREMENT,
  `article_id` int NOT NULL,
  `utilisateur_id` int DEFAULT NULL,
  `magasin_id` int DEFAULT NULL,
  `type` enum('Entree','Sortie','Vente','Transfert','Ajustement','Retour_stock') COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantite` int NOT NULL DEFAULT '0',
  `date_mouvement` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `motif` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mvt_art` (`article_id`),
  KEY `idx_mvt_type` (`type`),
  KEY `idx_mvt_date` (`date_mouvement`),
  KEY `fk_mvt_user` (`utilisateur_id`),
  KEY `idx_mvt_magasin` (`magasin_id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `paiements_facture`
--

DROP TABLE IF EXISTS `paiements_facture`;
CREATE TABLE IF NOT EXISTS `paiements_facture` (
  `id` int NOT NULL AUTO_INCREMENT,
  `facture_id` int NOT NULL,
  `mode_paiement` enum('Especes','Mobile_Money','Carte_Bancaire','Virement','Autre') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Especes',
  `montant` decimal(12,2) NOT NULL DEFAULT '0.00',
  `reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_paiement` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pf_facture` (`facture_id`),
  KEY `idx_pf_mode` (`mode_paiement`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déclencheurs `paiements_facture`
--
DROP TRIGGER IF EXISTS `trg_paiements_immutable_delete`;
DELIMITER $$
CREATE TRIGGER `trg_paiements_immutable_delete` BEFORE DELETE ON `paiements_facture` FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INTEGRITE: suppression physique de paiement interdite';
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trg_paiements_immutable_update`;
DELIMITER $$
CREATE TRIGGER `trg_paiements_immutable_update` BEFORE UPDATE ON `paiements_facture` FOR EACH ROW BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'INTEGRITE: les paiements sont immuables';
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Structure de la table `parametres`
--

DROP TABLE IF EXISTS `parametres`;
CREATE TABLE IF NOT EXISTS `parametres` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cle` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `valeur` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `categorie` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `ordre` int NOT NULL DEFAULT '0',
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_modif` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cle` (`cle`),
  KEY `idx_par_cat` (`categorie`),
  KEY `idx_par_cle` (`cle`)
) ENGINE=InnoDB AUTO_INCREMENT=195 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Structure de la table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
CREATE TABLE IF NOT EXISTS `permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cle_permission` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `categorie` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  PRIMARY KEY (`id`),
  UNIQUE KEY `cle_permission` (`cle_permission`),
  KEY `idx_perm_cle` (`cle_permission`),
  KEY `idx_perm_cat` (`categorie`)
) ENGINE=InnoDB AUTO_INCREMENT=112 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `promotions`
--

DROP TABLE IF EXISTS `promotions`;
CREATE TABLE IF NOT EXISTS `promotions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_promo` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type_reduction` enum('pourcentage','montant_fixe') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pourcentage',
  `valeur` decimal(10,2) NOT NULL DEFAULT '0.00',
  `article_id` int DEFAULT NULL,
  `categorie_id` int DEFAULT NULL,
  `montant_min_achat` decimal(12,2) NOT NULL DEFAULT '0.00',
  `date_debut` datetime DEFAULT NULL,
  `date_fin` datetime DEFAULT NULL,
  `limite_utilisations` int DEFAULT NULL,
  `nb_utilisations` int NOT NULL DEFAULT '0',
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code_promo` (`code_promo`),
  KEY `fk_promo_article` (`article_id`),
  KEY `fk_promo_categorie` (`categorie_id`),
  KEY `idx_promo_code` (`code_promo`),
  KEY `idx_promo_dates` (`date_debut`,`date_fin`),
  KEY `idx_promo_actif` (`actif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `regles_promotions`
--

DROP TABLE IF EXISTS `regles_promotions`;
CREATE TABLE IF NOT EXISTS `regles_promotions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(200) COLLATE utf8mb4_general_ci NOT NULL,
  `condition_type` enum('PEREMPTION_PROCHE','SURSTOCK') COLLATE utf8mb4_general_ci NOT NULL,
  `jours_limite` int DEFAULT NULL COMMENT 'Pour PEREMPTION_PROCHE : jours max avant DLC',
  `seuil_stock` int DEFAULT NULL COMMENT 'Pour SURSTOCK : quantité minimum en stock',
  `pourcentage_remise` decimal(5,2) NOT NULL COMMENT 'Ex: 15.00 = 15%',
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `cree_le` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_promo_condition` (`condition_type`,`actif`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Structure de la table `retours_factures`
--

DROP TABLE IF EXISTS `retours_factures`;
CREATE TABLE IF NOT EXISTS `retours_factures` (
  `id` int NOT NULL AUTO_INCREMENT,
  `numero_retour` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `facture_id` int NOT NULL,
  `magasin_id` int NOT NULL DEFAULT '1',
  `utilisateur_id` int DEFAULT NULL,
  `montant_total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `motif` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_retour` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `statut` enum('Valide','Annule') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Valide',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ret_num` (`numero_retour`),
  KEY `fk_ret_user` (`utilisateur_id`),
  KEY `idx_ret_facture` (`facture_id`),
  KEY `idx_ret_magasin` (`magasin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_nom` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `permission_id` int NOT NULL,
  PRIMARY KEY (`role_nom`,`permission_id`),
  KEY `fk_rp_permission` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `sequences`
--

DROP TABLE IF EXISTS `sequences`;
CREATE TABLE IF NOT EXISTS `sequences` (
  `cle` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valeur` bigint NOT NULL DEFAULT '0',
  `date_maj` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `stock_magasins`
--

DROP TABLE IF EXISTS `stock_magasins`;
CREATE TABLE IF NOT EXISTS `stock_magasins` (
  `id` int NOT NULL AUTO_INCREMENT,
  `magasin_id` int NOT NULL,
  `article_id` int NOT NULL,
  `quantite` int NOT NULL DEFAULT '0',
  `valeur_stock` decimal(14,2) NOT NULL DEFAULT '0.00',
  `stock_alerte` int NOT NULL DEFAULT '5',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_stock_mag_art` (`magasin_id`,`article_id`),
  KEY `idx_sm_magasin` (`magasin_id`),
  KEY `idx_sm_article` (`article_id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `transferts_stock`
--

DROP TABLE IF EXISTS `transferts_stock`;
CREATE TABLE IF NOT EXISTS `transferts_stock` (
  `id` int NOT NULL AUTO_INCREMENT,
  `article_id` int NOT NULL,
  `magasin_source_id` int NOT NULL,
  `magasin_destination_id` int NOT NULL,
  `quantite` int NOT NULL,
  `utilisateur_id` int DEFAULT NULL,
  `motif` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_transfert` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tr_date` (`date_transfert`),
  KEY `idx_tr_art` (`article_id`),
  KEY `idx_tr_src` (`magasin_source_id`),
  KEY `idx_tr_dst` (`magasin_destination_id`),
  KEY `fk_tr_user` (`utilisateur_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `utilisateurs`
--

DROP TABLE IF EXISTS `utilisateurs`;
CREATE TABLE IF NOT EXISTS `utilisateurs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `login` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mot_de_passe` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `totp_secret` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `totp_actif` tinyint(1) NOT NULL DEFAULT '0',
  `google_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google_avatar` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('chef équipe','Admin','Magasinier','Vendeur') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Vendeur',
  `magasin_id` int DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `login` (`login`),
  KEY `idx_user_magasin` (`magasin_id`),
  KEY `idx_user_google_id` (`google_id`),
  UNIQUE KEY `idx_user_google_email` (`google_email`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `articles`
--
ALTER TABLE `articles`
  ADD CONSTRAINT `fk_art_fournisseur` FOREIGN KEY (`fournisseur_id`) REFERENCES `fournisseurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Contraintes pour la table `article_lots`
--
ALTER TABLE `article_lots`
  ADD CONSTRAINT `fk_lot_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lot_magasin` FOREIGN KEY (`magasin_id`) REFERENCES `magasins` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `clotures_caisse`
--
ALTER TABLE `clotures_caisse`
  ADD CONSTRAINT `fk_cloture_magasin` FOREIGN KEY (`magasin_id`) REFERENCES `magasins` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_cloture_user` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE RESTRICT;

--
-- Contraintes pour la table `depenses`
--
ALTER TABLE `depenses`
  ADD CONSTRAINT `fk_dep_magasin` FOREIGN KEY (`magasin_id`) REFERENCES `magasins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_dep_user` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Contraintes pour la table `factures`
--
ALTER TABLE `factures`
  ADD CONSTRAINT `fk_fact_magasin` FOREIGN KEY (`magasin_id`) REFERENCES `magasins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_fact_user` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_facture_cloture` FOREIGN KEY (`cloture_id`) REFERENCES `clotures_caisse` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `inventaires`
--
ALTER TABLE `inventaires`
  ADD CONSTRAINT `fk_inv_magasin` FOREIGN KEY (`magasin_id`) REFERENCES `magasins` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_inv_user` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `inventaire_lignes`
--
ALTER TABLE `inventaire_lignes`
  ADD CONSTRAINT `fk_invl_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_invl_inventaire` FOREIGN KEY (`inventaire_id`) REFERENCES `inventaires` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `lignes_commande_fournisseur`
--
ALTER TABLE `lignes_commande_fournisseur`
  ADD CONSTRAINT `fk_lcf_commande` FOREIGN KEY (`commande_id`) REFERENCES `commandes_fournisseur` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `lignes_facture`
--
ALTER TABLE `lignes_facture`
  ADD CONSTRAINT `fk_lf_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lf_facture` FOREIGN KEY (`facture_id`) REFERENCES `factures` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `lignes_retour`
--
ALTER TABLE `lignes_retour`
  ADD CONSTRAINT `fk_lret_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_lret_lfact` FOREIGN KEY (`ligne_facture_id`) REFERENCES `lignes_facture` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_lret_ret` FOREIGN KEY (`retour_id`) REFERENCES `retours_factures` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `logs_activite`
--
ALTER TABLE `logs_activite`
  ADD CONSTRAINT `fk_la_user` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Contraintes pour la table `mouvements_stock`
--
ALTER TABLE `mouvements_stock`
  ADD CONSTRAINT `fk_mvt_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_mvt_magasin` FOREIGN KEY (`magasin_id`) REFERENCES `magasins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_mvt_user` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Contraintes pour la table `paiements_facture`
--
ALTER TABLE `paiements_facture`
  ADD CONSTRAINT `fk_pf_facture` FOREIGN KEY (`facture_id`) REFERENCES `factures` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `promotions`
--
ALTER TABLE `promotions`
  ADD CONSTRAINT `fk_promo_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_promo_categorie` FOREIGN KEY (`categorie_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `retours_factures`
--
ALTER TABLE `retours_factures`
  ADD CONSTRAINT `fk_ret_facture` FOREIGN KEY (`facture_id`) REFERENCES `factures` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_ret_magasin` FOREIGN KEY (`magasin_id`) REFERENCES `magasins` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_ret_user` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `stock_magasins`
--
ALTER TABLE `stock_magasins`
  ADD CONSTRAINT `fk_sm_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sm_magasin` FOREIGN KEY (`magasin_id`) REFERENCES `magasins` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `transferts_stock`
--
ALTER TABLE `transferts_stock`
  ADD CONSTRAINT `fk_tr_art` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_tr_dst` FOREIGN KEY (`magasin_destination_id`) REFERENCES `magasins` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_tr_src` FOREIGN KEY (`magasin_source_id`) REFERENCES `magasins` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_tr_user` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Contraintes pour la table `utilisateurs`
--
ALTER TABLE `utilisateurs`
  ADD CONSTRAINT `fk_user_magasin` FOREIGN KEY (`magasin_id`) REFERENCES `magasins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
