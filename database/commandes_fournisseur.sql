-- database/commandes_fournisseur.sql
-- Migration Module 1 : Commandes fournisseurs (Cycle d'achat)

CREATE TABLE IF NOT EXISTS `commandes_fournisseur` (
  `id` int NOT NULL AUTO_INCREMENT,
  `fournisseur_id` int NOT NULL,
  `magasin_id` int NOT NULL,
  `utilisateur_id` int NOT NULL,
  `statut` enum('Brouillon','En_Attente','Envoyee','Recue_Partielle','Recue','Annulee') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Brouillon',
  `date_commande` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_reception_prevue` date DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cf_fournisseur` (`fournisseur_id`),
  KEY `idx_cf_magasin` (`magasin_id`),
  KEY `idx_cf_user` (`utilisateur_id`),
  KEY `idx_cf_statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lignes_commande_fournisseur` (
  `id` int NOT NULL AUTO_INCREMENT,
  `commande_id` int NOT NULL,
  `article_id` int NOT NULL,
  `quantite_commandee` int NOT NULL DEFAULT '1',
  `quantite_recue` int NOT NULL DEFAULT '0',
  `prix_achat_unitaire` decimal(12,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `idx_lcf_commande` (`commande_id`),
  KEY `idx_lcf_article` (`article_id`),
  CONSTRAINT `fk_lcf_commande` FOREIGN KEY (`commande_id`) REFERENCES `commandes_fournisseur` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions RBAC pour les achats
INSERT IGNORE INTO `permissions` (`cle_permission`, `description`, `categorie`) VALUES
('achats_gerer', 'Gérer les commandes fournisseurs (création, édition, réception)', 'Logistique'),
('achats_consulter', 'Consulter les commandes fournisseurs', 'Logistique'),
('achats_valider', 'Valider ou rejeter les commandes fournisseurs soumises (approbation)', 'Logistique');

-- Attribution aux rôles par défaut
INSERT IGNORE INTO `role_permissions` (`role_nom`, `permission_id`)
SELECT 'Directeur', id FROM `permissions` WHERE `cle_permission` IN ('achats_gerer', 'achats_consulter', 'achats_valider')
UNION ALL
SELECT 'Admin', id FROM `permissions` WHERE `cle_permission` IN ('achats_gerer', 'achats_consulter')
UNION ALL
SELECT 'Magasinier', id FROM `permissions` WHERE `cle_permission` IN ('achats_gerer', 'achats_consulter');
