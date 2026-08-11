-- ============================================================
-- Module 10 : Sécurité complémentaire (2FA TOTP & Proxy)
-- À exécuter APRÈS le schéma de base eSotck.
-- ============================================================

ALTER TABLE utilisateurs
    ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(32) NULL AFTER mot_de_passe,
    ADD COLUMN IF NOT EXISTS totp_actif  TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret;
