-- ============================================================
-- Migration Google OAuth — 27/08/2026
-- Compatible MySQL 8.0+ (pas de IF NOT EXISTS sur CREATE INDEX)
-- ============================================================

-- 1. Colonnes Google OAuth (ignorer l'erreur si les colonnes existent déjà)
ALTER TABLE `utilisateurs`
  ADD COLUMN `google_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `totp_actif`;
ALTER TABLE `utilisateurs`
  ADD COLUMN `google_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `google_id`;
ALTER TABLE `utilisateurs`
  ADD COLUMN `google_avatar` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `google_email`;

-- 2. Dédupliquer les google_email avant d'ajouter l'unicité
--    (conserver la ligne la plus récente en cas de doublon)
DELETE t1 FROM `utilisateurs` t1
  INNER JOIN `utilisateurs` t2
  ON t1.google_email = t2.google_email
  AND t1.google_email IS NOT NULL
  AND t1.id < t2.id;

-- 3. Index google_id (recherche rapide)
ALTER TABLE `utilisateurs`
  ADD INDEX `idx_user_google_id` (`google_id`);

-- 4. Index unique google_email ( connexion par email Google )
ALTER TABLE `utilisateurs`
  ADD UNIQUE INDEX `idx_user_google_email` (`google_email`);
