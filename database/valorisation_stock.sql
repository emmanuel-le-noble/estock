-- ============================================================
-- valorisation_stock.sql — Valorisation du stock (CUMP / FIFO)
--
-- Distinguée de la logique FEFO (péremption) : ici c'est la
-- valorisation COMPTABLE du stock.
--
--   * articles.cump : Coût Unitaire Moyen Pondéré courant
--   * articles.valeur_stock : valeur comptable du stock
--   * article_couts : couches de coût (FIFO) à la réception
--   * stock_magasins.valeur_stock : valeur par magasin
--
-- ATTENTION : idempotence garantie par database/apply_migrations.php.
-- ============================================================

ALTER TABLE `articles`
    ADD COLUMN `cump`         DECIMAL(14,4) NOT NULL DEFAULT 0.0000 AFTER `prix_achat`,
    ADD COLUMN `valeur_stock` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `quantite_stock`;

ALTER TABLE `stock_magasins`
    ADD COLUMN `valeur_stock` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `quantite`;

-- Couches de coût FIFO (une ligne par réception / lot de coût)
CREATE TABLE IF NOT EXISTS `article_couts` (
    `id`            INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `article_id`    INT           NOT NULL,
    `magasin_id`    INT           NOT NULL DEFAULT 1,
    `quantite`      INT           NOT NULL DEFAULT 0,
    `cout_unitaire` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    `date_entree`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reference`     VARCHAR(60)   NULL COMMENT 'Référence d entrée (commandes, mouvements)',
    KEY `idx_acc_article` (`article_id`, `magasin_id`),
    KEY `idx_acc_date` (`date_entree`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;