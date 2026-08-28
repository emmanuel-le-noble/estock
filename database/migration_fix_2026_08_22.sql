-- ============================================================
-- Migration corrective du 22/08/2026
-- Appliquer sur la base estock_db existante AVANT la mise en production
-- ============================================================

-- 1. Enum mouvements_stock.type complet (CRASH GARANTI sans cela)
--    Les valeurs 'Transfert', 'Ajustement', 'Retour_stock' etaient utilisees
--    par le code PHP mais absentes de l'enum MySQL.
ALTER TABLE `mouvements_stock`
  MODIFY COLUMN `type` enum('Entree','Sortie','Vente','Transfert','Ajustement','Retour_stock') COLLATE utf8mb4_unicode_ci NOT NULL;

-- 2. Permissions manquantes (FONCTIONNALITES INACCESSIBLES)
--    5 permissions referencees par le code PHP n'existaient pas dans la table.
INSERT IGNORE INTO `permissions` (`cle_permission`, `description`, `categorie`) VALUES
  ('clients_consulter',   'Consulter la fiche client',               'Clients'),
  ('clients_gerer',       'Creer / modifier / supprimer les clients', 'Clients'),
  ('conformite_archives', 'Gerer les archives de conformite',         'Conformite'),
  ('conformite_export_syscohada', 'Exporter en format SYSCOHADA',     'Conformite'),
  ('articles_modifier',   'Modifier les articles via l''API',         'Articles');

-- 3. Ajout des permissions aux roles existants
-- Directeur : toutes les permissions
INSERT IGNORE INTO `role_permissions` (`role_nom`, `permission_id`)
SELECT 'chef équipe', p.id
FROM `permissions` p
WHERE p.cle_permission IN ('clients_consulter','clients_gerer','conformite_archives','conformite_export_syscohada','articles_modifier');

-- Admin : clients + articles
INSERT IGNORE INTO `role_permissions` (`role_nom`, `permission_id`)
SELECT 'Admin', p.id
FROM `permissions` p
WHERE p.cle_permission IN ('clients_consulter','clients_gerer','articles_modifier');

-- 4. Index manquants pour les performances (CREATE INDEX IF NOT EXISTS = MySQL 8.0.29+)
CREATE INDEX IF NOT EXISTS `idx_hp_type_operation` ON `historique_points` (`type_operation`);

CREATE INDEX IF NOT EXISTS `idx_cf_date_commande` ON `commandes_fournisseur` (`date_commande`);

CREATE INDEX IF NOT EXISTS `idx_cl_client_type` ON `consentements_log` (`client_id`, `type_consentement`);

CREATE INDEX IF NOT EXISTS `idx_ac_fifo` ON `article_couts` (`article_id`, `quantite`, `date_entree`);

-- 5. Suppression de la table de test (MyISAM, inutile en production)
DROP TABLE IF EXISTS `__test_group`;
