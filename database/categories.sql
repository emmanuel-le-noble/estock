-- ============================================================
-- Module 2 : Catégories d'articles
-- ============================================================

CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `nom` VARCHAR(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actif` TINYINT(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Colonne categorie_id sur articles
ALTER TABLE `articles` ADD COLUMN `categorie_id` INT DEFAULT NULL AFTER `fournisseur_id`;
