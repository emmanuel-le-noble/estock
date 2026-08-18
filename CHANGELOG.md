# Changelog

Toutes les modifications notables du projet eStock sont documentées dans ce fichier.
Format basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) — versionnage
sémantique (SemVer). La version courante est définie par `APP_VERSION` (`config/connexion.php`).

## [Non publié] — En cours

### Renommages / suppressions (cadre local) — ancien → nouveau

| Ancien | Nouveau | Type |
|---|---|---|
| `database/nf525.sql` | `database/integrite_ventes.sql` | migration |
| `database/rgpd.sql` | `database/donnees_clients.sql` | migration |
| `database/emails_queue_rgpd.sql` | `database/emails_consentements.sql` | migration |
| `bin/verif_nf525.php` | `bin/verif_integrite.php` | outil CLI |
| `bin/purge_rgpd.php` | `bin/purge_donnees_clients.php` | outil CLI |
| `docs/conformite/nf525.md` | `docs/conformite/integrite.md` | documentation |
| `export_fec.php` + `templates/export_fec.html.twig` | supprimés — `export_syscohada.php` est le seul export comptable | fichier |
| `tests/Integration/EmailsQueueRgpdTest.php` | `tests/Integration/EmailsConsentementsTest.php` | test |
| permission `conformite_export_fec` | permission `conformite_export_syscohada` | RBAC |
| action d'audit `NF525_BACKFILL` | action `CHAINAGE_BACKFILL` | audit |
| libellés « Conformité NF525 / RGPD » (menu), « TVA non applicable » sur factures/tickets | terminologie locale (traçabilité des ventes, protection des données clients) | UI |

### Ajouté
- **Export SYSCOHADA (OHADA)** : `export_syscohada.php` + vue dédiée — CSV
  SYSCOHADA révisé compatible comptable/OHADA, préambule (entreprise, NIF, RCCM,
  exercice, régime fiscal), journaux VT/AC/DG/INV (incluant la variation de
  stocks au CUMP), mention TPU (aucun compte 4457).
- **Outils CLI de maintenance** :
  - `bin/test_restore.php` — restauration de la dernière sauvegarde sur la base
    de test `estock_db_test` + vérifications d'intégrité (tables, utilisateurs,
    articles, factures, CA agrégé) ;
  - `bin/purge_donnees_clients.php` — purge programmée (CLI/cron, options
    `--mois-inactivite`, `--mois-anonyme`, `--dry-run`).
- **Tests PHPUnit** :
  - `tests/Unit/ConformiteTogoUnitTest.php` — cohérence régime fiscal TPU/TVA,
    taux effectif, mention, config POS ;
  - `tests/Integration/FactureConformiteTest.php` — préfixe de numérotation,
    JOIN client (raison sociale/NIF/RCCM) sur `db_facture_get_by_id()` ;
  - `tests/Integration/ValorisationCumpTest.php` — CUMP : moyenne pondérée
    multi-entrées, fallback prix_achat, consommation FIFO à la vente.
- **Conformité e-facturation OTR** : `docs/conformite/otr.md` (régime fiscal,
  identifiants NIF/RCCM, colonne `factures.statut_transmission` prête, plan du
  futur connecteur) + encart « Exports comptables & e-facturation » sur
  `conformite.php`.

### Modifié
- **Consolidation de la nomenclature** : plus aucune référence aux normes
  étrangères — le produit est positionné sur le cadre local (OTR Togo) :
  migrations `integrite_ventes.sql` / `donnees_clients.sql` /
  `emails_consentements.sql`, outils `bin/verif_integrite.php` et
  `bin/purge_donnees_clients.php`, docs `docs/conformite/integrite.md`.
- **Export SYSCOHADA** : nouveau journal **INV** (variation de stocks au CUMP,
  compte 31/6037) ; permission `conformite_export_syscohada` ; les écritures
  s'appuient sur les journaux généraux `db_journal_*`.
- **Statistiques** : la **TVA collectée** de la période est affichée dans le
  compte de résultat (régime TVA uniquement, `db_stats_period()`) ; en régime
  TPU, une ligne « TVA — non applicable » est affichée.
- **Avertissements NIF (OTR)** : en caisse, un client professionnel (raison
  sociale / RCCM) sans NIF déclenche un avertissement visuel (sans bloquer la
  vente) ; `facture_view.php` signale de même une facture B2B non conforme.
  L'API `api/clients/search` renvoie désormais `nif` et `rccm`.
- **Journal des ventes (export SYSCOHADA)** : en régime TPU, les écritures
  4457 (TVA) ne sont plus générées (montant nul) — cohérence comptable.
- **Vente au poids** : structure prête en base (`vente_au_poids`,
  `unite_mesure`, `poids_precision`) mais saisie décimale en caisse non
  branchée — mention explicite dans le README (pas de fausse promesse).
