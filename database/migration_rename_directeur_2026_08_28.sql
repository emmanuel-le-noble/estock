-- ============================================================
-- Migration : Renommage du rôle Directeur → chef équipe
-- Date : 28/08/2026
-- ============================================================

-- 1. Modifier l'ENUM dans la table utilisateurs
ALTER TABLE `utilisateurs`
  MODIFY COLUMN `role` enum('chef équipe','Admin','Magasinier','Vendeur') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Vendeur';

-- 2. Mettre à jour la table role_permissions
UPDATE `role_permissions` SET `role_nom` = 'chef équipe' WHERE `role_nom` = 'Directeur';

-- 3. Nettoyer les anciens messages du journal d'activité
UPDATE `logs_activite` SET `details` = REPLACE(`details`, 'Directeur', 'chef équipe') WHERE `details` LIKE '%Directeur%';
UPDATE `logs_activite` SET `details` = REPLACE(`details`, 'du Directeur', 'du chef équipe') WHERE `details` LIKE '%du Directeur%';
UPDATE `logs_activite` SET `details` = REPLACE(`details`, 'par le Directeur', 'par le chef équipe') WHERE `details` LIKE '%par le Directeur%';
UPDATE `logs_activite` SET `details` = REPLACE(`details`, '(Directeur)', '(chef équipe)') WHERE `details` LIKE '%(Directeur)%';
