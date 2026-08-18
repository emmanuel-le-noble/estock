# eStock · Application de gestion de stock, facturation (POS) & multi-magasins

**eStock** est une application web complète de gestion de supermarché / commerce de
détail : vente au comptoir (POS + lecteur de code-barres), gestion multi-magasins,
achats fournisseurs, inventaire physique, péremptions (FEFO), retours SAV,
promotions, dépenses, statistiques, clôture de caisse journalière, mode hors-ligne
et administration complète (RBAC dynamique, audit trail).

- **Backend** : PHP 8 pur, procédural, sans framework ni MVC (Twig 3 via Composer)
- **Base de données** : MySQL / MariaDB (`utf8mb4`) — compatible WampServer
- **Frontend** : HTML5, CSS3, Vanilla JS, Bootstrap 5 (CDN), JsBarcode, Chart.js
- **Sécurité** : sessions sécurisées, CSRF, RBAC dynamique, URLs signées HMAC,
  rate limiting sur connexion, en-têtes HTTP (CSP), audit trail, 2FA TOTP (helpers)

---

## Table des matières

1. [Installation](#1-installation)
2. [Comptes par défaut](#2-comptes-par-défaut)
3. [Démarrage rapide](#3-démarrage-rapide)
4. [Arborescence du projet](#4-arborescence-du-projet)
5. [Guide utilisateur par rôle](#5-guide-utilisateur-par-rôle)
6. [Modules fonctionnels](#6-modules-fonctionnels)
7. [Rôles & permissions (RBAC)](#7-rôles--permissions-rbac)
8. [Architecture technique](#8-architecture-technique)
9. [Base de données](#9-base-de-données)
10. [Sécurité](#10-sécurité)
11. [API REST](#11-api-rest)
12. [Mode hors-ligne (offline sync)](#12-mode-hors-ligne-offline-sync)
13. [Guide développeur (conventions)](#13-guide-développeur-conventions)
14. [Outils de maintenance](#14-outils-de-maintenance)
15. [Dépannage & FAQ](#15-dépannage--faq)

---

## 1. Installation

### Prérequis

- **WampServer** (ou équivalent) : Apache + PHP 8.0+ + MySQL 5.7+/8
- Extensions PHP requises : `pdo_mysql`, `openssl`, `mbstring`, `json`, `session`
- [Composer](https://getcomposer.org) (pour Twig)

### Étapes

```bash
# 1. Copier le projet dans le dossier web
#    C:\wamp64\www\eSotck   (nom de dossier historique du sous-dossier Apache ;
#                           le nom du PROJET est "eStock" : composer.json,
#                           .env.example, logos et commentaires utilisent eStock)
# 2. Installer les dépendances PHP (Twig 3) — uniquement si vendor/ absent
composer install
```

**3. Créer la base de données** :

- **Manuelle (recommandée)** : importez `database/estock_db (1).sql` dans
  phpMyAdmin, puis exécutez `php database/apply_migrations.php` (toutes les
  migrations, voir [Outils de maintenance](#14-outils-de-maintenance)).

**4. Configurer l'environnement** — copier `.env.example` vers `.env` :

```
DB_HOST=127.0.0.1        # facultatif (défaut 127.0.0.1)
DB_NAME=estock_db        # facultatif (défaut estock_db)
DB_USER=root             # facultatif en dev (défaut root), obligatoire en production
DB_PASS=********         # OBLIGATOIRE, dans TOUS les environnements
SECRET_URL_KEY=********  # OBLIGATOIRE, 32 caractères minimum
APP_ENV=development      # development | production
```

> **DB_PASS et SECRET_URL_KEY sont toujours bloquants** quel que soit
> l'environnement : l'application refuse de démarrer si l'une des deux est
> manquante (aucun fallback silencieux, page d'erreur HTTP 500 ou message CLI).
> La distinction dev/prod porte sur les autres variables (`DB_HOST`, `DB_NAME`,
> `DB_USER`) : valeur d'exemple acceptée en développement avec journalisation
> d'alerte, démarrage refusé en production.
>
> La connexion se fait sur `127.0.0.1` (jamais `localhost`) pour éviter les
> problèmes de socket sous Windows. Le fichier `.env` est chargé par
> `config/connexion.php` (chargeur maison, sans dépendance externe).

**5. Ouvrir l'application** : http://localhost/eSotck/

> Le routeur chiffré AES-256-CBC est activé : toutes les URLs de navigation
> internes passent par `/?route=<token>` (chiffrées + signées HMAC, validité 24 h).

## 2. Création du premier compte

La base livrée ne contient **aucun compte actif** et aucun mot de passe connu.
Après l'import de la base et les migrations, créez le premier Directeur depuis
la ligne de commande :

```powershell
php bin/create_admin.php "Nom du responsable" directeur "un-mot-de-passe-d-au-moins-12-caracteres"
```

Ne réutilisez jamais un mot de passe de démonstration et ne conservez pas cette
commande dans un historique partagé. Le Directeur crée ensuite les autres
comptes depuis l'interface Utilisateurs.

Pour une ancienne installation qui contient encore `admin`, `magasin` ou
`vendeur`, créez d'abord le nouveau Directeur puis exécutez :

```powershell
php bin/disable_demo_accounts.php
```

## 3. Démarrage rapide

1. **Directeur** → se connecter avec `admin` → Tableau de bord : vérifier les
   alertes de stock, compléter **Paramètres** (nom boutique, devise, TVA, ticket).
2. **Magasinier** → créer les **Catégories**, les **Articles** (code-barres,
   seuil d'alerte, DLC si périssable), les **Fournisseurs**, saisir le **stock
   initial** via **Mouvements → Entrée**.
3. **Vendeur** → ouvrir la **Caisse**, scanner le code-barres (douchette ou
   saisie manuelle), encaisser (espèces / carte, paiements rapides), imprimer le
   ticket 80 mm.
4. **Directeur** → suivre les **Statistiques**, la **Clôture de caisse**
   journalière et le **Journal d'activité**.

## 4. Arborescence du projet

```
eSotck/                          # Dossier Apache (nom historique) — projet "eStock"
├── index.php                    # Front controller (routeur chiffré AES-256-CBC)
├── .htaccess                    # Réécriture → toutes les requêtes vers index.php
├── composer.json                # Dépendances PHP (Twig 3)
├── .env / .env.example          # Configuration d'environnement (DB, HMAC, APP_ENV)
│
├── auth/login.php               # Connexion (rate limiting, session sécurisée)
├── auth/logout.php              # Déconnexion
├── auth/choisir_magasin.php     # Sélecteur du magasin de suivi (Directeur)
│
├── api/index.php                # API REST centralisée (JSON + Auth par session)
│
│   ── Pages applicatives (Twig layout base.html.twig) ──
├── tableau_bord.php             # Dashboard : indicateurs + alertes
├── stock.php                    # Consultation du stock (recherche, mini-stock)
├── articles.php                 # CRUD articles + recherche + code-barres EAN-13
├── categories.php               # CRUD catégories d'articles (actives/inactives)
├── fournisseurs.php             # CRUD fournisseurs (devise de facturation)
├── mouvements.php               # Saisie Entrées/Sorties + historique filtrable
├── peremptions.php              # Alertes DLC/DDM (expirés, urgents) — FEFO
├── etiquettes.php               # Génération d'étiquettes de rayon (EAN-13, A4)
├── transferts.php               # Transferts inter-magasins (expédition/réception)
├── commandes_fournisseur.php    # Cycle d'achat : commandes fournisseurs (devise)
├── suggestions_achat.php        # Suggestions de réapprovisionnement par fournisseur
├── inventaire.php               # Sessions d'inventaire physique (comptage cyclique)
├── retours.php                  # Retours clients & avoirs SAV
├── promotions.php               # Codes promo & remises ciblées
├── caisse.php                   # POS : scan, panier, paiements multi-modes
├── factures.php                 # Historique des factures (recherche, filtres)
├── facture_view.php             # Détail facture + impression (ticket / A4 légal)
├── ticket_print.php             # Ticket thermique 80 mm (impression)
├── cloture.php                  # Clôture / Z de caisse journalière (chaînée)
├── clients.php                  # Clients : fidélité, consentement, anonymisation
├── depenses.php                 # Dépenses d'exploitation (charges)
├── statistiques.php             # Analyse CA, marges, top ventes, compte de résultat
├── export_syscohada.php         # Export SYSCOHADA (écritures comptables OHADA)
├── conformite.php               # Traçabilité : intégrité de chaîne, archives de caisse
├── exports.php                  # Exports CSV (articles, factures, mouvements, inventaires)
├── utilisateurs.php             # CRUD utilisateurs + affectation magasin
├── magasins.php                 # CRUD magasins + statut actif
├── parametres.php               # Paramètres boutique + devise + TVA + ticket + RBAC
├── audit.php                    # Journal d'activité (audit trail, filtrable)
├── profil.php                   # Profil : mot de passe, infos personnelles
├── documentation.php            # Guide utilisateur intégré par rôle
│
├── templates/                   # Templates Twig (layout + pages + impressions)
│   ├── base.html.twig           # Layout maître (sidebar sections + topbar + CSS)
│   ├── *.html.twig              # Une vue par page applicative
│   ├── etiquettes_print.html.twig / ticket_print.html.twig  # Impressions
│   └── facture_view.php         # A4 légal B2B rendu via header/footer legacy
├── config/
│   ├── connexion.php            # PDO, sessions, auth, CSRF, RBAC, Twig, pagination
│   └── parametres.php           # Cache des paramètres boutique (auto-install + seed)
├── includes/
│   ├── header.php               # Layout des pages PHP (sidebar + topbar)
│   ├── footer.php               # Pied de page (version APP_VERSION) + scripts JS
│   ├── acces_refuse.php         # Page 403
│   ├── security_headers.php     # CSP nonce + en-têtes HTTP de sécurité
│   ├── db_functions.php         # Couche d'accès aux données (180+ fonctions SQL)
│   └── helpers.php              # Validation, logique métier, rendu HTML, HMAC
├── bin/                         # Scripts CLI (maintenance, backups, worker)
│   ├── backup_db.php            # Sauvegarde CLI (dump SQL zippé + rotation 30 j)
│   ├── test_restore.php         # Test de restauration sur base de test
│   ├── inventaire_tables.php    # Inventaire réel des tables vs base
│   ├── emails_worker.php        # Worker de la file d'attente e-mails
│   ├── purge_donnees_clients.php # Purge programmée (anonymisation/suppression)
│   └── verif_integrite.php      # Audit CLI de la chaîne cryptographique
├── database/                    # Scripts SQL (dump + migrations idempotentes)
├── docs/                        # Documentation (conformité, ADR, OpenAPI)
├── tests/                       # Suite de tests (run_tests.php + cas)
├── assets/
│   ├── css/style.css            # Styles globaux + impression
│   └── js/app.js, caisse.js     # JS global (sidebar, filtres) / logique POS
└── vendor/                      # Composer (non versionné)
```

---

## 5. Guide utilisateur par rôle

> L'application intègre une **page Documentation** (menu latéral → Documentation)
> qui détaille le fonctionnement de chaque module pour chaque rôle.

| | **Vendeur** | **Magasinier** | **Admin** | **Directeur** |
|---|---|---|---|---|
| **Caisse (POS)** | ✅ Encaisser, tickets, retours | ❌ | ❌ | ❌ |
| **Stock / Articles** | Consultation | Gestion complète | Gestion complète | Supervision |
| **Achats fournisseurs** | ❌ | Créer & recevoir | Créer & recevoir | Valider & recevoir |
| **Inventaire** | ❌ | Créer, compter, valider | Créer, compter, valider | Supervision |
| **Factures** | Consulter + imprimer | Consulter | Consulter / annuler | Consulter / annuler |
| **Dépenses** | ❌ | Saisir (son magasin) | Saisir | Saisir + vue d'ensemble |
| **Statistiques** | Son activité | Son magasin | Globale | Globale |
| **Administration** | Profil | Profil | Utilisateurs, magasins, paramètres | Tout (incl. audit, validation) |

Voir la [matrice complète des permissions](#7-rôles--permissions-rbac).

---

## 6. Modules fonctionnels

### 6.1 Tableau de bord (`tableau_bord.php`)
Indicateurs du jour et du mois (CA, ventes, paniers, marge), alertes stock faible
et DLC, derniers mouvements, top produits. **Toujours filté au magasin actif**
(`user_magasin_id()`).

### 6.2 Caisse POS (`caisse.php` + `assets/js/caisse.js`)
- Champ de scan qui **garde le focus en permanence** (clavier + douchette).
- Saisie code-barres → recherche AJAX → ajout au panier sans rechargement.
- **Raccourcis clavier** : `F2` vider le panier, `F8` valider la vente.
- **Paiements multi-modes** : espèces, carte, mobile money, montants exacts,
  paiements rapides (boutons), monnaie rendue calculée.
- **Codes promo** appliqués sur le total (voir 6.12).
- **Mode hors-ligne** : ventes mises en file d'attente (`localStorage`) et
  synchronisées automatiquement via `api/caisse/sync` (voir §12).
- **Clôture** : bouton d'accès à la clôture de caisse (voir 6.8).
- **Quantités** : saisie en nombres entiers (la vente au poids — quantités
  décimales via `vente_au_poids` / `unite_mesure` — est prévue mais non
  branchée, voir 6.5).
- **Client fidélité** : sélection par nom/téléphone/code carte, points
  disponibles ; avertissement visuel si un client professionnel (raison
  sociale / RCCM) n'a pas de NIF — la vente reste possible.

### 6.3 Encaissement (`valider_facture.php`)
Opération **transactionnelle** :
1. Vérification des stocks (verrouillage `FOR UPDATE`).
2. Génération du numéro de facture (préfixe configurable + séquence).
3. Insertion facture + lignes + paiements.
4. Décrémentation du stock (par **lots FEFO** pour les articles périssables).
5. Création du mouvement de stock type `Vente` pour l'historique.
6. Journalisation `VALIDER_FACTURE` dans l'audit trail.

### 6.4 Factures (`factures.php`, `facture_view.php`, `ticket_print.php`)
Recherche par n°, client, date, statut (Payée / Annulée), montant ; pagination.
Détail via **URL signée HMAC** (24 h) ; impression **ticket 80 mm** ; les
factures d'un autre magasin sont inaccessibles (contrôle `magasin_id`).

### 6.5 Articles & catégories (`articles.php`, `categories.php`)
- Fiche article : nom, référence, **code-barres (générateur EAN-13 intégré)**,
  prix d'achat/vente, **TVA multiple** (taux par article + taux par défaut),
  seuil d'alerte, **DLC/DDM optionnelle** (lot), image, catégorie, actif.
- Recherche AJAX (code-barres / nom) via l'API.
- Catégories activables/désactivables (jamais supprimées si utilisées).
- **Vente au poids** : la structure est prête (`articles.vente_au_poids`,
  `unite_mesure`, `poids_precision` via la migration `unite_mesure.sql`) mais la
  saisie décimale en caisse n'est pas encore branchée — les quantités restent
  des nombres entiers.

### 6.6 Stock multi-magasins (`stock.php`, `mouvements.php`)
- **Stock par magasin** (`stock_magasins`) + stock global (`articles.quantite_stock`).
- **Mouvements** `Entree` / `Sortie` / `Vente` / `Ajustement` / `Transfert` /
  `Retour_stock` avec motif, référence fournisseur, numéro de facture.
- Entrées multi-lots : saisie d'un lot par DLC/DDM → **FEFO** à la sortie.

### 6.7 Lots & péremptions (`peremptions.php` — FEFO)
- Sorties selon **FEFO** (first-expired, first-out) en écrasant une quantité
  (l'article expire en premier s'écoule en premier).
- Alertes DLC : articles **expirés** (péremption bloquante en caisse) et
  **urgents** (J-14 configurable, message en caisse).
- Impacts sur suggestions d'achat (solde "consommable" au lieu du stock brut).

### 6.8 Clôture de caisse (`cloture.php` — Z de caisse journalier)
- Par magasin : montant attendu (somme des ventes Payée de la journée), montant
  réel saisi, écart calculé, statut.
- Une facture ne peut pas être annulée après clôture (règle métier).
- Historique des clôtures filtrable par période + audit trail.

### 6.9 Achats fournisseurs (`commandes_fournisseur.php`)
Cycle complet : **Brouillon → En_Attente (soumission) → Envoyee (validation)
→ Recue / Recue_Partielle**, plus **Annulee** (rejet → retour au brouillon).
- Fournisseurs (`fournisseurs.php`) : CRUD, coordonnées et, le cas échéant,
  **devise de facturation** (achats multi-devises, taux de change appliqué à la
  réception).
- Création manuelle ou **pré-remplie** depuis les suggestions d'achat.
- **Réception partielle ou totale** : réintègre le stock (mouvement `Entree`,
  multi-lots DLC) et vérifie le fournisseur de la commande.
- Validation (`achats_valider`) : Directeur uniquement.
- Le magasin est imposé sur toute action (création/édition/validation/réception).

### 6.10 Suggestions d'achat (`suggestions_achat.php`)
Articles sous le seuil d'alerte (stock consommable = stock global − DLC bloquées),
regroupés par fournisseur, avec quantité à commander suggérée. Génère les
étiquettes et crée la commande fournisseur pré-remplie.

### 6.11 Inventaire physique (`inventaire.php`)
- Sessions : **En cours → Validé / Annulé**, référence unique, magasin imposé.
- Saisie des comptages par article (écart = compté − théorique).
- **Validation** : applique les écarts en stock (mouvement `Ajustement` +
  journalisation), en transaction.
- Historique filtrable + export CSV.

### 6.12 Retours & avoirs (`retours.php`)
- Retour sur **facture payable** : jusqu'à 100 % des quantités vendues, avec
  motif, réintégration automatique du stock (mouvement `Retour_stock`).
- Avoirs (montants) et retours (quantités) tracés (`retours_factures`,
  `lignes_retour`), statut Valide / Annule.

### 6.13 Promotions & codes promo (`promotions.php`)
- **Type** : pourcentage ou **montant fixe** ; ciblage **article** ou
  **catégorie** ; conditions : montant minimum d'achat, dates (début/fin),
  limite d'utilisations, activable/désactivable.
- Code promo unique (MAJUSCULE) → application automatique dans la caisse.
- **Ventes Flash** (`regles_promotions`, gérées dans `parametres.php` — onglet
  Promotions) : règles de remise automatiques appliquées en caisse par
  `db_calculer_prix_article()` (api/scan, api/caisse/sync, valider_facture.php)
  quand un lot approche de sa péremption (`PEREMPTION_PROCHE`) ou qu'un article
  est en surstock (`SURSTOCK`). La règle la plus forte l'emporte.

### 6.14 Étiquetage rayon (`etiquettes.php` + `etiquettes_print.html.twig`)
Sélection d'articles → génération **EAN-13** (JsBarcode) → étiquettes
imprimables au format A4 (page dédiée, impression propre).

### 6.15 Transferts inter-magasins (`transferts.php`)
- Expédition par le magasin émetteur (mouvement `Transfert`, stock sortant),
  réception par le magasin destinataire (stock entrant).
- Historique des transferts par statut (Expédié / Reçu) — le magasin est imposé.

### 6.16 Dépenses (`depenses.php`)
- Charges d'exploitation : libellé, catégorie, montant, date, justificatif
  (texte), magasin imposé (même pour le Directeur en suivi).
- Agrégé dans le **compte de résultat** (CA − Achats de la période − Dépenses).

### 6.17 Statistiques & compte de résultat (`statistiques.php`)
- Période au choix, magasin de suivi.
- CA (factures Payée), nombre de ventes, panier moyen, **marge brute** et taux,
  **TVA collectée** (régime TVA uniquement — en TPU, la ligne affiche
  « non applicable »), **top ventes / top produits**, répartition paiements,
  **compte de résultat** (CA, achats de la période, dépenses, résultat net),
  graphiques Chart.js, export CSV.
- **Valorisation des stocks (CUMP)** : chaque entrée actualise le coût moyen
  pondéré (`articles.cump`, couches `article_couts` consommées FIFO en sortie) ;
  la **CAMV** est valorisée au CUMP courant (fallback `prix_achat`). C'est une
  valorisation **comptable** — à distinguer du **FEFO** (6.7) qui gère la
  **péremption** des lots DLC/DDM à la sortie, et non leur coût.

### 6.18 Utilisateurs & magasins (`utilisateurs.php`, `magasins.php`)
- Utilisateurs : nom, prénom, identifiant, mot de passe (BCRYPT), **rôle**,
  **magasin d'affectation** (obligatoire), actif, dernière connexion.
- Magasins : nom, adresse, **NIF / RCCM** (repris sur le ticket et la facture
  de ce point de vente), actif/inactif (un magasin désactivé est verrouillé
  pour ses utilisateurs).

### 6.19 Paramètres (`parametres.php`)
- **Boutique** : nom, slogan, devise (symbole, position, code, décimales),
  **NIF** (OTR) et **RCCM**, **régime fiscal** (TPU — factures hors taxes avec
  mention « TVA non applicable » — ou TVA classique, taux par défaut,
  exonérations par article), préfixe des numéros de facture, avertissement si
  NIF/RCCM manquants (factures non conformes).
- **Ticket** : format (80 mm), en-tête, remarque, paiements rapides (1/2/3).
- **RBAC** : gestion des permissions par rôle (matrice interactive, voir §7).
- **Thème** : couleur de l'interface (indigo par défaut).

### 6.20 Journal d'activité (`audit.php`)
Audit trail complet (actions, utilisateur, magasin, IP, date) : connexions,
ventes, encaissements, modifications articles/stock, commandes, inventaires,
retours, promotions, transferts, clôtures, gestion utilisateurs/permissions.
**Jamais de données sensibles** (mots de passe, jetons) dans les logs.

### 6.21 Notifications par e-mail (`emails_queue` + worker CLI)
Les e-mails passent par une file d'attente (`emails_queue`) : l'application
insère uniquement le message (jamais de blocage de la requête utilisateur), puis
`bin/emails_worker.php` (CLI / cron) envoie via `mail()` avec retries (3 max).
`envoyer_email_enqueue()` remplace les envois synchrones de
`envoyer_email_notification()` (rétrocompatible : si `emails_queue` absente,
repli sur `mail()`).

### 6.22 Clients & fidélité (`clients.php`, `carte_fidelite.php`, `desinscription.php`)
- Fiche client : NIF / RCCM (B2B), téléphone, e-mail, points de fidélité
  (historique `historique_points`), carte avec QR code, suggestion d'achats
  (`suggestions_achat.php`).
- **Consentement** au programme de fidélité (opt-in, switch en caisse et
  `consentements_log`), désinscription par e-mail via lien signé HMAC.
- **Purge de conservation** : anonymisation des clients sans achat depuis
  N mois sans consentement actif, suppression des fiches anonymisées > 12
  mois sans facture (manuel dans l'UI ou planifié `bin/purge_donnees_clients.php`).

### 6.23 Traçabilité des ventes (`conformite.php`)
- Chaînage cryptographique SHA-256 des factures et clôtures (hash incluant le
  précédent, écrit dans la même transaction), triggers SGBD d'immuabilité
  (aucune mise à jour / suppression d'écritures validées).
- **Vérification d'intégrité** (page + audit CLI `bin/verif_integrite.php`) et
  rattrapage idempotent des factures antérieures à l'activation.
- **Archives périodiques** : export JSON signé HMAC par période/magasin,
  conservation paramétrable (≥ 6 ans par défaut). Détails :
  `docs/conformite/integrite.md`.

### 6.24 Export comptable SYSCOHADA (`export_syscohada.php`)
- Écritures SYSCOHADA révisé (en-tête PCEC) pour le comptable : journaux
  **VT** (ventes), **AC** (achats fournisseurs), **DG** (dépenses) et **INV**
  (variation de stocks au CUMP) ; annulations contre-passées ; compte 4457
  (TVA) alimenté uniquement en régime TVA, aucun en TPU. CSV UTF-8, période et
  magasin au choix, permission dédiée (`conformite_export_syscohada`).
- **Couverture DSF** : achats (607 / 401), ventes (707 / 53 caisse ·
  58 mobile money · 512 banque selon le mode de paiement), stocks (31 / 6037),
  dépenses (comptes 6xx) — de quoi établir la déclaration statistique et
  fiscale SYSCOHADA. Préparation e-facturation OTR : `docs/conformite/otr.md`.

### 6.25 2FA TOTP (`includes/helpers.php`)
Helpers génération/vérification TOTP (`totp_generer_secret`,
`totp_calculer_code`, `totp_verifier_code`) + colonnes `totp_secret` /
`totp_actif` sur `utilisateurs`. À activer par interface (profil) en production.

### 6.26 Exports CSV (`exports.php`)
Exports UTF-8 (séparateur `;`) depuis l'interface : articles, factures,
mouvements de stock, inventaires — période et magasin au choix.

---

## 7. Rôles & permissions (RBAC)

Le contrôle d'accès est **dynamique** : tables `permissions` + `role_permissions`
(pivot) ; l'interface **Paramètres → Permissions** permet de modifier la matrice
sans toucher au code.

Utilisation en PHP : `peut('cle_permission')`, `exiger_permission('...')`,
`peut_facturer()`, `peut_gerer_stock()`. En Twig : `{% if peut('cles') %}`,
`peut_facturer`, `can_manage_stock`, etc.

### Permissions disponibles (par catégorie)

| Catégorie | Permission | Description |
|---|---|---|
| articles | `articles_consulter` | Consulter la liste des articles |
| articles | `articles_gerer` | Créer / Modifier / Supprimer des articles |
| caisse | `cloture_gerer` | Effectuer la clôture de caisse |
| config | `audit_consulter` | Consulter le journal d'audit |
| config | `parametres_gerer` | Modifier les paramètres de la boutique |
| config | `statistiques_consulter` | Consulter les statistiques |
| depenses | `depenses_consulter` / `depenses_gerer` | Consulter / Saisir les dépenses |
| facturation | `caisse_gerer` | Utiliser la caisse (POS) |
| facturation | `facturation_consulter` / `facturation_gerer` | Consulter / Créer & annuler des factures |
| fournisseurs | `fournisseurs_consulter` / `fournisseurs_gerer` | Consulter / Gérer les fournisseurs |
| Logistique | `achats_consulter` / `achats_gerer` / `achats_valider` | Consulter / Gérer / Valider les commandes fournisseurs |
| Logistique | `inventaire_consulter` / `inventaire_gerer` | Consulter / Créer & valider les inventaires |
| magasins | `magasins_consulter` / `magasins_gerer` | Consulter / Gérer les magasins |
| stock | `stock_consulter` / `stock_gerer` / `stock_transfert` | Consulter / Gérer le stock / Transferts |
| utilisateurs | `utilisateurs_consulter` / `utilisateurs_gerer` | Consulter / Gérer les utilisateurs |
| Ventes | `promotions_consulter` / `promotions_gerer` | Consulter / Gérer promotions & codes promo |
| Ventes | `retours_consulter` / `retours_gerer` | Consulter / Traiter les retours & avoirs |

### Matrice par défaut

| Permission | Directeur | Admin | Magasinier | Vendeur |
|---|:-:|:-:|:-:|:-:|
| articles_consulter / articles_gerer | ✅ | ✅ | ✅ | consulter seul |
| caisse_gerer / cloture_gerer | ❌ | ❌ | ❌ | ✅ |
| facturation_consulter / gerer | ✅ | ✅ | consulter | ✅ |
| stock_consulter / stock_gerer | ✅ | ✅ | ✅ | consulter seul |
| stock_transfert | ✅ | ✅ | ✅ | ❌ |
| fournisseurs_consulter / gerer | ✅ | ✅ | ✅ | ❌ |
| achats_consulter / achats_gerer | ✅ | ✅ | ✅ | ❌ |
| achats_valider | ✅ | ❌ | ❌ | ❌ |
| inventaire_consulter / gerer | ✅ | ✅ | ✅ | ❌ |
| retours_consulter / gerer | ✅ | ✅ | ❌ | ✅ |
| promotions_consulter / gerer | ✅ | ✅ | ❌ | ✅ |
| depenses_consulter / gerer | ✅ | ✅ | ✅ | ❌ |
| magasins_consulter / gerer | ✅ | ✅ | consulter | ❌ |
| utilisateurs_consulter / gerer | ✅ | ✅ | ❌ | ❌ |
| parametres_gerer | ✅ | ✅ | ❌ | ❌ |
| statistiques_consulter | ✅ | ✅ | ✅ | ✅ |
| audit_consulter | ✅ | ❌ | ❌ | ❌ |

### Multi-magasins & rôle

- Tout utilisateur est affecté à un **magasin d'affectation**.
- Le magasin « actif » de travail : `user_magasin_id()` = magasin choisi en
  session (le Directeur peut en changer via le sélecteur de la topbar) sinon
  magasin d'affectation, sinon 1.
- La quasi-totalité des pages/API/queries sont **filtrées par magasin actif**
  (caisse, factures, stock, mouvements, dépenses, statistiques, suggestions…).
  Le Directeur/Admin conserve la consultation multi-magasins dans certaines vues
  (ex. `stock.php?magasin_id=…`).

---

## 8. Architecture technique

### Vue d'ensemble

```
Navigateur ──▶ Apache (RewriteRule) ──▶ index.php (routeur chiffré)
                                              │
                        ┌─────────────────────┼──────────────────────┐
                        ▼                     ▼                      ▼
                 Pages applicatives      api/index.php         login.php / logout.php
                 (PHP + Twig)            (REST JSON)           (pages directes)
                        │                     │
                        ▼                     ▼
                 config/connexion.php · includes/db_functions.php · includes/helpers.php
                        ▼
                   MySQL (127.0.0.1)
```

- **PHASE 1** — `config/connexion.php` se charge en premier : variables
  d'environnement, PDO (`EMULATE_PREPARES => false`), session sécurisée,
  fonctions d'auth (login/logout guard), CSRF, chargement permissions RBAC
  (cache session), Twig (variables globales + helpers), pagination.
- **PHASE 2** — `config/parametres.php` : charge les paramètres boutique dans
  `$_SESSION['_params']` (crée la table + seed si absente).
- **PHASE 3** — la page applicative : garde d'accès (`exiger_permission`,
  `peut`), traitement POST (CSRF → validation → transaction), rendu Twig
  (`base.html.twig`).

### Routage & URLs signées
- Accès direct aux `.php` (toutes sécurisées par session + permissions) et
  routeur `/index.php?route=<token>` (chiffrement AES-256-CBC + HMAC).
- Toute URL embarquant un `?id=` doit être générée par
  `generate_signed_url(page, id, params)` (HMAC-SHA256, expiration 24 h) et
  vérifiée par `verify_url_signature()` avant usage.

### Templates Twig
- `templates/base.html.twig` : layout maître (sidebar en 4 sections pliables,
  topbar, flash messages, CSP nonce).
- Variables globales : `base_url`, `app_nom`, `current_user`, `current_role`,
  `permissions`, `csrf_token`, `csp_nonce_val`, `page_courante`, `nav_sections`,
  `nb_alertes_*` (par magasin actif), `flash_messages`.
- Helpers : `h()`, `money()`, `date_fr()`, `csrf_field()`, `peut()`, liens
  signés `path_*` / `generate_signed_url` en Twig.
- Autoescaping HTML activé.

### Pages PHP « legacy » (header.php)
Quelques pages (`stock.php`, `facture_view.php`, `ticket_print.php`,
`scan_ajax.php`, `valider_facture.php`, `suggestions_impression.php`,
`exports.php`, `profil.php`, `cloture.php` pour partie) utilisent
`includes/header.php` : **le menu et la
topbar y sont construits à l'identique** des vues Twig (4 sections pliables,
mêmes conditions rôle) — toute modification de navigation doit être reproduite
**dans les deux layouts**.

### JavaScript
- `assets/js/app.js` : sidebar (sections pliables mémorisées), filtres de
  recherche, badges, estampilles.
- `assets/js/caisse.js` : scan code-barres, panier, totaux, paiements multi-
  modes, file d'attente hors-ligne (`localStorage`), synchro `api/caisse/sync`,
  raccourcis clavier.

---

## 9. Base de données

### Tables (35)

Liste générée automatiquement — voir `bin/inventaire_tables.php` (compare les
scripts `database/*.sql` avec la base réelle, sortie `--md` lisible).

| Table | Rôle |
|---|---|
| `archives_caisse` | Archives périodiques de caisse (conservation ≥ 6 ans) |
| `article_couts` | Couches de coût (FIFO / CUMP) — valorisation comptable |
| `article_lots` | Lots DLC/DDM par article (FEFO) |
| `articles` | Articles (CUMP, valeur_stock, unite_mesure, vente_au_poids) |
| `categories` | Référentiel produits |
| `clients` | Clients (fidélité + consentement, anonymisation) |
| `clotures_caisse` | Z de caisse journaliers (chaînés cryptographiquement) |
| `commandes_fournisseur` | Commandes d'achat (devise + taux de change) |
| `consentements_log` | Registre des consentements (traçabilité) |
| `depenses` | Dépenses d'exploitation |
| `emails_queue` | File d'attente des e-mails (worker CLI) |
| `factures` | Ventes (hash chaîne, horodatage, B2B, soft-delete) |
| `fournisseurs` | Fournisseurs (devise de facturation) |
| `historique_points` | Historique des opérations de points fidélité |
| `inventaires` / `inventaire_lignes` | Sessions d'inventaire physique |
| `lignes_commande_fournisseur` | Lignes de commande fournisseur |
| `lignes_facture` | Lignes de facture (immuables, triggers) |
| `lignes_retour` | Lignes de retour SAV |
| `login_attempts` | Rate limiting connexion |
| `logs_activite` | Journal d'audit |
| `magasins` | Points de vente (multi-magasins) |
| `mouvements_stock` | Historique des mouvements de stock |
| `paiements_facture` | Paiements multi-modes (immuables) |
| `parametres` | Paramètres boutique (devise, TVA, conformité) |
| `permissions` / `role_permissions` | Catalogue des permissions + matrice RBAC |
| `promotions` | Codes promo & remises ciblées |
| `regles_promotions` | Ventes Flash : règles de remise auto (péremption proche / surstock) |
| `retours_factures` | Retours / avoirs SAV |
| `sequences` | Compteurs atomiques (numérotation factures) |
| `stock_magasins` | Stock par magasin (+ valeur comptable) |
| `transferts_stock` | Transferts inter-magasins |
| `utilisateurs` | Comptes utilisateurs (rôles + magasin affecté) |
| `__test_group` | Table technique de test (présente en base, hors scripts) |

### Fichiers SQL

| Fichier | Contenu |
|---|---|
| `database/estock_db (1).sql` | Dump initial complet (schéma + données) — base de référence |
| `database/apply_migrations.php` | **Migrateur CLI** — applique toutes les migrations (catégories, TVA multiple, commandes fournisseur, inventaires, retours, promotions, dépenses, multi-magasins, lots FEFO, clôtures, offline sync, permissions, sécurité, intégrité des ventes, données clients, fidélité, unités, archives) idempotentes |
| `database/*.sql` | Migrations individuelles (importables à la main) |

**Génération de l'inventaire** : `php bin/inventaire_tables.php` (comparaison
scripts ↔ base réelle) et `php bin/inventaire_tables.php --md` (liste Markdown
pour ce README).

**Fonctions SQL principales** (`includes/db_functions.php`, 180+ fonctions) :
`db_article_*`, `db_user_*`, `db_facture_*`, `db_stock_*`, `db_mouvement_*`,
`db_lot_*` (FEFO), `db_commande_fournisseur_*`, `db_inventaire_*`,
`db_retour_*`, `db_promotion_*`, `db_transfert_*`, `db_depense_*`,
`db_magasin_*`, `db_stats_*`, `db_journal_*` (exports comptables), `db_client_*`
(fidélité, consentements), `db_archives_*` (traçabilité). Les recherches
renvoient `['sql', 'params']` pour la pagination (ex. `db_articles_search_sql()`).

### Transactions & intégrité
- Écritures multi-tables via `db_transaction(callback, ok, err, redirect)`.
- Ventes / inventaires / réceptions / transferts : `SELECT ... FOR UPDATE` sur
  les lignes de stock (prévention des courses critiques).

---

## 10. Sécurité

| Mécanisme | Détail |
|---|---|
| Sessions | Cookie `HttpOnly` + `SameSite=Lax` + `Secure` (HTTPS auto), `session_regenerate_id()` |
| Mots de passe | BCRYPT (coût 12) |
| CSRF | Jeton de session, `csrf_guard()` sur chaque POST + `csrf_field()` dans les formulaires |
| RBAC | Permissions dynamiques par rôle (cache session + périmètre magasin) |
| URLs signées | HMAC-SHA256 + AES-256-CBC ; expiration 24 h ; `verify_url_signature()` |
| SQLi | 100 % requêtes préparées (`EMULATE_PREPARES => false`) |
| XSS | Échappement `h()` partout, autoescaping Twig |
| CSP | En-tête Content-Security-Policy avec nonce (`csp_nonce()` exigé sur les scripts inline) |
| En-têtes HTTP | `includes/security_headers.php` : X-Frame-Options, X-Content-Type-Options, Referrer-Policy |
| Brute-force | Table `login_attempts` : rate limiting (délai progressif après échecs) |
| Audit | `suivre_activite()` sur toutes les actions sensibles (sans données sensibles) |
| Proxy | IP réelle derrière reverse proxy (helpers `get_client_ip()`) |
| 2FA | Helpers TOTP prêts (colonnes `totp_secret` / `totp_actif`) |
| Journalisation erreurs | `error_log()` uniquement (jamais `getMessage()` vers l'utilisateur) |

---

## 11. API REST

Routeur : `api/index.php` — réponses `application/json`, auth par session
(connexion obligatoire), permissions et **magasin actif** appliqués.

| Endpoint | Méthode | Rôle |
|---|---|---|
| `api/articles?search=` | GET | Vendeur+ |
| `api/scan?code=` | GET | Vendeur+ (caisse) |
| `api/stats` | GET | Dir./Admin/Magasinier |
| `api/peremptions` | GET | Vendeur+ |
| `api/caisse/status` | GET | Vendeur+ (état stock + config caisse) |
| `api/clients/search?q=` | GET | Vendeur+ (recherche client — renvoie aussi `nif` / `rccm` pour l'alerte facture B2B) |
| `api/caisse/sync` | POST | Vendeur+ (synchronisation ventes hors-ligne) |
| `api/export/*` | GET | selon permission |

Codes HTTP : `200` OK, `400` requête invalide, `401` non connecté, `403`
permission refusée, `404` introuvable, `500` erreur (message générique +
`error_log`).

---

## 12. Mode hors-ligne (offline sync)

- Détection de connexion (`navigator.onLine` + heartbeat) ; badge dans la topbar.
- Ventes encaissées hors-ligne : queue dans `localStorage`
  (`eStock_sales_queue`), validation locale différée.
- Synchronisation automatique au retour en ligne via `api/caisse/sync`
  (idempotent grâce à `client_sale_id` sur `factures`).
- Les ventes synchronisées sont ensuite traitées comme des ventes classiques
  (stocks, mouvements, clôture, audit).

---

## 13. Guide développeur (conventions)

1. **PHP procédural** — pas de classes, pas de namespaces. Chaque fonction
   reçoit `$pdo` en **premier paramètre** (interdit : `global $pdo`).
2. **Requêtes préparées uniquement** ; attention `EMULATE_PREPARES=false` :
   pas de placeholder dupliqué ni `INTERVAL :x` dans une même requête
   (interpoler les entiers castés).
3. **Constantes de rôles** : `ROLE_DIRECTEUR`, `ROLE_ADMIN`, `ROLE_MAGASINIER`,
   `ROLE_VENDEUR` ; préfixe des fonctions par entité (`db_article_*`).
4. **Pattern POST obligatoire** :
   `csrf_guard()` → `extract_post_data()` (validation types/longueurs) →
   `db_transaction()` (écriture) → message flash + redirect.
5. **URLs signées** pour tout `?id=` : `generate_signed_url()` /
   `verify_url_signature()`.
6. **Sortie HTML** : `h()` (échappement). **Erreurs utilisateur** : messages
   génériques + `error_log()`.
7. **Audit** : `suivre_activite('ACTION', 'Description')` sur toute action
   sensible (jamais de mot de passe/jeton).
8. **Stock** : verrous `FOR UPDATE` ; ne jamais ajouter de mouvement « stock »
   redondant là où `db_article_insert()` initialise déjà le stock.
9. **Filtrage magasin** : utiliser `user_magasin_id()` comme périmètre par
   défaut dans toute requête métier.
10. **Templates Twig** : `titre_page` obligatoire dans chaque render (topbar).
11. **Deux layouts à maintenir en miroir** : `includes/header.php` et
    `templates/base.html.twig`.
12. **Nonce CSP** sur tout `<script>` inline : `<script nonce="<?= h(csp_nonce()) ?>">`.
13. **Options PDO** : `ATTR_EMULATE_PREPARES => false`, `ATTR_ERRMODE =>
    ERRMODE_EXCEPTION`, `ATTR_DEFAULT_FETCH_MODE => FETCH_ASSOC`.

### Vérifications avant livraison
```bash
# Contrôle syntaxique de tout le projet
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }

# Tests CLI (résultats) — ex. matrice RBAC, requêtes par magasin
php -r "require 'config/connexion.php'; var_dump(peut('articles_gerer'));"
```

---

## 14. Outils de maintenance

| Outil | Usage |
|---|---|
| `bin/backup_db.php` | Sauvegarde CLI de la base : `php bin/backup_db.php` (dump SQL zippé + rotation 30 j) — penser au cron quotidien |
| `bin/test_restore.php` | Test de restauration automatique : `php bin/test_restore.php` (restaure la dernière sauvegarde sur une base de test `estock_db_test` et vérifie l'intégrité) |
| `bin/emails_worker.php` | Worker de la file d'attente e-mails : `php bin/emails_worker.php` (cron toutes les minutes recommandé) |
| `bin/purge_donnees_clients.php` | Purge programmée : `php bin/purge_donnees_clients.php` (anonymisation clients sans consentement actif > 36 mois, puis suppression des fiches anonymisées > 12 mois) |
| `bin/verif_integrite.php` | Audit CLI de la chaîne cryptographique : `php bin/verif_integrite.php` (rapport texte / `--json`, code retour 0/1/2) |
| `bin/create_admin.php` | Création unique du premier compte Directeur après import : `php bin/create_admin.php "Nom" login "mot-de-passe"` |
| `bin/disable_demo_accounts.php` | Désactive les comptes de démonstration après création d'un Directeur distinct |
| `bin/preflight_release.php` | Contrôle non destructif avant remise : configuration, extensions, tables, comptes actifs et sauvegardes |
| `bin/inventaire_tables.php` | Inventaire réel des tables : `php bin/inventaire_tables.php` (comparaison scripts ↔ base) / `--md` pour la liste README |
| `database/apply_migrations.php` | Migrateur idempotent : `php database/apply_migrations.php` |
| `exports.php` | Exports CSV UTF-8 depuis l'interface (articles, factures, mouvements, inventaires) |
| `export_syscohada.php` | Export SYSCOHADA (écritures comptables OHADA) depuis l'interface |
| `vendor/bin/phpunit` | Suite de tests : `vendor\bin\phpunit` (unitaire + intégration sur `estock_db_test`, jamais la production) |

---

## 15. Dépannage & FAQ

**« Connexion refusée » / la base n'existe pas**
→ Vérifier `DB_PASS` ; importer `database/estock_db (1).sql` puis lancer
`php database/apply_migrations.php`.

**La page affiche « route invalide »**
→ L'URL signée a expiré (> 24 h) : revenir par le menu de navigation.

**Le scan ne fonctionne pas**
→ Vérifier que la douchette envoie bien `Enter` ; cliquer dans le champ scannage
(deuxième douchette : mode keyboard wedge). La recherche est en AJAX (`api/scan`).

**Le stock ne baisse pas après une vente**
→ Une vente insère facture + lignes + paiements + mouvement `Vente` : vérifier
`mouvements_stock` et « factures Payée ». En cas d'échec, la transaction entière
est annulée (aucune écriture partielle).

**Un utilisateur voit « Accès refusé »**
→ Vérifier son rôle et la matrice **Paramètres → Permissions** (RBAC dynamique) ;
vérifier son **magasin d'affectation** et que ce magasin est actif.

**Le Directeur ne voit pas le bon magasin**
→ Utiliser le sélecteur de magasin de la topbar (`auth/choisir_magasin.php`) ;
le magasin actif est mémorisé en session.

**Où sont les logs ?**
→ Actions journalisées : interface **Journal d'activité** (table
`logs_activite`). Erreurs techniques : `error_log` du serveur web.

**Comment mettre à jour une base existante ?**
→ `php database/apply_migrations.php` (toutes les migrations sont idempotentes).

---

© eStock — Application de gestion de stock & facturation (POS). Documentation
complète ; la page intégrée **Documentation** (menu latéral) guide chaque
utilisateur selon son rôle.
