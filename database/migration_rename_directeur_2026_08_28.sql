-- ============================================================
-- Migration : Renommage du rôle Directeur → Chef d equipe
-- Date : 28/08/2026
-- ============================================================

-- 1. Modifier l'ENUM dans la table utilisateurs
ALTER TABLE `utilisateurs`
  MODIFY COLUMN `role` enum('chef équipe','Admin','Magasinier','Vendeur') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Vendeur';

-- 2. Mettre à jour la table role_permissions
UPDATE `role_permissions` SET `role_nom` = 'chef équipe' WHERE `role_nom` = 'Directeur';
