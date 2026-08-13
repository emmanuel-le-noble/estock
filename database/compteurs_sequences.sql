-- ============================================================
-- compteurs_sequences.sql — Compteurs atomiques (NF525)
-- Garantit la continuité de numérotation des factures même en
-- cas de transactions concurrentes (multi-caisses).
-- Idempotent : CREATE TABLE IF NOT EXISTS.
-- ============================================================

CREATE TABLE IF NOT EXISTS `sequences` (
    `cle`      VARCHAR(100) NOT NULL,
    `valeur`   BIGINT       NOT NULL DEFAULT 0,
    `date_maj` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;