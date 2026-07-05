-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1:3306
-- Généré le : ven. 03 juil. 2026 à 15:16
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
-- Structure de la table `articles`
--

DROP TABLE IF EXISTS `articles`;
CREATE TABLE IF NOT EXISTS `articles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code_barre` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nom` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prix_achat` decimal(12,2) NOT NULL DEFAULT '0.00',
  `prix_vente` decimal(12,2) NOT NULL DEFAULT '0.00',
  `quantite_stock` int NOT NULL DEFAULT '0',
  `seuil_alerte` int NOT NULL DEFAULT '5',
  `emplacement` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fournisseur_id` int DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code_barre` (`code_barre`),
  KEY `idx_art_code` (`code_barre`),
  KEY `idx_art_nom` (`nom`),
  KEY `fk_art_fournisseur` (`fournisseur_id`),
  KEY `idx_art_low_stock` (`actif`,`quantite_stock`,`seuil_alerte`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `articles`
--

INSERT INTO `articles` (`id`, `code_barre`, `nom`, `sku`, `prix_achat`, `prix_vente`, `quantite_stock`, `seuil_alerte`, `emplacement`, `fournisseur_id`, `actif`, `date_creation`) VALUES
(1, '3017620422003', 'Nutella 400g', 'NUT400', 2099.00, 2952.00, 23, 5, 'A1-01', 1, 1, '2026-07-01 16:51:22'),
(2, '3033710065967', 'Eau de source 1.5L', 'EAU15', 230.00, 558.00, 117, 20, 'B2-05', 2, 1, '2026-07-01 16:51:22'),
(3, '3168930009870', 'Café moulu 250g', 'CAF250', 1837.00, 2755.00, 9, 10, 'A1-02', 1, 1, '2026-07-01 16:51:22'),
(4, '3401535600125', 'Savon de Marseille', 'SAV250', 722.00, 1279.00, 34, 5, 'C3-10', 3, 1, '2026-07-01 16:51:22'),
(5, '3338075100011', 'Jus d\'orange 1L', 'JUS1L', 984.00, 1574.00, 2, 10, 'B2-06', 2, 1, '2026-07-01 16:51:22');

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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `article_lots`
--

INSERT INTO `article_lots` (`id`, `article_id`, `magasin_id`, `numero_lot`, `quantite`, `date_peremption`, `date_reception`, `cree_le`) VALUES
(1, 5, 1, 'LOT-2026-ABC', 5, '2027-12-03', '2026-07-03 03:23:59', '2026-07-03 03:23:59'),
(2, 1, 1, 'LOT-2025-ABC', 0, '2026-08-02', '2026-07-03 03:25:58', '2026-07-03 03:25:58');

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
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cloture_jour` (`magasin_id`,`utilisateur_id`,`date_cloture`),
  KEY `fk_cloture_user` (`utilisateur_id`),
  KEY `idx_cloture_date` (`date_cloture`),
  KEY `idx_cloture_magasin` (`magasin_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `clotures_caisse`
--

INSERT INTO `clotures_caisse` (`id`, `magasin_id`, `utilisateur_id`, `date_cloture`, `montant_attendu`, `montant_reel`, `ecart`, `statut`, `date_creation`) VALUES
(9, 1, 1, '2026-07-03', 3069.60, 3070.00, 0.40, 'VALIDE', '2026-07-03 01:30:25');

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

--
-- Déchargement des données de la table `depenses`
--

INSERT INTO `depenses` (`id`, `magasin_id`, `utilisateur_id`, `titre`, `categorie`, `montant`, `date_depense`, `description`, `date_creation`) VALUES
(1, 1, 1, 'Facture électricité', 'Électricité', 15000.00, '2026-06-05', 'payement de facture d\'électricité', '2026-07-03 04:30:21');

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
  `total_ttc` decimal(12,2) NOT NULL DEFAULT '0.00',
  `montant_paye` decimal(12,2) NOT NULL DEFAULT '0.00',
  `monnaie_rendue` decimal(12,2) NOT NULL DEFAULT '0.00',
  `statut` enum('Payee','Annulee') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Payee',
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero_facture` (`numero_facture`),
  UNIQUE KEY `idx_fact_client_sale` (`client_sale_id`),
  KEY `idx_fact_num` (`numero_facture`),
  KEY `idx_fact_date` (`date_facture`),
  KEY `fk_fact_user` (`utilisateur_id`),
  KEY `idx_fact_date_statut` (`date_facture`,`statut`),
  KEY `idx_fact_magasin` (`magasin_id`),
  KEY `idx_fact_cloture` (`cloture_id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `factures`
--

INSERT INTO `factures` (`id`, `numero_facture`, `date_facture`, `utilisateur_id`, `magasin_id`, `cloture_id`, `client_sale_id`, `total_ht`, `tva_taux`, `total_ttc`, `montant_paye`, `monnaie_rendue`, `statut`) VALUES
(1, 'FAC-20260701-0001', '2026-07-01 16:53:42', 1, NULL, NULL, NULL, 2952.00, 20.00, 3542.00, 13119.00, 9577.00, 'Payee'),
(2, 'FAC-20260702-0001', '2026-07-02 19:37:35', 1, NULL, NULL, NULL, 5510.00, 20.00, 6612.00, 16399.00, 9787.00, 'Payee'),
(3, 'FAC-20260702-0002', '2026-07-02 20:06:57', 1, NULL, NULL, NULL, 2853.00, 20.00, 3424.00, 3424.00, 0.00, 'Payee'),
(4, 'FAC-20260702-0003', '2026-07-02 20:14:29', 1, NULL, NULL, NULL, 1673.00, 20.00, 2007.00, 2007.00, 0.00, 'Payee'),
(7, 'FAC-20260703-0001', '2026-07-03 01:18:00', 1, 1, 9, NULL, 2558.00, 20.00, 3069.60, 5000.00, 1930.40, 'Payee'),
(12, 'FAC-20260703-0002', '2026-07-03 01:34:38', 3, 1, NULL, NULL, 5510.00, 20.00, 6612.00, 6612.00, 0.00, 'Payee'),
(13, 'FAC-20260703-0003', '2026-07-03 03:43:10', 3, 1, NULL, NULL, 2804.40, 20.00, 3365.28, 5000.00, 1634.72, 'Payee'),
(14, 'FAC-20260703-0004', '2026-07-03 04:26:41', 3, 1, NULL, NULL, 5608.80, 20.00, 6730.56, 10000.00, 3269.44, 'Payee'),
(15, 'FAC-20260703-0005', '2026-07-03 04:27:42', 3, 1, NULL, NULL, 2804.40, 20.00, 3365.28, 5000.00, 1634.72, 'Payee');

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
  PRIMARY KEY (`id`),
  KEY `idx_fournisseur_nom` (`nom`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `fournisseurs`
--

INSERT INTO `fournisseurs` (`id`, `nom`, `contact`, `telephone`) VALUES
(1, 'GrosDistribution SARL', 'M. Dupont', '+33 1 23 45 67 89'),
(2, 'TechImport', 'Mme Martin', '+33 1 98 76 54 32'),
(3, 'AgroFrais', 'M. Bernard', '+228 94 56 78 90 12');

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
  `prix_unitaire` decimal(12,2) NOT NULL DEFAULT '0.00',
  `prix_original` decimal(12,2) DEFAULT NULL COMMENT 'Prix unitaire avant remise (null = pas de remise)',
  `remise_pct` decimal(5,2) DEFAULT NULL COMMENT 'Pourcentage de remise appliqué (ex: 30.00 = 30%)',
  PRIMARY KEY (`id`),
  KEY `idx_lf_facture` (`facture_id`),
  KEY `fk_lf_article` (`article_id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `lignes_facture`
--

INSERT INTO `lignes_facture` (`id`, `facture_id`, `article_id`, `quantite`, `prix_unitaire`, `prix_original`, `remise_pct`) VALUES
(1, 1, 1, 1, 2952.00, NULL, NULL),
(2, 2, 3, 2, 2755.00, NULL, NULL),
(3, 3, 4, 1, 1279.00, NULL, NULL),
(4, 3, 5, 1, 1574.00, NULL, NULL),
(5, 4, 2, 3, 558.00, NULL, NULL),
(6, 7, 4, 2, 1279.00, NULL, NULL),
(8, 12, 3, 2, 2755.00, NULL, NULL),
(9, 13, 1, 1, 2804.40, NULL, NULL),
(10, 14, 1, 2, 2804.40, 2952.00, 5.00),
(11, 15, 1, 1, 2804.40, 2952.00, 5.00);

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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `login_attempts`
--

INSERT INTO `login_attempts` (`id`, `ip_address`, `login`, `attempted_at`) VALUES
(1, '::1', 'magasinier', '2026-07-02 21:09:30');

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
) ENGINE=InnoDB AUTO_INCREMENT=74 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `logs_activite`
--

INSERT INTO `logs_activite` (`id`, `utilisateur_id`, `action`, `details`, `ip_address`, `date_action`) VALUES
(1, 1, 'MOUVEMENT_STOCK', 'Entree de 3 unité(s) — Café moulu 250g', '::1', '2026-07-02 21:58:48'),
(2, 1, 'DECONNEXION', 'Déconnexion', '::1', '2026-07-02 22:19:40'),
(3, 2, 'CONNEXION', 'Connexion réussie: magasin', '::1', '2026-07-02 22:19:56'),
(4, 2, 'DECONNEXION', 'Déconnexion', '::1', '2026-07-02 22:25:44'),
(5, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-02 22:25:51'),
(6, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-02 23:01:19'),
(7, 1, 'MODIFICATION_PARAMETRES', 'Modification des paramètres boutique', '::1', '2026-07-03 00:15:36'),
(8, 1, 'MODIFICATION_PARAMETRES', 'Modification des paramètres boutique', '::1', '2026-07-03 00:18:35'),
(9, 1, 'VENTE', 'Facture FAC-20260703-0001 validée — 1 ligne(s), TTC=3 069,60', '::1', '2026-07-03 01:18:00'),
(10, 1, 'DECONNEXION', 'Déconnexion', '::1', '2026-07-03 01:19:21'),
(11, 3, 'CONNEXION', 'Connexion réussie: vendeur', '::1', '2026-07-03 01:19:59'),
(12, 3, 'VENTE', 'Facture FAC-20260703-0002 validée — 1 ligne(s), TTC=3 306,00', '::1', '2026-07-03 01:21:07'),
(13, 3, 'DECONNEXION', 'Déconnexion', '::1', '2026-07-03 01:27:39'),
(14, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-03 01:27:44'),
(15, 1, 'ECART_CAISSE', 'Écart de caisse détecté pour l\'utilisateur Administrateur . Attendu : 3 069,60, Réel : 3 070,00, Écart : +0,40', '::1', '2026-07-03 01:30:25'),
(16, 1, 'CLOTURE_CAISSE', 'Clôture de caisse validée — Montant attendu: 3 069,60, Réel: 3 070,00', '::1', '2026-07-03 01:30:25'),
(17, 1, 'DECONNEXION', 'Déconnexion', '::1', '2026-07-03 01:33:41'),
(18, 3, 'CONNEXION', 'Connexion réussie: vendeur', '::1', '2026-07-03 01:33:58'),
(19, 3, 'VENTE', 'Facture FAC-20260703-0002 validée — 1 ligne(s), TTC=6 612,00', '::1', '2026-07-03 01:34:38'),
(20, 3, 'DECONNEXION', 'Déconnexion', '::1', '2026-07-03 01:43:34'),
(21, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-03 01:43:40'),
(22, 1, 'DECONNEXION', 'Déconnexion', '::1', '2026-07-03 01:44:42'),
(23, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-03 01:44:46'),
(24, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-03 01:45:18'),
(25, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-03 02:39:48'),
(26, 1, 'LOT_AJOUTE', 'Lot LOT-2026-ABC — Qté 5 — DLC 2027-12-03', '::1', '2026-07-03 03:23:59'),
(27, 1, 'LOT_AJOUTE', 'Lot LOT-2025-ABC — Qté 3 — DLC 2026-08-02', '::1', '2026-07-03 03:25:58'),
(28, 1, 'DECONNEXION', 'Déconnexion', '::1', '2026-07-03 03:41:00'),
(29, 3, 'CONNEXION', 'Connexion réussie: vendeur', '::1', '2026-07-03 03:41:19'),
(30, 3, 'VENTE', 'Facture FAC-20260703-0003 validée — 1 ligne(s), TTC=3 365,28', '::1', '2026-07-03 03:43:10'),
(31, 3, 'DECONNEXION', 'Déconnexion', '::1', '2026-07-03 03:44:02'),
(32, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-03 03:44:07'),
(33, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-03 04:05:59'),
(34, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-03 04:23:00'),
(35, 1, 'DECONNEXION', 'Déconnexion', '::1', '2026-07-03 04:25:10'),
(36, 3, 'CONNEXION', 'Connexion réussie: vendeur', '::1', '2026-07-03 04:25:28'),
(37, 3, 'SORTIE_STOCK_LOT', 'Vente FAC-20260703-0004 : -2 unité(s) du lot LOT-2025-ABC (DLC: 2026-08-02)', '::1', '2026-07-03 04:26:41'),
(38, 3, 'VENTE', 'Facture FAC-20260703-0004 validée — 1 ligne(s), TTC=6 730,56', '::1', '2026-07-03 04:26:41'),
(39, 3, 'SORTIE_STOCK_LOT', 'Vente FAC-20260703-0005 : -1 unité(s) du lot LOT-2025-ABC (DLC: 2026-08-02)', '::1', '2026-07-03 04:27:42'),
(40, 3, 'VENTE', 'Facture FAC-20260703-0005 validée — 1 ligne(s), TTC=3 365,28', '::1', '2026-07-03 04:27:42'),
(41, 3, 'DECONNEXION', 'Déconnexion', '::1', '2026-07-03 04:28:11'),
(42, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-03 04:28:15'),
(43, 1, 'DEPENSE_ENREGISTREE', 'Électricité — Facture électricité : 15 000,00 FCFA', '::1', '2026-07-03 04:30:21'),
(44, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-03 04:44:52'),
(45, 1, 'DEPENSE_ENREGISTREE', 'Électricité — Facture électricité : 15 000,00 FCFA', '::1', '2026-07-03 04:45:50'),
(46, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-03 04:47:44'),
(47, 1, 'DECONNEXION', 'Déconnexion', '::1', '2026-07-03 04:58:27'),
(48, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-03 04:58:34'),
(49, 1, 'MODIFICATION_PARAMETRES', 'Modification des paramètres boutique', '::1', '2026-07-03 05:06:12'),
(50, 1, 'MODIFICATION_PARAMETRES', 'Modification des paramètres boutique', '::1', '2026-07-03 05:06:49'),
(51, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-03 05:23:31'),
(52, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-03 11:37:36'),
(53, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-03 11:44:16'),
(54, 1, 'DECONNEXION', 'Déconnexion', '::1', '2026-07-03 11:52:26'),
(55, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-03 11:52:47'),
(56, 1, 'MODIFICATION_PARAMETRES', 'Modification des paramètres boutique', '::1', '2026-07-03 12:01:59'),
(57, 1, 'MODIFICATION_PARAMETRES', 'Modification des paramètres boutique', '::1', '2026-07-03 12:02:23'),
(58, 1, 'MODIFICATION_PARAMETRES', 'Modification des paramètres boutique', '::1', '2026-07-03 12:02:39'),
(59, 1, 'MODIFICATION_PARAMETRES', 'Modification des paramètres boutique', '::1', '2026-07-03 12:02:48'),
(60, 1, 'MODIFICATION_PARAMETRES', 'Modification des paramètres boutique', '::1', '2026-07-03 12:04:06'),
(61, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-03 14:39:29'),
(62, 1, 'DECONNEXION', 'Déconnexion', '::1', '2026-07-03 14:43:16'),
(63, 3, 'CONNEXION', 'Connexion réussie: vendeur', '::1', '2026-07-03 14:43:37'),
(64, 3, 'DECONNEXION', 'Déconnexion', '::1', '2026-07-03 14:45:12'),
(65, 3, 'CONNEXION', 'Connexion réussie: vendeur', '::1', '2026-07-03 14:47:44'),
(66, 3, 'DECONNEXION', 'Déconnexion', '::1', '2026-07-03 15:02:18'),
(67, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-03 15:02:39'),
(68, 1, 'MODIFICATION_PARAMETRES', 'Modification des paramètres boutique', '::1', '2026-07-03 15:06:54'),
(69, 1, 'MOUVEMENT_STOCK', 'Entree de 250 unité(s) — Savon de Marseille', '::1', '2026-07-03 15:08:01'),
(70, 1, 'MODIFICATION_PARAMETRES', 'Modification des paramètres boutique', '::1', '2026-07-03 15:09:17'),
(71, 1, 'DECONNEXION', 'Déconnexion', '::1', '2026-07-03 15:13:05'),
(72, 1, 'CONNEXION', 'Connexion réussie: admin', '::1', '2026-07-03 15:13:18'),
(73, 1, 'MODIFICATION_PARAMETRES', 'Modification des paramètres boutique', '::1', '2026-07-03 15:14:15');

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
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `magasins`
--

INSERT INTO `magasins` (`id`, `nom`, `adresse`, `code_postal`, `actif`, `date_creation`) VALUES
(1, 'Magasin Principal', '123 Rue du Commerce', '75001', 1, '2026-07-02 22:15:22');

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
  `type` enum('Entree','Sortie','Vente') COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantite` int NOT NULL DEFAULT '0',
  `date_mouvement` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `motif` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mvt_art` (`article_id`),
  KEY `idx_mvt_type` (`type`),
  KEY `idx_mvt_date` (`date_mouvement`),
  KEY `fk_mvt_user` (`utilisateur_id`),
  KEY `idx_mvt_magasin` (`magasin_id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `mouvements_stock`
--

INSERT INTO `mouvements_stock` (`id`, `article_id`, `utilisateur_id`, `magasin_id`, `type`, `quantite`, `date_mouvement`, `motif`) VALUES
(1, 1, 1, NULL, 'Vente', 1, '2026-07-01 16:53:42', 'Vente - Facture FAC-20260701-0001'),
(2, 3, 1, NULL, 'Vente', 2, '2026-07-02 19:37:35', 'Vente - Facture FAC-20260702-0001'),
(3, 4, 1, NULL, 'Vente', 1, '2026-07-02 20:06:57', 'Vente - Facture FAC-20260702-0002'),
(4, 5, 1, NULL, 'Vente', 1, '2026-07-02 20:06:57', 'Vente - Facture FAC-20260702-0002'),
(5, 2, 1, NULL, 'Vente', 3, '2026-07-02 20:14:29', 'Vente - Facture FAC-20260702-0003'),
(6, 3, 1, NULL, 'Entree', 3, '2026-07-02 21:58:48', 'réaprovisionnement'),
(7, 4, 1, 1, 'Vente', 2, '2026-07-03 01:18:00', 'Vente - Facture FAC-20260703-0001'),
(8, 3, 3, 1, 'Vente', 1, '2026-07-03 01:21:07', 'Vente - Facture FAC-20260703-0002'),
(9, 3, 3, 1, 'Vente', 2, '2026-07-03 01:34:38', 'Vente - Facture FAC-20260703-0002'),
(10, 1, 3, 1, 'Vente', 1, '2026-07-03 03:43:10', 'Vente facture FAC-20260703-0003'),
(11, 1, 3, 1, 'Vente', 2, '2026-07-03 04:26:41', 'Vente facture FAC-20260703-0004 [Lot LOT-2025-ABC: -2]'),
(12, 1, 3, 1, 'Vente', 1, '2026-07-03 04:27:42', 'Vente facture FAC-20260703-0005 [Lot LOT-2025-ABC: -1]'),
(13, 4, 1, 1, 'Entree', 250, '2026-07-03 15:08:01', 'réaprovisionnement');

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `parametres`
--

DROP TABLE IF EXISTS `parametres`;
CREATE TABLE IF NOT EXISTS `parametres` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cle` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valeur` text COLLATE utf8mb4_unicode_ci,
  `categorie` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `ordre` int NOT NULL DEFAULT '0',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_modif` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cle` (`cle`),
  KEY `idx_par_cat` (`categorie`),
  KEY `idx_par_cle` (`cle`)
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `parametres`
--

INSERT INTO `parametres` (`id`, `cle`, `valeur`, `categorie`, `ordre`, `description`, `date_modif`) VALUES
(1, 'nom_boutique', 'eStock Market', 'boutique', 10, 'Nom affiché dans le titre, le ticket et le login', '2026-07-03 15:12:34'),
(2, 'adresse_boutique', '123 Rue du Commerce, Lomé', 'boutique', 20, 'Adresse affichée sur le ticket de caisse', '2026-07-03 15:12:34'),
(3, 'telephone_boutique', '228 90 45 67 89', 'boutique', 30, 'Téléphone affiché sur le ticket', '2026-07-03 15:12:34'),
(4, 'email_boutique', 'contact@estock-market.example', 'boutique', 40, 'E-mail de contact affiché sur le ticket', '2026-07-03 15:12:34'),
(5, 'site_boutique', 'www.estock-market.example', 'boutique', 50, 'Site web affiché sur le ticket', '2026-07-03 15:12:34'),
(6, 'slogan_boutique', 'Gérez votre stock et votre caisse. Simplement.', 'boutique', 60, 'Slogan affiché sur la page login', '2026-07-03 15:12:34'),
(7, 'devise_nom', 'Franc CFA BCEAO', 'financier', 10, 'Nom complet de la devise', '2026-07-03 15:14:15'),
(8, 'devise_symbole', 'F', 'financier', 20, 'Symbole affiché avec les montants', '2026-07-03 15:14:15'),
(9, 'devise_code', 'XOF', 'financier', 30, 'Code ISO 4217 de la devise', '2026-07-03 15:12:34'),
(10, 'devise_position', 'apres', 'financier', 35, 'Position du symbole : avant ou apres', '2026-07-03 15:12:34'),
(11, 'devise_decimales', '0', 'financier', 36, 'Nombre de décimales affichées', '2026-07-03 15:14:15'),
(12, 'separateur_decimal', '', 'financier', 37, 'Séparateur décimal', '2026-07-03 15:14:15'),
(13, 'separateur_milliers', '', 'financier', 38, 'Séparateur des milliers', '2026-07-03 15:14:15'),
(14, 'tva_taux_defaut', '20.00', 'financier', 40, 'Taux de TVA par défaut en %', '2026-07-03 15:12:34'),
(15, 'tva_active', '1', 'financier', 50, 'TVA activée (1) ou désactivée (0)', '2026-07-03 15:12:34'),
(16, 'paiement_rapide_1', '2000.00', 'financier', 60, 'Premier montant rapide à la caisse', '2026-07-03 15:14:15'),
(17, 'paiement_rapide_2', '5000.00', 'financier', 70, 'Deuxième montant rapide à la caisse', '2026-07-03 15:14:15'),
(18, 'paiement_rapide_3', '10000.00', 'financier', 80, 'Troisième montant rapide à la caisse', '2026-07-03 15:14:15'),
(19, 'app_nom', 'eStock', 'apparence', 10, 'Nom court affiché dans la sidebar', '2026-07-03 15:12:34'),
(20, 'theme_couleur', 'indigo', 'apparence', 20, 'Couleur d\'accent du thème', '2026-07-03 15:12:34'),
(21, 'langue', 'fr', 'apparence', 30, 'Langue de l\'interface', '2026-07-03 15:12:34'),
(22, 'ticket_entete', 'Merci de votre visite !', 'ticket', 10, 'Message de fin de ticket', '2026-07-03 15:12:34'),
(23, 'ticket_remarque', '', 'ticket', 20, 'Remarque additionnelle sur le ticket', '2026-07-03 15:12:34'),
(24, 'ticket_format', '80mm', 'ticket', 30, 'Format du ticket : 58mm ou 80mm', '2026-07-03 15:12:34'),
(25, 'pays', 'Togo', 'general', 10, 'Pays', '2026-07-03 15:12:34'),
(26, 'code_postal', '', 'general', 20, 'Code postal ou ville', '2026-07-03 15:12:34');

-- --------------------------------------------------------

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
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `permissions`
--

INSERT INTO `permissions` (`id`, `cle_permission`, `description`, `categorie`) VALUES
(1, 'stock_consulter', 'Consulter l\'état du stock', 'stock'),
(2, 'stock_gerer', 'Gérer les entrées/sorties de stock', 'stock'),
(3, 'stock_transfert', 'Effectuer des transferts inter-magasins', 'stock'),
(4, 'articles_consulter', 'Consulter la liste des articles', 'articles'),
(5, 'articles_gerer', 'Créer/Modifier/Supprimer des articles', 'articles'),
(6, 'fournisseurs_consulter', 'Consulter les fournisseurs', 'fournisseurs'),
(7, 'fournisseurs_gerer', 'Gérer les fournisseurs', 'fournisseurs'),
(8, 'facturation_consulter', 'Consulter les factures', 'facturation'),
(9, 'facturation_gerer', 'Créer/Annuler des factures', 'facturation'),
(10, 'caisse_gerer', 'Utiliser la caisse (POS)', 'facturation'),
(11, 'utilisateurs_consulter', 'Consulter les utilisateurs', 'utilisateurs'),
(12, 'utilisateurs_gerer', 'Créer/Modifier les utilisateurs', 'utilisateurs'),
(13, 'magasins_consulter', 'Consulter les magasins', 'magasins'),
(14, 'magasins_gerer', 'Gérer les magasins', 'magasins'),
(15, 'depenses_consulter', 'Consulter les dépenses', 'depenses'),
(16, 'depenses_gerer', 'Saisir/Modifier les dépenses', 'depenses'),
(17, 'parametres_gerer', 'Modifier les paramètres de la boutique', 'config'),
(18, 'audit_consulter', 'Consulter le journal d\'audit', 'config'),
(19, 'statistiques_consulter', 'Consulter les statistiques', 'config'),
(20, 'cloture_gerer', 'Effectuer la clôture de caisse', 'caisse');

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

--
-- Déchargement des données de la table `regles_promotions`
--

INSERT INTO `regles_promotions` (`id`, `nom`, `condition_type`, `jours_limite`, `seuil_stock`, `pourcentage_remise`, `actif`, `cree_le`) VALUES
(1, 'Anti-gaspillage - expire dans 7 jours', 'PEREMPTION_PROCHE', 7, NULL, 30.00, 1, '2026-07-03 03:39:02'),
(2, 'Anti-gaspillage - expire dans 14 jours', 'PEREMPTION_PROCHE', 14, NULL, 15.00, 1, '2026-07-03 03:39:02'),
(3, 'Anti-gaspillage - expire dans 30 jours', 'PEREMPTION_PROCHE', 30, NULL, 5.00, 1, '2026-07-03 03:39:02'),
(4, 'Surstock - plus de 100 unités', 'SURSTOCK', NULL, 100, 10.00, 0, '2026-07-03 03:39:02'),
(5, 'Surstock - plus de 200 unités', 'SURSTOCK', NULL, 200, 20.00, 0, '2026-07-03 03:39:02');

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

--
-- Déchargement des données de la table `role_permissions`
--

INSERT INTO `role_permissions` (`role_nom`, `permission_id`) VALUES
('Admin', 1),
('Directeur', 1),
('Magasinier', 1),
('Vendeur', 1),
('Admin', 2),
('Directeur', 2),
('Magasinier', 2),
('Admin', 3),
('Directeur', 3),
('Magasinier', 3),
('Admin', 4),
('Directeur', 4),
('Magasinier', 4),
('Vendeur', 4),
('Admin', 5),
('Directeur', 5),
('Magasinier', 5),
('Admin', 6),
('Directeur', 6),
('Magasinier', 6),
('Admin', 7),
('Directeur', 7),
('Magasinier', 7),
('Admin', 8),
('Directeur', 8),
('Vendeur', 8),
('Admin', 9),
('Directeur', 9),
('Vendeur', 9),
('Admin', 10),
('Directeur', 10),
('Vendeur', 10),
('Admin', 11),
('Directeur', 11),
('Admin', 12),
('Directeur', 12),
('Admin', 13),
('Directeur', 13),
('Magasinier', 13),
('Admin', 14),
('Directeur', 14),
('Admin', 15),
('Directeur', 15),
('Magasinier', 15),
('Admin', 16),
('Directeur', 16),
('Magasinier', 16),
('Admin', 17),
('Directeur', 17),
('Directeur', 18),
('Admin', 19),
('Directeur', 19),
('Magasinier', 19),
('Admin', 20),
('Directeur', 20),
('Vendeur', 20);

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
  `stock_alerte` int NOT NULL DEFAULT '5',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_stock_mag_art` (`magasin_id`,`article_id`),
  KEY `idx_sm_magasin` (`magasin_id`),
  KEY `idx_sm_article` (`article_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `stock_magasins`
--

INSERT INTO `stock_magasins` (`id`, `magasin_id`, `article_id`, `quantite`, `stock_alerte`) VALUES
(1, 1, 5, 7, 10),
(2, 1, 3, 6, 10),
(3, 1, 1, 22, 5),
(4, 1, 4, 282, 5),
(5, 1, 2, 117, 20);

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `role` enum('Directeur','Admin','Magasinier','Vendeur') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Vendeur',
  `magasin_id` int DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `login` (`login`),
  KEY `idx_user_magasin` (`magasin_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `utilisateurs`
--

INSERT INTO `utilisateurs` (`id`, `nom`, `login`, `mot_de_passe`, `role`, `magasin_id`, `actif`, `date_creation`) VALUES
(1, 'Administrateur ', 'admin', '$2y$10$ynEM3uM1KAHQtjuqGFThoOuOT/NVCPJ/nHaUKU4pjB58d8dt6/Bk.', 'Directeur', 1, 1, '2026-07-01 16:51:22'),
(2, 'Magasinier Rayon', 'magasin', '$2y$10$KgIOuMmBR/qpr5mnn7XGA.pa4b3bLDJJr4fPJ1uyBB4HO0SisIAg.', 'Magasinier', 1, 1, '2026-07-01 16:51:22'),
(3, 'Vendeur Comptoir', 'vendeur', '$2y$10$fIRsNoDrYmOyvUYOovJ6peHj1c6kLDl6lrbgMTndFlT9mI78MaBLK', 'Vendeur', 1, 1, '2026-07-01 16:51:22');

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
-- Contraintes pour la table `lignes_facture`
--
ALTER TABLE `lignes_facture`
  ADD CONSTRAINT `fk_lf_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_lf_facture` FOREIGN KEY (`facture_id`) REFERENCES `factures` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

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
