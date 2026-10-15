-- Complète les permissions effectivement contrôlées par le code et initialise
-- les accès par défaut documentés pour chaque rôle.
INSERT IGNORE INTO `permissions` (`cle_permission`, `description`, `categorie`) VALUES
  ('articles_consulter', 'Consulter le catalogue des articles', 'Articles'),
  ('articles_gerer', 'Créer et gérer les articles et catégories', 'Articles'),
  ('achats_consulter', 'Consulter les commandes fournisseurs', 'Achats'),
  ('achats_gerer', 'Créer, modifier et réceptionner les commandes', 'Achats'),
  ('achats_valider', 'Valider les commandes fournisseurs', 'Achats'),
  ('stock_consulter', 'Consulter les stocks et péremptions', 'Stock'),
  ('inventaire_consulter', 'Consulter les inventaires', 'Inventaire'),
  ('inventaire_gerer', 'Créer et valider les inventaires', 'Inventaire'),
  ('caisse_gerer', 'Gérer la caisse et enregistrer les ventes', 'Ventes'),
  ('facturation_consulter', 'Consulter et imprimer les factures', 'Ventes'),
  ('retours_consulter', 'Consulter les retours et le SAV', 'Ventes'),
  ('retours_gerer', 'Créer et traiter les retours', 'Ventes'),
  ('promotions_consulter', 'Consulter les promotions', 'Ventes'),
  ('promotions_gerer', 'Créer et modifier les promotions', 'Ventes');

-- Directeur : toutes les permissions présentes au moment de la migration.
INSERT IGNORE INTO `role_permissions` (`role_nom`, `permission_id`)
SELECT 'Directeur', `id` FROM `permissions`;

-- Admin : gestion opérationnelle complète, sans accès au journal ni à la conformité.
INSERT IGNORE INTO `role_permissions` (`role_nom`, `permission_id`)
SELECT 'Admin', `id` FROM `permissions`
WHERE `cle_permission` IN (
  'articles_consulter','articles_gerer','articles_modifier','achats_consulter','achats_gerer',
  'stock_consulter','inventaire_consulter','inventaire_gerer','caisse_gerer',
  'facturation_consulter','clients_consulter','clients_gerer','retours_consulter',
  'retours_gerer','promotions_consulter','promotions_gerer','utilisateurs_gerer'
);

-- Magasinier : stock, articles, commandes et inventaires.
INSERT IGNORE INTO `role_permissions` (`role_nom`, `permission_id`)
SELECT 'Magasinier', `id` FROM `permissions`
WHERE `cle_permission` IN (
  'articles_consulter','articles_gerer','articles_modifier','achats_consulter','achats_gerer',
  'stock_consulter','inventaire_consulter','inventaire_gerer'
);

-- Vendeur : caisse, factures, clients, retours et consultation des promotions.
INSERT IGNORE INTO `role_permissions` (`role_nom`, `permission_id`)
SELECT 'Vendeur', `id` FROM `permissions`
WHERE `cle_permission` IN (
  'caisse_gerer','facturation_consulter','clients_consulter','retours_consulter',
  'retours_gerer','promotions_consulter'
);
