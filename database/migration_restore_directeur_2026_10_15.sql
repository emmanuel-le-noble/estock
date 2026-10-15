-- Remet le nom du rôle « Directeur » après la migration historique du 28/08/2026.
-- Les journaux déjà écrits sont conservés tels quels afin de préserver leur historique.

ALTER TABLE `utilisateurs`
  MODIFY COLUMN `role` enum('chef équipe','Directeur','Admin','Magasinier','Vendeur') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Vendeur';

INSERT IGNORE INTO `role_permissions` (`role_nom`, `permission_id`)
SELECT 'Directeur', `permission_id`
FROM `role_permissions`
WHERE `role_nom` = 'chef équipe';

DELETE FROM `role_permissions`
WHERE `role_nom` = 'chef équipe';

UPDATE `utilisateurs`
SET `role` = 'Directeur'
WHERE `role` = 'chef équipe';

ALTER TABLE `utilisateurs`
  MODIFY COLUMN `role` enum('Directeur','Admin','Magasinier','Vendeur') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Vendeur';