- **Traçabilité des transferts** : `db_transferer_stock()` crée désormais les
  mouvements de stock — `Transfert` (sortie, magasin source) et `Entree`
  (magasin destination) — dans la même transaction. Ils apparaissent sur
  `mouvements.php` avec les filtres et badges élargis (`Transfert`,
  `Ajustement`, `Retour_stock`).
- **Numérotation des factures** : le préfixe est désormais paramétrable
  (`Paramètres → facture_prefixe`, ex. `FAC-20260814-0001`). `generate_invoice_number()`
  nettoie et valide le préfixe ; toujours atomique via la table `sequences`.
- **Valorisation CUMP câblée (P3-13)** :
  - `process_stock_movement()` actualise `articles.cump` à **toute entrée**
    (coût réel transmis par la réception fournisseur via
    `prix_achat_unitaire`, sinon `prix_achat` de la fiche) et consomme les
    couches FIFO (`article_couts`) aux sorties/ventes ;
  - `db_article_cump_entree()` est multi-magasins (le magasin 1 n'est plus
    codé en dur) ;
  - CAMV des statistiques valorisé au **CUMP courant** avec fallback
    `prix_achat` pour les articles antérieurs à l'activation de la
    valorisation (`db_stats_cout_achat_ventes()`) ;
  - `db_article_get_for_mouvement()` renvoie `prix_achat`/`cump` (nécessaire
    au fallback).
- **Docs ADR** : `docs/decisions/adr.md` — Paiement Mobile Money (ADR-001),
  Journal de caisse / balance (ADR-002), Scalabilité multi-caisses (ADR-003),
  File d'attente e-mails (ADR-004).
- **Fiche facture (facture_view.php)** : bloc Client (nom/raison sociale + NIF)
  affiché sur le ticket ; alerte « facture non conforme (OTR) » si le NIF
  boutique n'est pas renseigné ; mention « TVA non applicable (TPU) » en régime TPU.
- **Caisse (caisse.php)** : en régime TPU, la ligne TVA est remplacée par la
  mention « TVA non applicable (TPU) » (calcul JS déjà à 0 via `param_pos_config()`).
- **`db_facture_get_by_id()`** : join `clients` (client_nom : raison sociale
  prioritaire, client_nif, client_rccm) utilisé par la facture et le ticket.
- **Base URL JS** : `window.BASE_URL` injecté centralement (layout Twig +
  `includes/header.php`) ; suppression des chemins durs `'/eSotck/'` (fallback
  relatif). Le dossier Apache peut être renommé sans casser les liens AJAX.
- **README** : précision sur `regles_promotions` (Ventes Flash en caisse, pas les
  suggestions d'achat), outil `bin/inventaire_tables.php`, tests PHPUnit.
- **tests/bootstrap.php** : la migration `conformite_togo.sql` est appliquée sur
  la base de test (NIF/RCCM clients).

## [2.0.0] — 2026

### Ajouté
- Chaînage cryptographique des factures et clôtures, triggers d'inaltérabilité
  (traçabilité des ventes, voir `docs/conformite/integrite.md`).
- Archives de caisse signées (camouflage, conservation ≥ 6 ans) — `conformite.php`.
- Données clients : consentement fidélité tracé (`consentements_log`),
  anonymisation, purge programmée, purge base de test.
- Fidélité : points par devise, verrouillage `FOR UPDATE`, reversements,
  historique — caisse et API.
- Multi-magasins complet (stock_magasins, transferts, magasin d'affectation).
- Lots FEFO (péremptions), alertes DLC bloquantes en caisse.
- Commandes fournisseur (cycle brouillon → reçu), suggestions d'achat par
  fournisseur, inventaires physiques, retours/avoirs SAV, promotions & codes
  promo, dépenses, statistiques + compte de résultat.
- Paiements multi-modes (espèces, carte, mobile money), mode hors-ligne
  (file localStorage, synchro `api/caisse/sync` idempotente).
- RBAC dynamique (permissions/role_permissions), audit trail, rate limiting,
  URLs signées HMAC, CSP nonce, 2FA TOTP (helpers), file d'e-mails + worker.
- API REST centralisée (`api/index.php`), export SYSCOHADA, exports CSV.

### Sécurité (2.0.0)
- `DB_PASS` et `SECRET_URL_KEY` toujours bloquants (aucun fallback silencieux).
- Refus de démarrage en production si `DB_USER` manquant ; fallback dev journalisé.

## [1.0.0] — Début du projet

### Ajouté
- POS caisse (scan code-barres, tickets 80 mm), articles, catégories, stock.
- Facturation transactionnelle, numérotation journalière, sessions sécurisées,
  RBAC de base, rôles Directeur / Admin / Magasinier / Vendeur.