# eStock · Application de gestion de stock & facturation (POS)

Application web universelle (responsive) de gestion de stock et de facturation
de type supermarché, pensée pour une utilisation au **comptoir (ordinateur)**
et en **rayon (mobile)** via une douchette / lecteur de code-barres.

- **Backend** : PHP pur (procédural, sans framework, sans MVC)
- **Moteur de templates** : Twig 3.x (via Composer)
- **Base de données** : MySQL (compatible WampServer)
- **Frontend** : HTML5, CSS3, JavaScript natif (Vanilla JS), Bootstrap 5 (CDN)

---

## 1. Installation (3 étapes)

1. **Copier le projet** dans `C:\wamp64\www\eSotck` (déjà fait).

2. **Créer la base de données** — deux options au choix :
   - **Recommandé** : ouvrez `http://localhost/eSotck/installer.php`
     (crée la base, les tables ET les 3 utilisateurs avec de vrais mots de
     passe hachés en BCRYPT). **Supprimez ensuite `installer.php`.**
   - **Manuel** : importez `database/schema.sql` dans phpMyAdmin, puis créez
     les utilisateurs via l'interface (l'application génère le hash).

3. **Vérifier la connexion** dans `config/connexion.php` (ou via variables d'environnement) :
    ```php
    // Via variables d'environnement (recommandé en production)
    define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
    define('DB_NAME', getenv('DB_NAME') ?: 'estock_db');
    define('DB_USER', getenv('DB_USER') ?: 'root');
    define('DB_PASS', getenv('DB_PASS') ?: 'admin');
    ```

4. **Configurer la clé HMAC** pour les URLs signées (variable d'environnement, optionnel en dev) :
    ```bash
    # Dans votre fichier .env ou httpd.conf
    SECRET_URL_KEY=votre_cle_secrete_tres_longue_ici
    ```
    > Sans cette variable, la clé par défaut est utilisée (à changer en production).

Puis ouvrez : **http://localhost/eSotck/**

> Le routeur chiffré est automatiquement activé. Toutes les URLs de navigation
> utilisent maintenant `/?route=<token>` au lieu de `page.php?id=...`.

### Comptes par défaut
| Rôle        | Login    | Mot de passe | Accès |
|-------------|----------|--------------|-------|
| Directeur   | `admin`  | `admin123`   | Tout  |
| Magasinier  | `magasin`| `magasin123` | Articles, Fournisseurs, Mouvements |
| Vendeur     | `vendeur`| `vendeur123` | Caisse, Factures, Consultation stock |

> ⚠️ **Changez ces mots de passe** après installation.

---

## 2. Arborescence du projet

```
eSotck/
├── index.php              # Front controller (routeur chiffré AES-256-CBC)
├── .htaccess              # Rewrite rules → toutes les requêtes → index.php
├── composer.json          # Dépendances PHP (Twig)
├── login.php              # Authentification (page accessible directement)
├── logout.php             # Déconnexion (page accessible directement)
├── installer.php          # Installation en 1 clic (À SUPPRIMER ensuite)
│
├── api/
│   └── index.php          # Routeur API REST centralisé (JSON)
│
├── tableau_bord.php       # Dashboard + alertes de stock faible
├── articles.php           # CRUD articles (Magasinier+) — recherche AJAX via API
├── fournisseurs.php       # CRUD fournisseurs
├── mouvements.php         # Historique filtrable + saisie Entrée/Sortie
├── stock.php              # Consultation du stock (Vendeur)
├── caisse.php             # Interface POS + lecteur code-barres
├── scan_ajax.php          # Recherche article par code-barres (JSON) — déprécié, utiliser api/scan
├── valider_facture.php    # Enregistre la vente (transactionnelle)
├── factures.php           # Historique des factures (recherche)
├── facture_view.php       # Consultation + ticket imprimable (URL signée HMAC)
├── suggestions_achat.php  # Alertes stock : articles par fournisseur (Directeur/Magasinier)
├── utilisateurs.php       # Gestion des utilisateurs (Directeur/Admin)
├── profil.php             # Profil personnel : changement de mot de passe
├── audit.php              # Journal d'activité (Directeur uniquement)
├── parametres.php         # Personnalisation boutique, devise, TVA, ticket
├── transferts.php         # Transferts inter-magasins (Directeur/Admin/Magasinier)
├── depenses.php           # Gestion des dépenses d'exploitation (Directeur/Admin/Magasinier)
├── statistiques.php       # Tableau de bord analytique + compte de résultat
│
├── templates/
│   ├── base.html.twig                    # Layout maitre (sidebar + topbar + footer)
│   ├── suggestions_achat.html.twig       # Page alertes stock
│   ├── profil.html.twig                  # Page profil utilisateur
│   ├── audit.html.twig                   # Page journal d'activité
│   └── transferts.html.twig              # Page transferts inter-magasins
│   └── depenses.html.twig                # Page gestion des dépenses
├── vendor/                # Dépendances Composer (non versionné)
├── config/
│   ├── connexion.php      # PDO + sessions + helpers + gestion des rôles
│   └── parametres.php     # Cache paramètres boutique (auto-install)
├── includes/
│   ├── header.php         # En-tête HTML + menu selon le rôle
│   ├── footer.php         # Pied de page + scripts JS
│   ├── acces_refuse.php   # Page 403
│   ├── security_headers.php # En-têtes HTTP de sécurité
│   ├── db_functions.php   # Fonctions centralisées d'accès aux données
│   └── helpers.php        # Validation, logique métier, rendu HTML réutilisables
├── assets/
│   ├── css/style.css      # Styles communs + impression ticket
│   └── js/
│       ├── app.js         # JS global
│       └── caisse.js      # Logique scan code-barres + panier
├── database/
│   ├── schema.sql         # Script de création de la base
│   ├── login_attempts.sql # Table pour rate limiting login
│   ├── audit_trail.sql    # Table journal d'activité (audit trail)
│   ├── multi_magasin.sql  # Migration multi-magasins (magasins, stock_magasins, transferts_stock)
│   ├── offline_sync.sql   # Migration mode hors-ligne (client_sale_id sur factures)
│   ├── depenses.sql       # Table des dépenses d'exploitation (charges)
│   └── indexes_perf.sql   # Index de performance (à exécuter sur base existante)
└── README.md
```

---

## 3. Logique du lecteur de code-barres (mode supermarché)

Le cœur de la caisse repose sur **3 fichiers** :

### `caisse.php` — la vue
- Contient l'`<input id="scanInput">` qui **garde le focus en permanence**
  (re-focus automatique au clic/blur).

### `caisse.js` — la logique (Vanilla JS)
1. Écoute l'événement `keydown` sur le champ de scan.
2. Quand la **douchette simule la touche `Enter`**, on récupère le code saisi.
3. Requête **AJAX (fetch)** vers `scan_ajax.php?code=XXXX`.
4. Si l'article existe → ajout au panier (ou incrémentation) + recalcul des
   totaux **en temps réel**, sans rechargement de page.
5. Le champ est **vidé** et reprend le focus pour le scan suivant.
6. Raccourcis clavier : `F2` = vider le panier, `F8` = valider.

### `scan_ajax.php` — le service
- Reçoit un code-barres, renvoie du **JSON** (`found`, `article{...}` ou `message`).

### `valider_facture.php` — l'enregistrement
- Opération **transactionnelle** : génère le N° de facture, vérifie les stocks
  (`FOR UPDATE`), insère facture + lignes, **décrémente le stock** et crée un
  **mouvement de stock de type `Vente`** pour l'historique.

---

## 4. Architecture des données (`includes/db_functions.php`)

Toutes les requêtes SQL sont centralisées dans **`includes/db_functions.php`**. Les listes paginées utilisent les variantes `_sql()` qui retournent SQL+params pour `paginate()`.

### Principes
- Chaque fonction reçoit **`$pdo` en premier paramètre** (pas de `global`).
- Toutes les requêtes utilisent des **statements préparés** (protection injection SQL).
- Les fonctions sont organisées par **entité** (articles, fournisseurs, utilisateurs, factures, mouvements).
- **LIMIT** sur toutes les fonctions non paginées (500 pour dropdowns, 200 pour listes, 50 pour alertes).

### Fonctions principales

| Catégorie | Fonctions |
|-----------|-----------|
| **Articles** | `db_article_code_barre_exists()`, `_insert()`, `_update()`, `_deactivate()`, `_get_by_id()`, `_get_by_barcode()`, `_get_for_update()`, `_get_for_mouvement()`, `_update_stock()`, `_decrement_stock()`, `db_articles_list_active()`, `_search_stock()`, `_search_sql()`, `_count_active()`, `_stock_value()`, `_low_stock()`, `_low_stock_with_supplier()`, `_low_stock_count()` |
| **Fournisseurs** | `db_fournisseur_insert()`, `_update()`, `_delete()`, `_get_by_id()`, `db_fournisseurs_list_with_count()`, `_list()` |
| **Utilisateurs** | `db_user_login_exists()`, `_insert()`, `_update_with_password()`, `_update_without_password()`, `_toggle_active()`, `_get_by_id()`, `_get_by_login()`, `db_users_list()` |
| **Factures** | `db_facture_count_by_prefix()`, `_number_exists()`, `_insert()`, `_get_by_id()`, `_get_lignes()`, `db_factures_search_sql()`, `_count_today()`, `_revenue_today()` |
| **Lignes facture** | `db_ligne_facture_insert()` |
| **Mouvements** | `db_mouvement_insert()`, `db_mouvements_recent()`, `_search_sql()` |
| **Login attempts** | `db_login_attempt_insert()`, `_count()`, `_clear()` |
| **Dépenses** | `db_depense_insert()`, `db_depenses_list()`, `db_depenses_total()`, `db_depenses_by_categorie()`, `db_depenses_daily()` |
| **Statistiques comptables** | `db_stats_period()`, `db_stats_top_articles()`, `db_stats_daily_sales()`, `db_stats_cout_achat_ventes()`, `db_stats_benefice_net()` |

### Exemple d'utilisation

```php
// Recherche paginée d'articles
$search_sql = db_articles_search_sql($search);
$result = paginate($search_sql['sql'], $search_sql['params'], 25);
$articles = $result['items'];
```

---

## 4bis. Helpers de traitement (`includes/helpers.php`)

Les blocs de validation, logique métier et rendu HTML répétés dans les pages sont centralisés dans **`includes/helpers.php`**.

### Fonctions de validation

| Fonction | Description |
|----------|-------------|
| `csrf_guard($url)` | Valide le token CSRF, flash_error + redirect si invalide |
| `extract_post_data($schema, $redirect)` | Extrait et nettoie les champs POST selon un schéma typé (string/int/float/bool) avec validation automatique (required, max, min, whitelist) |
| `validate_required_fields($data, $fields, $url)` | Vérifie que des champs requis sont remplis |
| `validate_field_lengths($data, $rules, $url)` | Vérifie la longueur max de champs texte via un schéma |
| `validate_uniqueness($fn, $msg, $url)` | Vérifie l'unicité via un callable, flash + redirect si doublon |

### Fonctions métier

| Fonction | Description |
|----------|-------------|
| `db_transaction($fn, $ok, $err, $url)` | Exécute une opération transactionnelle (beginTransaction/commit/rollback) avec messages standardisés |
| `process_stock_movement($pdo, $id, $type, $qty, $motif, $user)` | Crée un mouvement stock avec vérification du stock dispo |
| `calc_line_subtotal($prix, $qty)` | Calcule le sous-total d'une ligne de facture |
| `is_stock_low($stock, $seuil)` | Détecte si un article est en alerte de stock |
| `movement_type_color($type)` | Retourne la couleur Bootstrap pour un type de mouvement |
| `movement_type_sign($type)` | Retourne le signe + ou - pour un type de mouvement |
| `stock_badge($stock, $seuil)` | Retourne le badge HTML du stock avec couleur dynamique |
| `generate_restock_list_by_supplier($articles)` | Regroupe les articles en alerte par fournisseur_id (pour suggestions_achat.php) |
| `generate_invoice_number($pdo)` | Génère un numéro de facture unique (FAC-AAAAMMJJ-XXXX) |
| `role_badge_color($role)` | Retourne la couleur Bootstrap associée à un rôle |
| `user_can_edit_user($pdo, $target_id)` | Vérifie la hiérarchie des rôles (Admin ne peut pas modifier Directeur) |
| `build_filter_params($filters)` | Construit les paramètres de filtre en excluant les valeurs vides |

### Fonctions d'URL signées (HMAC-SHA256)

| Fonction | Description |
|----------|-------------|
| `generate_signed_url($base_url, $id, $extra)` | Génère une URL signée avec token HMAC (ex: `articles.php?id=5&token=abc...`) |
| `verify_url_signature($id, $token, $extra)` | Vérifie la validité du token HMAC (protège contre la falsification d'ID) |

### Fonctions de rendu

| Fonction | Description |
|----------|-------------|
| `table_empty_row($msg, $colspan)` | Affiche une ligne « Aucun résultat » dans un tableau Bootstrap |
| `render_dropdown($name, $items, $selected, ...)` | Affiche un `<select>` Bootstrap avec sélection automatique |

### Exemple d'utilisation

```php
// Avant (copié dans 10 fichiers)
if (!csrf_validate()) {
    flash_error('Token de sécurité invalide.');
    redirect('articles.php');
}
$id = (int)($_POST['id'] ?? 0);
$nom = trim($_POST['nom'] ?? '');
if ($nom === '') { flash_error('Obligatoire.'); redirect('...'); }
if (mb_strlen($nom) > 200) { flash_error('Trop long.'); redirect('...'); }

// Après (1 ligne)
csrf_guard('articles.php');
$data = extract_post_data([
    'id'   => ['type' => 'int'],
    'nom'  => ['type' => 'string', 'trim' => true, 'required' => true, 'max' => 200, 'redirect' => '...'],
], 'articles.php');
```

### Génération d'URLs

```php
// URL classique vers une page
$page_url = page_url('articles');
// → articles.php

// URL avec paramètres
$page_url = page_url('articles', ['action' => 'editer', 'id' => 5]);
// → articles.php?action=editer&id=5

// Redirection classique
redirect('articles.php');
redirect(page_url('articles', ['q' => 'nutella']));
```

---

## 5. Rôles & sécurité

| Fonctionnalité             | Directeur | Admin | Magasinier | Vendeur |
|----------------------------|:---------:|:-----:|:----------:|:-------:|
| Tableau de bord            | ✅ | ✅ | ✅ | ✅ |
| Articles (CRUD + prix)     | ✅ | ✅ | ✅ | ❌ (lecture via Stock) |
| Fournisseurs               | ✅ | ✅ | ✅ | ❌ |
| Mouvements (Entrées/Sorties)| ✅ | ✅ | ✅ | ❌ |
| Transferts inter-magasins  | ✅ | ✅ | ✅ (lecture + création) | ❌ |
| Caisse / Facturation       | ✅ | ✅ | ❌ | ✅ |
| Historique factures        | ✅ | ✅ | ❌ | ✅ |
| Alertes stock (suggestions_achat) | ✅ | ❌ | ✅ | ❌ |
| Dépenses d'exploitation    | ✅ (CRUD + suppression) | ✅ (CRUD + suppression) | ✅ (ajout + consultation) | ❌ |
| Statistiques / P&L          | ✅ | ✅ | ❌ | ❌ |
| Utilisateurs               | ✅ | ✅ | ❌ | ❌ |
| Paramètres boutique        | ✅ | ✅ | ❌ | ❌ |
| Mon Profil (changement mdp)| ✅ | ✅ | ✅ | ✅ |
| Journal d'activité (audit) | ✅ | ❌ | ❌ | ❌ |

- Mots de passe **hachés** (`password_hash` / BCRYPT, vérifiés via `password_verify`).
- **Force mot de passe** : minimum 8 caractères imposé à la création/modification.
- Sessions PHP avec **régénération d'ID** à la connexion (anti-fixation).
- Sessions renforcées : `httponly`, `samesite=Lax`, `secure` dynamique (auto-détection HTTPS).
- **Nonce CSP** : `Content-Security-Policy` avec nonce par session pour les scripts inline.
- Accès contrôlés par `exiger_role(...)` au début de chaque page (sinon → 403).
- **Constantes de rôles** : `ROLE_DIRECTEUR`, `ROLE_ADMIN`, `ROLE_MAGASINIER`, `ROLE_VENDEUR` — plus de magic strings.
- Requêtes SQL **centralisées** dans `db_functions.php` avec **statements préparés** (PDO) → protection anti-injection.
- Sorties échappées (`h()`) → protection anti-XSS, y compris sur les dates (`date_fr()` wrappé avec `h()`).
- **Protection CSRF** : tokens dans tous les formulaires (`csrf_token`, `csrf_field`, `csrf_validate`), y compris sur le login.
- **Rate limiting** : max 5 tentatives de connexion par IP en 15 minutes (table `login_attempts`).
- **Validation longueur** : tous les champs texte vérifient la longueur max BDD avant insertion.
- **Messages d'erreur masqués** : `getMessage()` remplacé par des messages génériques + `error_log()`.
- **En-têtes de sécurité** : `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Content-Security-Policy` (nonce), `Referrer-Policy`.
- **Alertes stock** : badge sidebar (`db_articles_low_stock_count()`) affichant le nombre d'articles dont le stock ≤ seuil. Page `suggestions_achat.php` listant ces articles regroupés par fournisseur avec coordonnées de contact.
- **Actions destructives via POST** : suppression d'articles, de fournisseurs et activation/désactivation d'utilisateurs convertis de GET vers POST avec confirmation.
- **Credentials DB via env** : `getenv()` avec fallback, plus de credentials en dur dans le code.
- **BASE_URL dynamique** : constante auto-détectée (`$_SERVER['HTTP_HOST']` + `dirname(SCRIPT_NAME)`) qui s'adapte à tout sous-dossier Wamp/production. Les assets statiques (CSS/JS) l'utilisent pour éviter les redirections vers la racine du serveur.
- **URLs signées HMAC** : les liens avec `?id=` sont signés avec `HMAC-SHA256` via `generate_signed_url()` / `verify_url_signature()` — empêche la falsification des IDs dans la barre d'adresse. `facture_view.php` est protégé : le token intègre le paramètre `print` pour garantir l'intégrité du lien d'impression.
- **Protection IDOR** : contrôle d'accès hiérarchique (`user_can_edit_user()`) sur les utilisateurs + filtrage par `utilisateur_id` pour les factures des vendeurs.
- **Index SQL** : index composites sur `(date_facture, statut)` et `(actif, quantite_stock, seuil_alerte)` pour les requêtes fréquentes.
- **Défenses PHP 8** : null coalescing (`??`) sur tous les accès aux tableaux dans `suggestions_achat.php` (fournisseur_nom, articles, champs article), garde `is_array()` avant tout `count()`, et `$stmt ? $stmt->fetchAll() : []` dans `db_articles_low_stock_with_supplier()` pour garantir un retour tableau jamais `false`.
- **Changement de mot de passe自主** : page `profil.php` (accessible à tout utilisateur connecté) permettant de modifier son propre mot de passe après vérification de l'actuel via `password_hash()` / `password_verify()`. Lien accessible dans le footer de la sidebar (au-dessus de la déconnexion).
- **Restriction des privilèges admin** : les administrateurs ne peuvent plus modifier les mots de passe d'autres utilisateurs. Le champ « Mot de passe » a été supprimé du formulaire d'édition dans `utilisateurs.php`. Seul le Directeur et l'Admin peuvent créer des comptes (avec mot de passe), mais la modification ultérieure du mot de passe est exclusivement réservée au propriétaire du compte via `profil.php`.
- **Sécurité du profil** : vérification du mot de passe actuel (`password_verify`), validation de la force (min 8 caractères), interdiction de réutiliser l'ancien mot de passe, protection CSRF, et hachage via `PASSWORD_DEFAULT` (bcrypt).
- **Journal d'activité (Audit Trail)** : table `logs_activite` enregistrant toutes les actions sensibles (connexions, modifications CRUD, ventes, changements de paramètres). La fonction `suivre_activite()` dans `includes/helpers.php` est appelée de manière centralisée — silencieuse (try/catch) et ne ralentit jamais l'application. La page `audit.php` (Directeur uniquement) affiche les logs avec filtres, recherche et pagination. Le lien d'accès est intégré dans le footer de la sidebar (au-dessus de « Mon Profil »), visible uniquement pour le rôle Directeur grâce à une condition `{% if current_role == 'Directeur' %}`.

---

## 5quater. Système RBAC Dynamique (Gestion des Permissions)

eStock utilise un système de contrôle d'accès basé sur les rôles (RBAC) géré en base de données.

### Architecture

Le système repose sur deux nouvelles tables :
- `permissions` : 20 permissions prédéfinies (stock, facturation, admin, etc.)
- `role_permissions` : table pivot associant chaque rôle à ses permissions

### Permissions disponibles

| Clé | Description | Catégorie |
|-----|-------------|-----------|
| `stock_consulter` | Consulter l'état du stock | stock |
| `stock_gerer` | Gérer les entrées/sorties de stock | stock |
| `stock_transfert` | Effectuer des transferts inter-magasins | stock |
| `articles_consulter` | Consulter la liste des articles | articles |
| `articles_gerer` | Créer/Modifier/Supprimer des articles | articles |
| `fournisseurs_consulter` | Consulter les fournisseurs | fournisseurs |
| `fournisseurs_gerer` | Gérer les fournisseurs | fournisseurs |
| `facturation_consulter` | Consulter les factures | facturation |
| `facturation_gerer` | Créer/Annuler des factures | facturation |
| `caisse_gerer` | Utiliser la caisse (POS) | facturation |
| `utilisateurs_consulter` | Consulter les utilisateurs | utilisateurs |
| `utilisateurs_gerer` | Créer/Modifier les utilisateurs | utilisateurs |
| `magasins_consulter` | Consulter les magasins | magasins |
| `magasins_gerer` | Gérer les magasins | magasins |
| `depenses_consulter` | Consulter les dépenses | depenses |
| `depenses_gerer` | Saisir/Modifier les dépenses | depenses |
| `parametres_gerer` | Modifier les paramètres de la boutique | config |
| `audit_consulter` | Consulter le journal d'audit | config |
| `statistiques_consulter` | Consulter les statistiques | config |
| `cloture_gerer` | Effectuer la clôture de caisse | caisse |

### Matrice des droits par rôle

| Permission | Directeur | Admin | Magasinier | Vendeur |
|------------|:---------:|:-----:|:----------:|:-------:|
| stock_consulter | ✓ | ✓ | ✓ | ✓ |
| stock_gerer | ✓ | ✓ | ✓ | |
| stock_transfert | ✓ | ✓ | ✓ | |
| articles_consulter | ✓ | ✓ | ✓ | ✓ |
| articles_gerer | ✓ | ✓ | ✓ | |
| fournisseurs_consulter | ✓ | ✓ | ✓ | |
| fournisseurs_gerer | ✓ | ✓ | ✓ | |
| facturation_consulter | ✓ | ✓ | | ✓ |
| facturation_gerer | ✓ | ✓ | | ✓ |
| caisse_gerer | ✓ | ✓ | | ✓ |
| utilisateurs_consulter | ✓ | ✓ | | |
| utilisateurs_gerer | ✓ | ✓ | | |
| magasins_consulter | ✓ | ✓ | ✓ | |
| magasins_gerer | ✓ | ✓ | | |
| depenses_consulter | ✓ | ✓ | ✓ | |
| depenses_gerer | ✓ | ✓ | ✓ | |
| parametres_gerer | ✓ | ✓ | | |
| audit_consulter | ✓ | | | |
| statistiques_consulter | ✓ | ✓ | ✓ | |
| cloture_gerer | ✓ | ✓ | | ✓ |

### Utilisation dans le code PHP

```php
// Vérifier une permission dans un contrôleur
if (peut('stock_transfert')) {
    // Autorisé
}

// Exiger une permission (bloque avec 403 si refusé)
exiger_permission('stock_gerer');
```

### Utilisation dans les templates Twig

```twig
{# Afficher un bouton conditionnellement #}
{% if peaut('articles_gerer') %}
    <a href="{{ base_url }}articles.php?action=editer" class="btn btn-primary">
        <i class="bi bi-plus"></i> Ajouter
    </a>
{% endif %}

{# Afficher/masquer un élément de menu #}
{% if peaut('stock_consulter') %}
    <li><a href="{{ base_url }}stock.php">Stock</a></li>
{% endif %}
```

### Gestion des droits via l'interface

1. Aller dans **Paramètres** → **Gestion des Droits**
2. La matrice affiche toutes les permissions (lignes) contre tous les rôles (colonnes)
3. Cocher/décocher les cases pour activer/désactiver une permission
4. Cliquer sur **Sauvegarder les droits**
5. Les changements prennent effet immédiatement

### Migration depuis l'ancien système

L'ancien système utilisait des fonctions statiques :
- `peut_gerer_stock()` → remplacé par `peut('stock_consulter')`
- `peut_facturer()` → remplacé par `peut('caisse_gerer')`
- `peut_administrer()` → remplacé par `peut('utilisateurs_gerer')`

### Installation

Exécuter le script de migration :
```sql
mysql -u root estock_db < database/permissions.sql
```

Ou via phpMyAdmin, importer le fichier `database/permissions.sql`.

### Tests QA

Exécuter le script de test :
```
http://localhost/eStock/test_rbac_qa.php
```

Ou en mode JSON :
```
http://localhost/eStock/test_rbac_qa.php?mode=json
```

---

## 5bis. Installation de la table `logs_activite`

Après avoir importé `database/schema.sql`, importez également `database/audit_trail.sql` dans phpMyAdmin pour activer le journal d'activité :

```sql
-- Importer database/audit_trail.sql
-- Ou exécuter directement :
CREATE TABLE IF NOT EXISTS `logs_activite` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `utilisateur_id` INT DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `date_action` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_la_user (`utilisateur_id`),
    INDEX idx_la_action (`action`),
    INDEX idx_la_date (`date_action`),
    CONSTRAINT fk_la_user FOREIGN KEY (`utilisateur_id`)
        REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Actions journalisées

| Action | Description | Couleur dans l'interface |
|--------|-------------|--------------------------|
| `CONNEXION` | Connexion réussie | Vert |
| `ECHEC_CONNEXION` | Tentative échouée ou rate limited | Rouge |
| `MODIFICATION_ARTICLE` | Création ou modification d'article | Bleu |
| `SUPPRESSION_ARTICLE` | Désactivation d'article | Rouge |
| `MODIFICATION_FOURNISSEUR` | Création ou modification fournisseur | Bleu |
| `SUPPRESSION_FOURNISSEUR` | Suppression fournisseur | Rouge |
| `VENTE` | Facture validée avec succès | Vert |
| `ECHEC_VENTE` | Erreur lors de la validation | Rouge |
| `MODIFICATION_UTILISATEUR` | Création, modification ou activation/désactivation | Bleu |
| `MOUVEMENT_STOCK` | Entrée ou sortie manuelle de stock | Bleu |
| `MODIFICATION_PARAMETRES` | Modification des paramètres boutique | Bleu |
| `CHANGEMENT_MDP` | Changement de mot de passe personnel | Bleu |
| `DECONNEXION` | Déconnexion utilisateur | Vert |
| `DEPENSE_ENREGISTREE` | Dépense d'exploitation enregistrée | Orange |
| `DEPENSE_SUPPRIMEE` | Dépense supprimée | Rouge |

---

## 5ter. Module de gestion des dépenses d'exploitation

### Principe

Le module permet d'enregistrer les **charges d'exploitation** (loyer, électricité, transport, etc.) pour calculer le **bénéfice net réel** :

```
Bénéfice Net = Chiffre d'Affaires − Coût d'Achat Marchandises Vendues − Dépenses
```

### Table `depenses`

```sql
CREATE TABLE IF NOT EXISTS `depenses` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `magasin_id`      INT DEFAULT NULL,
    `utilisateur_id`  INT DEFAULT NULL,
    `titre`           VARCHAR(200) NOT NULL,
    `categorie`       VARCHAR(60)  NOT NULL DEFAULT 'Autre',
    `montant`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `date_depense`    DATE NOT NULL,
    `description`     TEXT DEFAULT NULL,
    `date_creation`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dep_magasin  (`magasin_id`),
    INDEX idx_dep_categorie (`categorie`),
    INDEX idx_dep_date     (`date_depense`),
    CONSTRAINT fk_dep_magasin  FOREIGN KEY (`magasin_id`)     REFERENCES `magasins`(`id`)     ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_dep_user     FOREIGN KEY (`utilisateur_id`)  REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Catégories de dépenses

| Catégorie | Exemples |
|-----------|----------|
| Loyer | LoyER du local commercial |
| Électricité | Facture EDF/CEET |
| Eau | Facture SODECI |
| Transport | Carburant, livraison |
| Fournitures | Papeterie, emballages |
| Salaires | Salaires du personnel |
| Entretien | Maintenance équipements |
| Téléphone/Internet | Facture telecom |
| Assurance | Assurance locale |
| Autre | Charges diverses |

### Formules comptables

| Indicateur | Formule | Source données |
|------------|---------|----------------|
| **Chiffre d'affaires (CA)** | `SUM(factures.total_ttc)` WHERE statut = 'Payee' | `db_stats_period()` |
| **Coût d'achat (CAMV)** | `SUM(lignes_facture.quantite × articles.prix_achat)` | `db_stats_cout_achat_ventes()` |
| **Marge brute** | `CA − CAMV` | Calculé |
| **Total Dépenses** | `SUM(depenses.montant)` | `db_depenses_total()` |
| **Bénéfice Net** | `CA − CAMV − Dépenses` | `db_stats_benefice_net()` |

### Fonctions DB ajoutées (`db_functions.php`)

| Fonction | Description |
|----------|-------------|
| `db_depense_insert()` | Insérer une dépense |
| `db_depenses_list()` | Lister les dépenses sur une période (filtre magasin optionnel) |
| `db_depenses_total()` | Total des dépenses sur une période |
| `db_depenses_by_categorie()` | Dépenses regroupées par catégorie |
| `db_depenses_daily()` | Dépenses jour par jour (pour graphique) |
| `db_stats_cout_achat_ventes()` | Coût d'achat des marchandises vendues (CAMV) |
| `db_stats_benefice_net()` | Calcul complet du bénéfice net |

### Page `depenses.php`

- **Accès** : Directeur, Admin, Magasinier
- **Fonctionnalités** :
  - Formulaire d'ajout rapide (modal Bootstrap) : titre, catégorie, montant, date, description
  - Tableau récapitulatif des dépenses du mois avec filtres de date
  - Graphique doughnut de répartition par catégorie (Chart.js)
  - Suppression possible (Directeur/Admin uniquement)
  - Filtrage par magasin pour les Directeurs/Admins

### Impact sur `statistiques.php`

La page statistiques affiche désormais :
- **Cartes** : CA, ventes, panier moyen, top article + CAMV, Total Dépenses, Bénéfice Net
- **Tableau P&L** : Compte de résultat complet (CA → Marge brute → Dépenses → Bénéfice Net)
- **Graphique** : courbe CA vs Dépenses superposées (axes X communs, couleurs distinctes)

### Migration requise

Exécuter `database/depenses.sql` :

```sql
-- Importer database/depenses.sql
-- Ou exécuter directement la requête CREATE TABLE ci-dessus
```

---

## 6. Installation de la table `login_attempts`

Après avoir importé `database/schema.sql`, importez également `database/login_attempts.sql` dans phpMyAdmin pour activer la protection anti brute-force :

```sql
-- Importer database/login_attempts.sql
-- Ou exécuter directement :
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL,
    `login` VARCHAR(60) NOT NULL,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_la_ip (`ip_address`),
    INDEX idx_la_date (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 7. Pagination

Les listes d'articles, mouvements et factures utilisent une **pagination côté serveur** (25 éléments par page). Les paramètres de filtrage sont conservés dans l'URL pour naviguer entre les pages.

---

## 8. Routage et URL racine

### Constante `BASE_URL`

Définie dynamiquement dans `config/connexion.php`, cette constante s'adapte automatiquement à l'environnement :

```php
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$path   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
define('BASE_URL', $scheme . '://' . $host . $path . '/');
```

| Environnement | `SCRIPT_NAME` | `BASE_URL` résultant |
|---|---|---|
| Wamp sous-dossier | `/eSotck/articles.php` | `http://localhost/eSotck/` |
| Wamp racine | `/articles.php` | `http://localhost/` |
| Production HTTPS | `/eSotck/index.php` | `https://monsite.com/eSotck/` |

**Usage** : les assets statiques (`assets/css/style.css`, `assets/js/app.js`) utilisent `BASE_URL` pour garantir des chemins corrects quel que soit le sous-dossier.

### URLs générées

Les pages utilisent des URLs classiques PHP avec la fonction helper `page_url()` :

```php
page_url('articles')                                         // → articles.php
page_url('articles', ['action' => 'editer', 'id' => 5])     // → articles.php?action=editer&id=5
page_url('factures', ['page' => 2])                          // → factures.php?page=2
```

### Sécurité par contrôle d'accès

- Chaque page vérifie le rôle de l'utilisateur au chargement (`exiger_role()` / `exiger_connexion()`).
- Les liens `?id=` sont signés HMAC-SHA256 (`generate_signed_url`) — toute falsification est rejetée.
- **Hiérarchie des rôles** : un Admin ne peut pas modifier un Directeur (`user_can_edit_user()`).
- **Factures vendeur** : un vendeur ne voit que ses propres factures (filtre `utilisateur_id`).
- L'ID est visible dans l'URL, mais l'accès est bloqué si l'ID n'appartient pas à l'utilisateur connecté.

---

## 9. Architecture Template (Twig)

### Structure

```
templates/
├── base.html.twig                    # Layout maitre (sidebar + topbar + footer)
├── suggestions_achat.html.twig       # Page alertes stock (exemple migre)
├── profil.html.twig                  # Page profil : changement mot de passe
└── ...                               # Autres pages a migrer
```

### Principe

- Chaque page PHP ne contient **plus de HTML** — uniquement la logique metier
- Les templates heritent de `base.html.twig` via `{% extends "base.html.twig" %}`
- Le bloc `{% block body %}` recoit le contenu de chaque page
- Les blocs `head_extra` et `scripts_extra` permettent d'ajouter du CSS/JS specifique par page

### Configuration

Le moteur Twig est initialise dans `config/connexion.php` (section 9). L'autoloader Composer est charge en ligne 2 du fichier.

### Variables globales disponibles

| Variable | Type | Description |
|----------|------|-------------|
| `base_url` | string | Racine de l'app (`http://localhost/eSotck/`) |
| `current_user` | array\|null | Donnees utilisateur connecte |
| `current_role` | string | Role de l'utilisateur |
| `is_logged_in` | bool | Connecte ou non |
| `can_manage_stock` | bool | Droit gestion stock |
| `can_bill` | bool | Droit facturation |
| `can_admin` | bool | Droit administration |
| `flash_messages` | array | Messages flash de la session |
| `page_courante` | string | Slug de la page courante |
| `nb_alertes_topbar` | int | Nombre d'articles en alerte stock |

### Helpers disponibles dans Twig

`money()`, `date_fr()`, `h()`, `csrf_field()`, `csp_nonce()`, `param()`, `param_app_name()`

### Migration d'une page

1. Creer `templates/ma_page.html.twig` qui etend `base.html.twig`
2. Deplacer le HTML dans `{% block body %}`
3. Remplacer `<?= h($var) ?>` par `{{ var }}`
4. Remplacer `<?php foreach ... ?>` par `{% for ... %}`
5. Modifier le PHP pour ne garder que la logique + `$twig->render()`

### Exemple : suggestions_achat

**Avant** (PHP + HTML melange) :
```php
$titre_page = 'Alertes Stock';
include __DIR__ . '/includes/header.php';
?>
<div class="card shadow-sm">
    <!-- 80 lignes de HTML -->
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
```

**Apres** (contrôleur pur) :
```php
echo $twig->render('suggestions_achat.html.twig', [
    'titre_page' => "Alertes Stock — Suggestions d'achat",
    'groupes'    => $groupes,
    'nb_total'   => $nb_total,
]);
```

### Migration incremental

Les anciens fichiers `includes/header.php` et `includes/footer.php` restent en place pour les pages non migrees. La migration se fait page par page, sans interruption de service.

---

## 10. API REST

L'application expose une API REST JSON centralisée via `api/index.php` (point d'entrée unique). Toutes les requêtes `GET/POST/PUT/DELETE /api/*` sont routées par ce fichier.

### Structure

```
api/
├── index.php    # Routeur centralisé (dispatcher)
└── .htaccess    # Rewrite rules + en-têtes sécurité
```

### Endpoints

| Méthode | Route | Auth | Description | Code HTTP |
|---------|-------|------|-------------|-----------|
| POST | `/api/login` | Non | Authentification (login + mot de passe) | 200, 401, 422, 429 |
| GET | `/api/logout` | Oui | Déconnexion | 200, 401 |
| GET | `/api/me` | Oui | Utilisateur courant | 200, 401 |
| GET | `/api/articles` | Oui | Liste des articles (recherche via `?q=`) | 200, 401 |
| POST | `/api/articles` | Admin | Création article | 201, 401, 403, 409, 422 |
| PUT | `/api/articles/{id}` | Admin | Modification article | 200, 401, 403, 404, 409, 422 |
| DELETE | `/api/articles/{id}` | Admin | Désactivation article | 200, 401, 403, 404 |
| GET | `/api/scan?code=XXX` | Oui | Recherche par code-barres | 200, 401, 422 |
| * | `*` (autre) | — | Route inconnue | 404 |

### Requêtes / Réponses

#### POST `/api/login`

```json
// Requête
{ "login": "admin", "mot_de_passe": "admin123" }

// Succès (200)
{ "success": true, "message": "Connexion réussie.", "user": { "id": 1, "login": "admin", "role": "Directeur", ... } }

// Échec (401)
{ "success": false, "message": "Identifiant ou mot de passe incorrect." }
```

#### GET `/api/articles?q=nutella`

```json
// 200 OK
{
    "data": [
        {
            "id": 1,
            "code_barre": "3017620422003",
            "nom": "Nutella",
            "sku": "NUT-280",
            "prix_achat": "4500.00",
            "prix_vente": "5500.00",
            "quantite_stock": "45",
            "seuil_alerte": "10",
            "emplacement": "A1-01",
            "fournisseur_nom": "Distribuidora XYZ"
        }
    ],
    "total": 1
}
```

#### GET `/api/scan?code=3017620422003`

```json
// 200 OK
{
    "found": true,
    "article": {
        "id": 1,
        "code_barre": "3017620422003",
        "nom": "Nutella",
        "prix_unitaire": 5500,
        "prix_display": "5 500,00 FCFA",
        "stock_dispo": 45,
        "alerte": ""
    }
}

// Non trouvé (200 avec found=false)
{ "found": false, "code": "XXXX", "message": "Aucun article trouvé pour ce code-barres." }
```

#### POST `/api/articles` (création)

```json
// Requête
{
    "code_barre": "1234567890123",
    "nom": "Nouvel article",
    "sku": "SKU-001",
    "prix_achat": 2000,
    "prix_vente": 3500,
    "quantite_stock": 50,
    "seuil_alerte": 10,
    "emplacement": "B2-03"
}

// Succès (201)
{ "success": true, "id": 42, "message": "Article créé." }

// Conflit (409)
{ "error": "Ce code-barres existe déjà." }
```

### Codes HTTP

| Code | Signification |
|------|---------------|
| 200 | Succès |
| 201 | Création réussie |
| 401 | Non autorisé (session manquante ou invalide) |
| 403 | Accès refusé (droits insuffisants) |
| 404 | Route ou ressource introuvable |
| 405 | Méthode HTTP non autorisée |
| 409 | Conflit (doublon) |
| 422 | Données invalides |
| 429 | Trop de tentatives (rate limiting) |
| 500 | Erreur serveur |

### Utilisation côté JavaScript

```javascript
// Connexion
const res = await fetch(BASE_URL + 'api/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ login: 'admin', mot_de_passe: 'admin123' })
});
const data = await res.json();

// Liste d'articles (session active via cookie)
const arts = await fetch(BASE_URL + 'api/articles?q=nutella');
const list = await arts.json();

// Scan code-barres
const scan = await fetch(BASE_URL + 'api/scan?code=3017620422003');
const result = await scan.json();
```

---

## 11. Mode Hors-Ligne (Offline Sync)

La caisse fonctionne de manière autonome en cas de coupure réseau. Les ventes sont sauvegardées localement et synchronisées automatiquement dès le retour de la connexion.

### Principe

```
En ligne  → POST valider_facture.php (classique)
Hors-ligne → localStorage → file d'attente → sync auto via API
```

### Détection de connexion

Le script `caisse.js` utilise les événements natifs `navigator.onLine` + `window.addEventListener('online/offline')` pour afficher un badge visuel :

| État | Badge | Comportement |
|------|-------|--------------|
| En ligne | Vert `En ligne` | Validation classique vers `valider_facture.php` |
| Hors-ligne | Orange `Hors-ligne — ventes en attente` | Sauvegarde dans `localStorage` |

### Stockage local (localStorage)

**Clé** : `estock_pending_sales`

```json
[
    {
        "client_sale_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
        "timestamp": "2026-07-02T14:30:00Z",
        "user_id": 1,
        "magasin_id": 2,
        "lignes": [
            {
                "article_id": 5,
                "code_barre": "3017620422003",
                "nom": "Nutella",
                "quantite": 2,
                "prix_unitaire": 5500.00
            }
        ],
        "total_ht": 11000.00,
        "tva_taux": 18.00,
        "total_ttc": 12980.00,
        "montant_paye": 13000.00,
        "monnaie_rendue": 20.00,
        "statut": "pending"
    }
]
```

### Synchronisation automatique

1. L'événement `online` est déclenché quand le réseau revient.
2. `synchroniserVentes()` envoie chaque vente en attente via `POST /api/caisse/sync`.
3. Après confirmation serveur, la vente est marquée `synced` puis supprimée de `localStorage`.
4. En cas d'échec, la vente reste en attente pour la prochaine tentative.

### API de synchronisation

**Endpoint** : `POST /api/caisse/sync`

**Requête** :
```json
{
    "client_sale_id": "uuid-v4",
    "timestamp": "2026-07-02T14:30:00Z",
    "user_id": 1,
    "magasin_id": 2,
    "lignes": [
        { "article_id": 5, "quantite": 2, "prix_unitaire": 5500.00 }
    ],
    "total_ttc": 12980.00,
    "montant_paye": 13000.00
}
```

**Réponses** :

| Code | Cas | JSON |
|------|-----|------|
| 200 | Succès | `{ "success": true, "facture_id": 142, "numero": "FAC-...", "client_sale_id": "..." }` |
| 200 | Déjà synchronisé | `{ "success": true, "already_synced": true, "facture_id": 142 }` |
| 409 | Stock insuffisant | `{ "success": false, "error": "Stock insuffisant...", "client_sale_id": "..." }` |
| 422 | Données invalides | `{ "success": false, "error": "..." }` |
| 401 | Non connecté | `{ "error": "Non autorisé." }` |

### Sécurité

- **Anti-doublon** : colonne `client_sale_id` (UUID, UNIQUE) dans la table `factures`.
- **Prix serveur** : le prix utilisé est celui de la BDD (`prix_vente`), pas celui envoyé par le client.
- **FOR UPDATE** : verrouillage des lignes d'article pour éviter les race conditions.
- **Transaction** : insertion facture + lignes + décrément stock + mouvements = tout ou rien.
- **Audit** : chaque vente synchronisée est journalisée via `suivre_activite('VENTE_HORS_LIGNE', ...)`.

### Migration BDD requise

Exécuter `database/offline_sync.sql` :

```sql
ALTER TABLE factures ADD COLUMN client_sale_id VARCHAR(36) DEFAULT NULL AFTER magasin_id;
ALTER TABLE factures ADD UNIQUE INDEX idx_fact_client_sale (client_sale_id);
```

### Fichiers concernés

| Fichier | Rôle |
|---------|------|
| `assets/js/caisse.js` | Détection online/offline + queue localStorage + sync auto |
| `caisse.php` | Injection `user_id` / `magasin_id` dans `POS_CONFIG` |
| `api/index.php` | Route `POST /api/caisse/sync` + handler `handle_caisse_sync()` |
| `database/offline_sync.sql` | Migration colonne `client_sale_id` |

---

## 12. Évolutions possibles

- Gestion du **TVA multiples** (par article ou catégorie).
- **Catégories** d'articles + filtres avancés.
- **Devis / commandes fournisseurs** (réapprovisionnement).
- **Exports** CSV/Excel des ventes et mouvements.
- ~~Mode **hors-ligne** (PWA) pour les inventaires en rayon.~~ ✅ Implémenté (caisse offline sync).
- Connexion à une **imprimante thermique** (ESC/POS) pour les tickets.

---

## 13. Tests rapides

1. Connectez-vous en `admin / admin123`.
2. Allez dans **Caisse**, tapez un code-barres (ex. `3017620422003` = Nutella)
   puis **Entrée** → l'article s'ajoute au panier.
3. Saisissez le montant reçu, puis **Valider** → le ticket s'affiche et
   s'imprime (vous pouvez le réimprimer depuis **Factures**).
4. Vérifiez dans **Mouvements** qu'une ligne `Vente` a été créée et que le
   stock de l'article a baissé dans **Articles**.
5. Cliquez sur votre nom dans le footer de la sidebar (icône personne) → changez votre mot de passe → déconnectez-vous → reconnectez-vous avec le nouveau mot de passe.
6. En tant qu'Admin, allez dans **Utilisateurs** → éditez un utilisateur → vérifiez que le champ « Mot de passe » n'apparaît plus.

---

## 14. Audit technique

Un rapport d'audit complet est disponible dans `analyse_projet.txt` (généré le 2026-07-02). Il couvre l'architecture, la sécurité (IDOR, XSS, CSRF, injection SQL), les statistiques du projet et les pistes d'évolution.

### Audit PHP 8 — Compatibilité complète

Un audit complet de compatibilité PHP 8 a été réalisé sur l'ensemble des **25 fichiers PHP** du projet (2026-07-02). Résultats :

- **Syntaxe** : les 25 fichiers passent `php -l` sans erreur.
- **Corrigé** : `test_caisse_checkout.php` — un tableau (`array_filter`) était utilisé comme entier dans l'interpolation de chaîne et dans une division. Le compteur est maintenant extrait via `$passedCount = count($passed)` avant toute utilisation, et la division est protégée par un garde `$totalTests > 0 ? ... : 0`.
- **Confirmé sûr** : tous les autres fichiers utilisent des défenses PHP 8 standards :
  - `??` (null coalescing) sur tous les accès aux tableaux (`$_GET`, `$_POST`, `$row[...]`, `$g[...]`)
  - `is_array()` avant tout `count()` sur des variables pouvant être `null`/`false`
  - Casts `(int)`, `(float)` pour garantir les types numériques
  - `PDO::ERRMODE_EXCEPTION` — toute requête échouée lève une exception avant `fetchAll()`
  - `$stmt ? $stmt->fetchAll() : []` pour les cas ambigus (`db_articles_low_stock_with_supplier`)
  - `try/catch (Throwable)` dans `_params_load()` pour les opérations sur la table `parametres`
- **Résultat** : le projet est désormais **entièrement compatible PHP 8** avec zéro Warning, Notice ou Fatal Error.

---

## 15. Corrections UI/UX

- **Sidebar** : la page Stock est maintenant visible par tous les rôles (y compris Directeur/Admin/Magasinier), pas seulement les Vendeurs.
- **Menu actif** : l'indicateur de page courante fonctionne correctement (`str_replace('.php', '', basename(...))`).
- **Confirmations de suppression** : tous les boutons de suppression (articles, fournisseurs, utilisateurs, panier caisse) ont un dialogue `confirm()` JavaScript.
- **CSP nonce** : ajouté aux balises `<script>` de `caisse.js` et `app.js` pour corriger le blocage des scripts inline par `Content-Security-Policy`.
- **Event delegation** : tous les `onclick=` inline supprimés dans `caisse.php` et migrés vers des `addEventListener` dans `caisse.js` (CSP-compliant) — bouton vider panier, boutons quantité (+/−), suppression ligne, boutons paiement rapide.
- **Paiement rapide** : migration des boutons montant rapide vers des `data-attributes` (`data-type`, `data-amount`) avec délégation d'événements sur `#quickPayContainer`.
- **Facture post-paiement** : correction de l'erreur « Lien expiré ou invalide » après validation d'une vente. La cause était un décalage entre `valider_facture.php` (redirigeait vers `facture_view.php` via `page_url()` sans token HMAC) et `facture_view.php` (exigeait un token via `verify_url_signature()`). La vérification HMAC a été remplacée par une protection IDOR basée sur la session (`exiger_role` + contrôle de propriété `utilisateur_id` pour les vendeurs), cohérente avec le reste de l'application.
- **Notification topbar** : l'icône d'alerte de stock (cloche avec badge rouge) est désormais dans la topbar au lieu de la sidebar. Elle redirige vers `suggestions_achat.php` et affiche le nombre d'articles en alerte via `db_articles_low_stock_count()`.
- **Page Statistiques** : nouvelle page `statistiques.php` (Directeur + Admin) avec graphiques Chart.js — courbe d'évolution du CA journalier et barres du top 5 articles. Filtres par période avec validation défensive des dates (défaut : mois en cours).
- **Fonctions SQL statistiques** : `db_stats_period()`, `db_stats_top_articles()`, `db_stats_daily_sales()` — requêtes paramétrées avec periods filtrables et protections PHP 8.
- **Lien profil dans la sidebar** : le lien « Mon Profil » a été déplacé de la topbar vers le footer de la sidebar, juste au-dessus du bouton de déconnexion. Le footer affiche désormais deux zones cliquables séparées : l'utilisateur (avatar + nom + rôle + icône personne → profil) et la déconnexion (icône flèche sur fond sombre). Le lien profil s'illumine (`active`) quand l'utilisateur est sur la page `profil.php`.
- **Sidebar dropdowns (interactivité)** : les boutons déroulants de la sidebar (Logistique & Stocks, Ventes, Administration) sont maintenant fonctionnels. Le JS dans `app.js` écoute les clics sur `.nav-group-toggle`, bascule l'attribut `aria-expanded` et la classe `.open` sur le conteneur `.nav-group-items` (animé via `max-height` CSS). Un comportement **accordéon** ferme automatiquement les autres groupes ouverts. L'ouverture automatique du groupe contenant la page active est gérée côté serveur (Twig) et côté client (JS).
- **Badge statut réseau dans la topbar** : le badge de connexion (`En ligne` / `Hors-ligne`) est désormais aligné horizontalement avec les icônes d'action (Caisse, Notifications, Accueil) dans la topbar. Correction : suppression du `position: fixed` appliqué par `caisse.js` qui sortait le badge du flux normal et le superposait aux autres éléments. Le badge est maintenant un élément flex à part entière du conteneur droit de la topbar (`display: inline-flex`, `gap: 6px`). Les classes CSS mortes `badge-offline`/`badge-online` ont été retirées du template Twig.
- **Module dépenses d'exploitation** : nouvelle page `depenses.php` + template `depenses.html.twig` permettant l'enregistrement des charges (loyer, électricité, transport, etc.). Le formulaire modal Bootstrap 5 saisit titre, catégorie, montant, date et description. Un tableau récapitulatif avec filtres de date et un graphique doughnut de répartition par catégorie complètent l'interface. Le calcul du bénéfice net (CA − CAMV − Dépenses) est intégré à la page `statistiques.php` avec un tableau P&L complet et un graphique CA vs Dépenses superposé.
- **Bug fix : Erreur fatale `titre_page()`** : la fonction `titre_page()` appelée dans `depenses.php` n'existait pas (aucun fichier PHP ne la définit). Correction : remplacement par l'assignation variable `$titre_page = 'Dépenses'`, conforme au pattern utilisé par tous les autres contrôleurs (`transferts.php`, `statistiques.php`, etc.).
- **Sidebar : positionnement du lien Dépenses** : le lien « Dépenses » a été déplacé du dropdown « Ventes » (gated par `can_bill`, incluant les Vendeurs) vers la section « Administration » (gated par `can_admin`), car la page est réservée aux Directeurs et Administrateurs. Le lien est maintenant un élément standalone `<a>` avec icône `bi-wallet2` et détection d'état actif via `page_courante`.
- **Bug fix : module dépenses — Type mismatch PHP 8 + sécurité suppression** : correction de 4 bugs dans le module dépenses : (1) signature `db_depense_insert()` corrigée de `int $magasin_id` à `?int $magasin_id` pour accepter `null` quand aucun magasin n'est assigné — le `catch Throwable` avalait silencieusement le `TypeError` PHP 8, (2) `$mid` dans `depenses.php` retourne désormais `null` explicitement quand ni le formulaire ni l'utilisateur n'ont de magasin valide, (3) la catégorie est maintenant validée via une whitelist `$categories` dans `extract_post_data()`, (4) la suppression est sécurisée : un Magasinier ne peut plus supprimer les dépenses d'un autre magasin (filtre `AND magasin_id = ?`). Les 3 guard checks redondants (`function_exists` + `require_once` en double) ont été supprimés.
- **Bug fix : dépenses — données insérées non affichées (requête SQL + filtre magasin)** : correction du bug principal où les dépenses en base ne remontaient jamais à l'écran. Trois causes identifiées : (1) les 4 fonctions SQL (`db_depenses_list`, `db_depenses_total`, `db_depenses_by_categorie`, `db_depenses_daily`) utilisaient `DATE()` sur la colonne `date_depense` (déjà de type DATE), ce qui empêchait l'utilisation de l'index et pouvait causer des incompatibilités de type avec les prepared statements — supprimé au profit d'une comparaison directe `d.date_depense BETWEEN ? AND ?`, (2) le filtre `AND d.magasin_id = ?` excluait les dépenses globales (`magasin_id IS NULL`) — corrigé en `AND (d.magasin_id = ? OR d.magasin_id IS NULL)`, (3) le contrôleur `depenses.php` appliquait toujours le filtre magasin depuis la session utilisateur, y compris pour les Directeurs/Admins qui devraient voir TOUTES les dépenses — introduit `$filtre_magasin = $is_directeur ? 0 : $magasin_id` pour ne filtrer que pour les Magasiniers. Le template a été nettoyé : suppression du titre `<h4>` dupliqué avec la topbar globale, alignement du bouton « Nouvelle dépense » à droite dans le flux Liquid Glass.

---

## 16. Architecture multi-magasins

### Principe
Le stock n'est plus global : chaque article possède un stock **par magasin** via la table pivot `stock_magasins`. Les mouvements, factures et transferts sont dorénavant rattachés à un magasin via `magasin_id`.

### Tables ajoutées
| Table | Rôle |
|---|---|
| `magasins` | Liste des magasins (nom, adresse, actif) |
| `stock_magasins` | Pivot `magasin_id` ↔ `article_id` → `quantite`, `stock_alerte` |
| `transferts_stock` | Historique des transferts inter-magasins |

### Colonnes ajoutées
- `utilisateurs.magasin_id` — magasin affecté à l'utilisateur
- `mouvements_stock.magasin_id` — magasin du mouvement
- `factures.magasin_id` — magasin émetteur de la facture

### Migration SQL
Exécuter `database/multi_magasin.sql` après `schema.sql` et `audit_trail.sql` :
- Crée les 3 tables + les colonnes `magasin_id`
- Peuple `stock_magasins` depuis les `quantite_stock` existantes (migration vers magasin #1)
- Affecte le magasin principal à tous les utilisateurs

### Fonctions DB ajoutées (`db_functions.php`)
- `db_magasins_list()` / `db_magasin_get_by_id()` — lecture magasins
- `db_stock_magasin_get()` / `db_stock_magasin_upsert()` — CRUD pivot
- `db_stock_magasin_init_for_article()` — initialisation à la création d'article
- `db_transferer_stock()` — transfert atomique (transaction + FOR UPDATE)
- `db_transferts_list()` — historique des transferts

### Fonctions modifiées
- `db_article_get_by_barcode()`, `db_article_get_for_update()`, `db_article_get_for_mouvement()` → acceptent `$magasin_id` optionnel
- `db_article_update_stock()`, `db_article_decrement_stock()` → décrémentent `stock_magasins` si `$magasin_id`
- `db_articles_search_stock()`, `db_articles_low_stock()`, `db_articles_low_stock_with_supplier()`, `db_articles_low_stock_count()`, `db_articles_stock_value()` → filtres par magasin
- `db_article_insert()` → initialise `stock_magasins` pour tous les magasins actifs
- `db_mouvement_insert()` → stocke `magasin_id`
- `db_facture_insert()` → stocke `magasin_id`
- `db_mouvements_search_sql()` → JOIN `magasins`, filtre par `magasin_id`
- `process_stock_movement()` → paramètre `$magasin_id` optionnel

### Pages modifiées
- **scan_ajax.php** : stock filtré par magasin de l'utilisateur connecté
- **caisse.php** : le scan passe par `scan_ajax.php` (déjà magasin-aware)
- **valider_facture.php** : décrémente `stock_magasins`, stocke `magasin_id` sur facture + mouvements
- **mouvements.php** : filtre par magasin, affiche nom du magasin
- **stock.php** : consultation filtrée par magasin
- **suggestions_achat.php** : alertes filtrées par magasin
- **tableau_bord.php** : statistiques (valeur stock, alertes) filtrées par magasin
- **articles.php** : mouvement initial avec `magasin_id`

### Page Transferts
- `transferts.php` + `templates/transferts.html.twig`
- Directeur/Admin : peuvent créer des transferts (source → destination, quantité)
- Magasinier : consultation seule des transferts liés à son magasin
- Lien dans la sidebar (Directeur + Admin + Magasinier)
- **Correction bug** : l'appel `twig_env()` (fonction inexistante) à la ligne 70 a été supprimé. `$twig` est une variable globale initialisée dans `config/connexion.php` (ligne 340) et directement accessible via `require_once`. Utiliser `echo $twig->render(...)` sans instanciation préalable, conformément au pattern de `suggestions_achat.php`.

---

## 17. Module Clôture de Caisse Journalière (Z de Caisse)

### Objectif
Verrouiller les ventes de la journée, calculer les écarts de caisse, et générer un récapitulatif.

### Table `clotures_caisse`
```sql
CREATE TABLE clotures_caisse (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    magasin_id      INT NOT NULL,
    utilisateur_id  INT NOT NULL,
    date_cloture    DATE NOT NULL,
    montant_attendu DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    montant_reel    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    ecart           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    statut          ENUM('VALIDE','ANNULEE') NOT NULL DEFAULT 'VALIDE',
    date_creation   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cloture_jour (magasin_id, utilisateur_id, date_cloture)
);
```

### Colonne ajoutée à `factures`
- `cloture_id` (INT, NULL, FK → `clotures_caisse(id)` ON DELETE SET NULL)
- Une facture avec `cloture_id IS NOT NULL` est verrouillée et ne peut plus être modifiée.

### Migration SQL
Exécuter `database/clotures_caisse.sql` après les migrations précédentes.

### Fonctions DB ajoutées (`db_functions.php`)
| Fonction | Description |
|----------|-------------|
| `db_calculer_ventes_du_jour($pdo, $magasin_id, $utilisateur_id)` | Somme des ventes non clôturées du jour (float) |
| `db_compter_ventes_du_jour($pdo, $magasin_id, $utilisateur_id)` | Nombre de ventes non clôturées du jour |
| `db_cloture_deja_ferme($pdo, $magasin_id, $utilisateur_id)` | Vérifie si une clôture existe déjà aujourd'hui |
| `db_cloture_insert($pdo, $data)` | Insère une clôture, retourne le nouvel ID |
| `db_cloture_verrouiller_ventes($pdo, $cloture_id, $magasin_id, $utilisateur_id)` | Lie les ventes du jour à la clôture |
| `db_cloture_get_derniere($pdo, $magasin_id)` | Dernière clôture validée d'un magasin |
| `db_clotures_list($pdo, $magasin_id, $date_debut, $date_fin)` | Historique des clôtures |

### Règles métier
1. **Un seul calcul par jour** : un vendeur ne peut effectuer qu'une seule clôture par jour et par magasin (contrainte d'unicité).
2. **Calcul automatique** : le montant théorique est calculé par le système (somme des `total_ttc` des factures payées non clôturées).
3. **Saisie du montant réel** : le vendeur saisit le montant physiquement compté dans le tiroir.
4. **Écart** : `ecart = montant_reel - montant_attendu`. Si l'écart est non nul, une alerte `ECART_CAISSE` est tracée dans le journal d'audit.
5. **Verrouillage** : toutes les factures du jour sont liées à la clôture via `cloture_id`, empêchant toute modification rétroactive.
6. **Blocage des ventes** : après clôture, le bouton "Valider" dans la caisse est désactivé et le scan est bloqué jusqu'à la prochaine journée.

### Pages
- `cloture.php` + `templates/cloture.html.twig` — Interface de clôture (accessible aux Vendeurs, Admins et Directeurs)
- Lien dans la sidebar sous "Ventes" > "Clôture de Caisse"

### Audit trail
- `CLOTURE_CAISSE` : clôture validée
- `ECART_CAISSE` : écart de caisse détecté (détails : montant attendu, réel, écart)

### Correctif — TypeError $utilisateur_id null
**Problème** : `valider_facture.php` ligne 50 — `db_cloture_deja_ferme()` recevait `null` au lieu d'un `int` pour `$utilisateur_id`, provoquant un `TypeError` fatale.

**Cause** : la variable `$user_id` n'était jamais extraite de la session. Seul `$u = user_courant()` était appelé, sans extraction de `$u['id']`.

**Correction** :
- `valider_facture.php` : ajout de `$user_id = (int)($u['id'] ?? 0);` avant l'appel à `db_cloture_deja_ferme()`.
- `db_functions.php` : le paramètre `$utilisateur_id` est maintenant `?int` (nullable). Si l'ID est 0 ou nul, la fonction retourne `true` (bloque par sécurité).

### Intégration UI/UX — Bouton « Clôturer la Caisse »

**Emplacement 1 : Page Caisse (`caisse.php`)**
- Bouton `btn-outline-warning` dans le card-header de la zone de scan
- Visible uniquement si la caisse n'est pas encore clôturée
- Masqué ( remplacé par un badge « Fermée ») une fois la clôture effectuée
- Redirige vers `cloture.php`

**Emplacement 2 : Sidebar (`base.html.twig`)**
- Lien dans le footer de la sidebar (au-dessus de « Mon Profil »)
- Visible pour les rôles `Directeur`, `Admin` et `Vendeur`
- Icône dorée (`#f59e0b`) pour attirer l'attention
- Surligné actif (`page_courante == 'cloture'`)

---

## 18. Gestion des Stocks par Lots (DLC/DDM) — Méthode FEFO

eStock supporte la gestion des stocks par lots avec traçabilité des dates de péremption (DLC/DDM) et méthode FEFO (First Expired, First Out).

### 18.1 Architecture de la base de données

**Table `article_lots`** :
```sql
CREATE TABLE article_lots (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    article_id        INT NOT NULL,
    magasin_id        INT NOT NULL,
    numero_lot        VARCHAR(100) NOT NULL,
    quantite          INT NOT NULL DEFAULT 0,
    date_peremption   DATE DEFAULT NULL,           -- NULL = pas de DLC
    date_reception    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cree_le           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_lot_article_magasin (article_id, magasin_id, numero_lot),
    INDEX idx_lot_fefo (article_id, magasin_id, date_peremption, quantite)
);
```

### 18.2 Règles de gestion

| Règle | Description |
|-------|-------------|
| **FEFO** | Les ventes déduisent en priorité du lot dont la DLC est la plus proche |
| **Multi-lots** | Si la quantité vendue dépasse le lot FEFO, le lot suivant est utilisé |
| **Lots sans DLC** | Les produits non périssables (DLC = NULL) sont utilisés en dernier |
| **Alertes** | Notifications automatiques pour les lots expirés ou expirant < 30 jours |
| **Traçabilité** | Chaque mouvement enregistre le numéro de lot utilisé |

### 18.3 Fonctions PHP disponibles

| Fonction | Description |
|----------|-------------|
| `db_lot_upsert()` | Créer ou incrémenter un lot existant |
| `db_lot_get_fefo()` | Trouver le lot FEFO (première expiration) |
| `db_lot_decrement()` | Décrémenter un lot spécifique |
| `db_lot_decrement_fefo()` | Décrémenter par FEFO (multi-lots) |
| `db_get_expiring_lots()` | Lots expirés + expirant bientôt |
| `db_peremption_alert_count()` | Compteur d'alertes pour le badge |
| `db_lots_by_article()` | Lots d'un article dans un magasin |
| `db_lots_all_with_details()` | Tableau complet des péremptions |

### 18.4 Utilisation

**Entrée de stock avec lot** :
```php
process_stock_movement($pdo, $article_id, 'Entree', $quantite, $motif, $user_id, $magasin_id,
                       $numero_lot, $date_peremption);
```

**Vente FEFO** (automatique) :
```php
// La fonction process_stock_movement() gère automatiquement la déduction FEFO
// lors des types 'Sortie' ou 'Vente'
process_stock_movement($pdo, $article_id, 'Vente', $quantite, $motif, $user_id, $magasin_id);
```

**Vérification manuelle FEFO** :
```php
$lot = db_lot_get_fefo($pdo, $article_id, $magasin_id);
if ($lot) {
    echo "Lot le plus proche : " . $lot['numero_lot'];
    echo "Expire le : " . $lot['date_peremption'];
}
```

### 18.5 Interfaces

| Page | Description |
|------|-------------|
| `peremptions.php` | Tableau des lots par urgence (rouge/orange/vert) |
| `mouvements.php` | Formulaire enrichi avec numéro de lot et DLC |
| Caisse (scan) | Notification visuelle si lot proche de la DLC |
| Topbar | Badge avec le nombre d'alertes de péremption |

### 18.6 Migration

Exécuter le script :
```bash
mysql -u root estock_db < database/article_lots.sql
```

### 18.7 FAQ

**Q : Que se passe-t-il si je vends plus que la quantité du lot FEFO ?**
R : Le système utilise automatiquement le lot suivant (méthode FEFO multi-lots).

**Q : Les lots sans DLC sont-ils utilisés en priorité ?**
R : Non, ils sont utilisés EN DERNIER (considérés comme péremption longue).

**Q : Comment modifier la période d'alerte (30 jours par défaut) ?**
R : Modifier le paramètre dans `db_get_expiring_lots($pdo, $jours)`.

---

## 19. Stabilisation — Polissage final (Juillet 2026)

### 19.1 Corrections critiques

| Fichier | Correction | Sévérité |
|---------|-----------|----------|
| `api/index.php` | Variable `$response` non initialisée dans `handle_scan()` — les alertes de péremption étaient silencieusement perdues | **CRITIQUE** |
| `assets/js/caisse.js` | Aucun garde-fou null sur les références DOM (`scanInput`, `panierTbody`, `formPaiement`, etc.) — 20+ sites de crash potentiels | **HAUTE** |
| `templates/*.html.twig` | 45 variables utilisées sans filtre `\|default` — erreurs Twig si un contrôleur omet une variable | **HAUTE** |

### 19.2 Blindage PHP 8

- `count()` blindé contre `null` : `count($var ?? [])` dans `tableau_bord.php`, `db_functions.php`, `parametres.php`
- `$_SESSION`, `$_GET`, `$_POST` systématiquement protégés par `??` ou `isset()` (vérifié — déjà conforme)
- Comparaisons strictes vérifiées (`!==` au lieu de `!=` pour les floats critiques)

### 19.3 Templates Twig

- **Entités HTML remplacées** : `&ocirc;` → `ô`, `&egrave;` → `è`, `&eacute;` → `é`, `&middot;` → `·`, `&laquo;`/`&raquo;` → `«`/`»`, `&le;` → `≤`
- **Filtres `\|default`** ajoutés sur toutes les variables sensibles : `current_user`, `current_role`, `page_courante`, `flash_messages`, `nb_alertes_*`, `depenses`, `logs`, `alertes`, `permissions_data`, etc.
- **Styles inline extraits** en classes CSS utility : `.avatar-profile`, `.col-date`, `.col-action`, `.col-ip`, `.col-permission`, `.col-role`

### 19.4 CSS & UI/UX

- **Boutons** : hover states unifiés avec `box-shadow` cohérent sur `btn-success`, `btn-danger`, `btn-light`
- **Sidebar** : suppression du double-contrôle JS/CSS sur la rotation des chevrons — CSS gère seul via `[aria-expanded="true"]`
- **Sidebar** : transition `padding .2s ease` morte supprimée (ne change jamais)
- **Login** : `border-radius` CSS corrigé (espace manquant : `var(--radius-sm)0` → `var(--radius-sm) 0`)

### 19.5 JavaScript

- **caisse.js** : tous les accès DOM (`scanInput`, `panierTbody`, `formPaiement`, `btnValider`, `zoneAlerte`, `conteneurPanier`) blindés avec `if (el)`
- **caisse.js** : optional chaining (`?.`) uniformisé pour `document.getElementById('inpTotalTtc')`
- **app.js** : variables `arrow` et `otherArrow` supprimées (plus nécessaire après suppression du dual-control)

### 19.6 Fichiers modifiés

| Fichier | Type |
|---------|------|
| `api/index.php` | PHP |
| `tableau_bord.php` | PHP |
| `includes/db_functions.php` | PHP |
| `parametres.php` | PHP |
| `login.php` | PHP |
| `assets/js/caisse.js` | JavaScript |
| `assets/js/app.js` | JavaScript |
| `assets/css/style.css` | CSS |
| `templates/base.html.twig` | Twig |
| `templates/cloture.html.twig` | Twig |
| `templates/depenses.html.twig` | Twig |
| `templates/audit.html.twig` | Twig |
| `templates/magasins.html.twig` | Twig |
| `templates/peremptions.html.twig` | Twig |
| `templates/suggestions_achat.html.twig` | Twig |
| `templates/transferts.html.twig` | Twig |
| `templates/permissions.html.twig` | Twig |
| `templates/profil.html.twig` | Twig |

### 19.7 Recommandations restantes (améliorer en v2)

- Activer le cache Twig en production (`'cache' => true`)
- Externaliser les `<script>` inline de `cloture.html.twig` et `depenses.html.twig` en fichiers JS dédiés
- Remplacer les `onclick="return confirm(...)"` par des handlers JS délégués
- Ajouter des variables d'environnement pour les credentials DB et la clé HMAC en production
- Implémenter la détection de proxy pour `$_SERVER['REMOTE_ADDR']` (rate limiting derrière un reverse proxy)

---

## 20. Module Péremptions — Stabilisation FIFO/FEFO (Juillet 2026)

### 20.1 Anomalies corrigées

| # | Fichier | Sévérité | Problème | Correction |
|---|---------|----------|----------|------------|
| 1 | `api/index.php:329` | **CRITIQUE** | `$magasin_id` réassigné depuis `$_GET` — contournement de la sécurité FEFO | Suppression de la réassignation ; utilise uniquement `$magasin_id` de `user_courant()` |
| 2 | `api/index.php:332` | **MOYENNE** | `new DateTime(null)` si `date_peremption` est null → Warning PHP 8.1+ | Ajout de `!empty()` + cast `(string)` avant instanciation DateTime |
| 3 | `api/index.php:334` | **FAIBLE** | `new DateTime()` sans argument = date-heure actuelle (inclut l'heure) | Remplacement par `new DateTime('today')` pour comparaison purement date |
| 4 | `peremptions.php:9` | **MOYENNE** | `exiger_role(ROLE_DIRECTEUR, ROLE_MAGASINIER)` exclut Admin/Vendeur | Remplacement par `peut('stock_consulter')` pour cohérence avec le sidebar |
| 5 | `peremptions.html.twig:4` | **FAIBLE** | `<div class="px-3 py-2">` double padding inutile | Remplacement par `<div class="mb-3">` |
| 6 | `peremptions.html.twig:76,115` | **MOYENNE** | `lot.date_peremption\|date()` plante si null | Ajout de `{% if lot.date_peremption is not empty %}` + fallback `—` |
| 7 | `peremptions.html.twig` | **FAIBLE** | Variables Twig sans `\|default()` sur tous les champs de lot | Ajout de `\|default('')` et `\|default(0)` systématiques |

### 20.2 Logique FEFO validée

| Composant | Statut | Détail |
|-----------|--------|--------|
| `db_lot_get_fefo()` | ✅ | Exclut les lots périmés (`date_peremption >= CURDATE()`), tri FEFO correct |
| `db_lot_decrement_fefo()` | ✅ | Boucle while, gère les lots partiels, lance RuntimeException si insuffisant |
| `valider_facture.php` | ✅ | Appelle `db_lot_decrement_fefo()` en transaction, rollback si échec |
| `handle_scan()` API | ✅ | Affiche alerte si lot FEFO expire ≤ 7j ou déjà périmé |
| Template caisse.js | ✅ | Toast visuel avec couleur rouge/orange selon statut expire/urgent |

### 20.3 Fichiers modifiés

| Fichier | Type |
|---------|------|
| `api/index.php` | PHP — Sécurisation FEFO |
| `peremptions.php` | PHP — Correction permissions + suppression header/footer legacy |
| `templates/peremptions.html.twig` | Twig — Refactorisation complète |

---

## 21. Module Péremptions — Nettoyage UI/UX (Juillet 2026)

### 21.1 Problème structurel résolu

`peremptions.php` incluait `includes/header.php` (layout legacy sidebar+topbar) **et** rendait un template Twig étendant `base.html.twig` (layout moderne sidebar+topbar). Résultat : **double sidebar, double topbar, HTML imbriqué invalide**.

### 21.2 Corrections appliquées

| Fichier | Avant | Après |
|---------|-------|-------|
| `peremptions.php` | `include header.php` + `include footer.php` + onglets HTML PHP | `$twig->render()` direct (pattern standard) |
| `peremptions.html.twig` | Pas d'onglets, en-tête dans un `<div class="mb-3">` | Onglets intégrés en Twig, en-tête sans wrapper superflu |

### 21.3 Pattern validé

La page suit maintenant le même pattern que `depenses.php` et `cloture.php` :
- Pas de `include header.php` / `include footer.php`
- `$twig->render()` avec passed variables
- Template étend `base.html.twig` qui gère le layout complet

---

## 22. Nouvelles Fonctionnalités (Juillet 2026)

### 22.1 Générateur de Code-barres EAN-13

- **Fonctions PHP** : `generer_ean13()`, `calculer_check_digit_ean13()`, `valider_ean13()`, `api_generer_code_barre()` dans `includes/helpers.php`
- **API** : `GET /api/generer_code_barre?prefixe=XXXXX` retourne un EAN-13 valide
- **Frontend** : Bouton « Générer » dans `articles.php` avec prévisualisation SVG via JsBarcode
- **Validation** : Vérification en temps réel de la longueur et du check digit

### 22.2 Paiements Multi-Modes (Caisse)

- **Base de données** : Table `paiements_facture` (`database/multi_paiements.sql`)
  - Colonnes : `facture_id`, `mode_paiement` (ENUM), `montant`, `reference`, `date_paiement`
  - Cascade FK sur `factures.id`
- **Backend** : `db_paiements_insert()`, `db_paiements_by_facture()`, `db_paiements_total_by_mode()` dans `includes/db_functions.php`
- **Frontend (caisse.php)** : 3 onglets (Espèces / Mobile Money / Carte Bancaire) avec :
  - Progression visuelle (barre + pourcentage)
  - Calcul automatique de la monnaie (mode Espèces)
  - Validation montant ≥ TTC avant soumission
  - Champ référence pour Mobile Money
- **Frontend (caisse.js)** : Logique JS complète :
  - Accumulation multi-modes en temps réel
  - Génération JSON `paiements_json` pour POST
  - Mode hors-ligne : synchronisation avec données paiements
- **Validation (valider_facture.php)** : 
  - Parsing `paiements_json` depuis POST
  - Fallback sur `montant_paye` classique si JSON vide
  - Appel `db_paiements_insert()` dans la transaction
  - Vérification `SUM(montants) >= total_ttc`

### 22.3 Impression Ticket Thermique 80mm

- **Template** : `templates/ticket_print.html.twig` (autonome, pas de `base.html.twig`)
  - Mise en page 80mm, police monospace
  - En-tête magasin, infos facture, lignes d'articles
  - Totaux (HT, TVA, TTC) avec devise configurable
  - Détail des paiements multi-modes
  - Code-barres facture (JsBarcode CODE128)
  - Auto-impression via `window.print()` au chargement
- **Contrôleur** : `ticket_print.php` — charge facture, lignes, paiements, magasin
- **CSS** : Styles `.ticket-thermal` dans `style.css` avec `@media print` optimisé

### 22.4 Modification Facture View

- **Paiements** : Affichage du détail des modes de paiement (si multi-paiement)
- **Bouton thermique** : Lien vers `ticket_print.php?facture_id=X` dans la barre d'actions
- **Rétrocompatibilité** : Affichage classique si aucun paiement enregistré dans `paiements_facture`

### 22.5 Fichiers modifiés / créés

| Fichier | Type | Description |
|---------|------|-------------|
| `includes/helpers.php` | Modifié | +4 fonctions EAN-13 |
| `includes/db_functions.php` | Modifié | +3 fonctions paiements |
| `api/index.php` | Modifié | +1 route `generer_code_barre` |
| `articles.php` | Modifié | +bouton générer + prévisualisation |
| `caisse.php` | Modifié | +UI multi-paiement |
| `assets/js/caisse.js` | Modifié | +logique multi-mode |
| `assets/css/style.css` | Modifié | +styles paiement + thermique |
| `valider_facture.php` | Modifié | +gestion paiements_json |
| `facture_view.php` | Modifié | +affichage paiements + bouton thermique |
| `database/multi_paiements.sql` | Créé | Table paiements_facture |
| `templates/ticket_print.html.twig` | Créé | Ticket thermique 80mm |
| `ticket_print.php` | Créé | Contrôleur impression thermique |

### 22.6 Prérequis Base de données

Exécuter `database/multi_paiements.sql` pour créer la table `paiements_facture` :

```sql
CREATE TABLE IF NOT EXISTS `paiements_facture` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `facture_id`      INT NOT NULL,
    `mode_paiement`   ENUM('Especes','Mobile_Money','Carte_Bancaire','Virement','Autre') NOT NULL DEFAULT 'Especes',
    `montant`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `reference`       VARCHAR(100) DEFAULT NULL,
    `date_paiement`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pf_facture (`facture_id`),
    INDEX idx_pf_mode   (`mode_paiement`),
    CONSTRAINT fk_pf_facture FOREIGN KEY (`facture_id`) REFERENCES `factures`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 23. Cycle de Stabilisation — Version de Polish Final

### 23.1 Corrections PHP 8 / Sécurité

| Fichier | Correction |
|---|---|
| `articles.php` | Déplacement du closure `$redirect_form` après l'assignation de `$data` (variable previously undefined) |
| `parametres.php` | Blindage `$_POST['jours_limite'] ?? ''` et `$_POST['seuil_stock'] ?? ''` contre les accès de clés indéfinies |
| `templates/depenses.html.twig` | Suppression du filtre `\|raw` sur `json_encode` pour les labels de graphique (prévention XSS) |
| `templates/base.html.twig` | Ajout du filtre `\|escape('css')` sur les variables CSS `--brand*` (prévention CSS injection) |

### 23.2 Alignement UI/UX

| Fichier | Correction |
|---|---|
| `templates/permissions.html.twig` | Suppression du wrapper `<div class="px-3 py-2">` superflu — alignement corrigé avec les autres pages |
| `templates/peremptions.html.twig` | Suppression du double titre `<h2>` redondant avec la topbar |
| `templates/permissions.html.twig` | Suppression du double titre `<h2>` redondant avec la topbar |
| `templates/transferts.html.twig` | Suppression du double titre `<h4>` redondant avec la topbar |
| `articles.php`, `fournisseurs.php`, `utilisateurs.php` | Uniformisation des boutons « Nouveau/Nouvel » en `btn-primary` (cohérence sémantique) |
| `assets/css/style.css` | Ajout de la classe `.glass-badge-qty.is-secondary` pour le badge « Inactif » (fin du style inline) |
| `assets/css/style.css` | Fusion des 2 blocs `@media print` dupliqués en un seul bloc unifié |

### 23.3 Synchronisation des Layouts

| Élément | `base.html.twig` (Twig) | `header.php` (PHP) |
|---|---|---|
| Garde Audit | `peut('audit_consulter')` (RBAC) | `peut('audit_consulter')` (RBAC) |
| Liens Péremptions | Dans le dropdown « Stocks » | Dans le groupe « Logistique » |
| Clôture sidebar footer | Présent | Présent (ajouté) |
| Label section | « Logistique » | « Logistique » (corrigé) |

### 23.4 Correction Fatale — `ticket_print.php`

| Ligne | Erreur | Correction |
|---|---|---|
| 20 | `db_facture_get()` — fonction inexistante | `db_facture_get_by_id()` |
| 27 | `db_lignes_facture()` — fonction inexistante | `db_facture_get_lignes()` |

Les `require_once` (lignes 9-11) étaient corrects. L'erreur venait uniquement d'un **nommage de fonctions** ne correspondant pas à la signature réelle dans `includes/db_functions.php`.

---

## 24. Correctifs supplémentaires

### 24.1 Warning `Undefined array key 'prenom'` / PDOException `Unknown column 'prenom'` — Ticket thermique

**Problème en deux actes :**

| Itération | Fichier | Problème | Correction |
|-----------|---------|----------|------------|
| 1 | `ticket_print.php:49` | `$vendeur['prenom']` sans coalescence → Warning | `($vendeur['prenom'] ?? '')` |
| 1 | `includes/db_functions.php:479` | Ajout de `prenom` dans le SELECT (colonne inexistante) → PDOException | Retour à `SELECT id, nom, login, role, actif` |
| 2 | `ticket_print.php:49` | Accès à `$vendeur['prenom']` malgré colonne absente | `$vendeur['nom'] ?? 'Caissier'` |

**Correction finale** : La colonne `prenom` n'existe pas dans la table `utilisateurs`. Le ticket affiche désormais uniquement le `nom` du vendeur avec fallback `'Caissier'`.

---

## 25. V2 — Polish & Stabilisation (Juillet 2026)

### 25.1 Uniformisation du séparateur de milliers (espace)

**Règle appliquée** : Tous les prix affichés utilisent l'espace comme séparateur de milliers (ex: `1 000 FCFA`). Les fonctions centrales `money()` et `param_money()` appliquent déjà ce format via `number_format(..., ',', ' ')`. Les appels directs à `number_format` sans spécification explicite ont été corrigés.

**Fichiers modifiés :**

| Fichier | Ligne(s) | Correction |
|---------|-----------|-----------|
| `test_cloture_qa.php` | 57, 67, 93, 94, 95, 115 | `number_format(..., 2)` → `number_format(..., 2, ',', ' ')` |

### 25.2 Corrections de bugs détectés

| # | Sévérité | Fichier | Problème | Correction |
|---|----------|---------|----------|------------|
| 1 | **Critique** | `templates/ticket_print.html.twig:67` | `facture.date_vente` — colonne inexistante (la colonne réelle est `date_facture`) | `date_vente` → `date_facture` |
| 4 | **Moyenne** | `valider_facture.php:38-41` | `foreach ($_POST['lignes'] as $l)` plante si POST contient une chaîne au lieu d'un tableau | Guard `!is_array($lignes)` → `$lignes = []` |
| 6 | **Faible** | `test_caisse_checkout.php:389` | Interpolation PHP : `$index + 1.` interprété comme texte littéral | `"$status " . ($index + 1) . ". ..."` |
| 17 | **Moyenne** | `config/connexion.php:135` | `$_SESSION['flash'][]` sans initialisation préalable → Warning PHP 8 | Guard `isset() && is_array()` avant l'ajout |
| 21 | **Faible** | `test_caisse_checkout.php:369` | `strpos` doublon avec le même needle `'vide'` | Second `strpos` → `'erreur'` |
| 22 | **Faible** | `caisse.php:246` | Chemin relatif `assets/js/caisse.js` | Préfixe `<?= BASE_URL ?>` |

### 25.3 Rapport de validation — Agents QA

| Test | Agent | Résultat | Détail |
|------|-------|----------|--------|
| Validation 1 | QA #1 | ✅ **VALIDE** | 6/6 bugs corrigés, formatage prix conforme |
| Contre-validation 2 | QA #2 | ✅ **CONTRE-VALIDÉ** | Aucune régression, vendor/ intact |

**Conclusion** : 6 bugs corrigés, formatage des prix unifié avec séparateur espace, zéro régression.

---

### 25.4 Commentaires Twig `{# ... #}` dans `caisse.php` (affichés en texte brut)

**Problème** : Le fichier `caisse.php` utilise `include header.php` (pas de rendu Twig), mais contenait 6 commentaires au format Twig `{# ... #}`. Ces commentaires étaient interprétés comme du texte HTML et s'affichaient littéralement sur la page.

**Correction** : 6 commentaires Twig convertis en commentaires HTML `<!-- ... -->` :
- `{# ── Panneau Multi-Paiements ── #}` → `<!-- Panneau Multi-Paiements -->`
- `{# Zone Espèces #}` → `<!-- Zone Espèces -->`
- `{# Zone Mobile Money / Carte #}` → `<!-- Zone Mobile Money / Carte -->`
- `{# Progression #}` → `<!-- Progression -->`
- `{# Boutons montant rapide #}` → `<!-- Boutons montant rapide -->`
- `{# Inputs cachés pour les paiements #}` → `<!-- Inputs cachés pour les paiements -->`

**Validation QA :**

| Test | Agent | Résultat |
|------|-------|----------|
| Validation 1 | QA #1 | ✅ **VALIDE** — zéro résidu `{# #}`, HTML intact |
| Contre-validation 2 | QA #2 | ✅ **CONTRE-VALIDÉ** — aucun `{#}` dans les fichiers `.php`, rien d'altéré |

---

## 26. Module 1 — Commandes fournisseurs (Cycle d'achat complet)

### 26.1 Objectif
Implémentation complète du cycle d'achat et d'approvisionnement fournisseur. Permet la création de commandes d'achat manuelles ou pré-remplies depuis les alertes de stock (`suggestions_achat.php`), la gestion des statuts (`Brouillon`, `Envoyee`, `Recue_Partielle`, `Recue`, `Annulee`) et la réception partielle ou totale réintégrant le stock via `process_stock_movement()` (type `Entree`).

### 26.2 Tables SQL
- `commandes_fournisseur` (`id`, `fournisseur_id`, `magasin_id`, `utilisateur_id`, `statut`, `date_commande`, `date_reception_prevue`, `notes`, `date_creation`)
- `lignes_commande_fournisseur` (`id`, `commande_id`, `article_id`, `quantite_commandee`, `quantite_recue`, `prix_achat_unitaire`)
- Script de migration : `database/commandes_fournisseur.sql`

### 26.3 Fonctions ajoutées (`includes/db_functions.php`)
- `db_commandes_fournisseur_search_sql()`
- `db_commande_fournisseur_get_by_id()`
- `db_commande_fournisseur_insert()`
- `db_commande_fournisseur_update()`
- `db_commande_fournisseur_update_statut()`
- `db_commande_fournisseur_lignes_get()`
- `db_commande_fournisseur_lignes_save()`
- `db_commande_fournisseur_lignes_update_reception()`

### 26.4 Fichiers modifiés / créés

| Fichier | Statut | Description |
|---|---|---|
| `database/commandes_fournisseur.sql` | **Créé** | Migration SQL (tables + permissions `achats_gerer` / `achats_consulter`) |
| `commandes_fournisseur.php` | **Créé** | Contrôleur PHP du cycle d'achat (liste, création, édition, réception, annulation) |
| `templates/commandes_fournisseur.html.twig` | **Créé** | Template Twig avec vues liste, détail et formulaire dynamique d'achat |
| `includes/db_functions.php` | **Modifié** | Ajout des requêtes SQL préparées `db_commande_fournisseur_*` |
| `suggestions_achat.php` | **Modifié** | Intégration de l'action POST pour pré-remplir un bon de commande |
| `templates/suggestions_achat.html.twig` | **Modifié** | Ajout du bouton « Créer bon de commande » par fournisseur |
| `templates/base.html.twig` | **Modifié** | Ajout du lien « Commandes d'achat » dans le menu sidebar Logistique |

---

## 27. Module 2 — Catégories d'articles

### 27.1 Objectif
Implémentation de la gestion des catégories d'articles pour classer les produits et faciliter le filtrage multi-critères dans les modules de consultation du stock, de la caisse et d'approvisionnement.

### 27.2 Tables SQL
- Table `categories` (`id`, `nom`, `description`, `actif`)
- Colonne ajoutée sur `articles` : `categorie_id` (INT, nullable, clé étrangère `categories(id)`)
- Script de migration : `database/categories.sql`

### 27.3 Fonctions ajoutées (`includes/db_functions.php`)
- `db_categories_all()`
- `db_categorie_get_by_id()`
- `db_categorie_insert()`
- `db_categorie_update()`
- `db_categorie_deactivate()`
- Extension de `db_articles_search_sql()` et `db_articles_search_stock()` pour le filtrage par `categorie_id` et la jointure de la colonne `categorie_nom`

### 27.4 Fichiers modifiés / créés

| Fichier | Statut | Description |
|---|---|---|
| `database/categories.sql` | **Créé** | Migration SQL (table `categories` + colonne `categorie_id` sur `articles`) |
| `categories.php` | **Créé** | Contrôleur PHP de gestion des catégories (liste, création, édition, désactivation) |
| `templates/categories.html.twig` | **Créé** | Template Twig de gestion des catégories avec modal dynamique |
| `includes/db_functions.php` | **Modifié** | Ajout des fonctions `db_categories_*` et mise à jour de la recherche d'articles |
| `articles.php` | **Modifié** | Prise en compte du champ `categorie_id` à la création/édition des articles |
| `stock.php` | **Modifié** | Ajout du filtre par catégorie et affichage de la colonne Catégorie |
| `templates/base.html.twig` | **Modifié** | Ajout du lien « Catégories » dans la section Logistique de la sidebar |

---

## 28. Module 3 — TVA Multiple

### 28.1 Objectif
Surcharge du taux de TVA par article (pour gérer les articles exonérés, à taux réduit, etc.) à la place du taux global unique. Les factures enregistrent le taux effectif par ligne et calculent la ventilation multi-taux sur les tickets et consultations.

### 28.2 Tables SQL
- Migration : `database/tva_multiple.sql`
- `articles.taux_tva` (DECIMAL(5,2) NULL)
- `lignes_facture.taux_tva` (DECIMAL(5,2) NULL)

### 28.3 Fichiers modifiés / créés

| Fichier | Statut | Description |
|---|---|---|
| `database/tva_multiple.sql` | **Créé** | Script d'ajout de la colonne `taux_tva` dans `articles` et `lignes_facture` |
| `includes/db_functions.php` | **Modifié** | Prise en compte de `taux_tva` dans `db_article_get_by_barcode`, `db_article_insert`, `db_article_update`, `db_ligne_facture_insert` |
| `valider_facture.php` | **Modifié** | Calcul du Total TTC ligne par ligne selon le taux spécifique et enregistrement en BDD |
| `articles.php` | **Modifié** | Ajout de la saisie du taux de TVA spécifique par article dans le formulaire |
| `facture_view.php` | **Modifié** | Ventilation de la TVA multi-taux sur la vue détaillée du ticket de caisse |
| `ticket_print.php` | **Modifié** | Transmission du résumé de ventilation TVA au moteur Twig |
| `templates/ticket_print.html.twig` | **Modifié** | Affichage de la ventilation par taux sur le ticket thermique 80mm |

---

## 29. Module 4 — Inventaire physique (Comptage cyclique)

### 29.1 Objectif
Mise en place d'un module complet d'inventaire physique par session avec comptage cyclique, gestion du stock théorique vs réel, calcul automatique des écarts (manquants/excédents) et ajustement automatique du stock via `process_stock_movement()`.

### 29.2 Tables SQL
- `inventaires` (`id`, `reference`, `magasin_id`, `statut`, `date_debut`, `date_fin`, `utilisateur_id`, `notes`)
- `inventaire_lignes` (`id`, `inventaire_id`, `article_id`, `quantite_theorique`, `quantite_comptee`, `ecart`, `notes`)
- Script de migration : `database/inventaire.sql`
- Permissions RBAC : `inventaire_gerer`, `inventaire_consulter`

### 29.3 Fonctions ajoutées (`includes/db_functions.php`)
- `db_inventaire_insert()`
- `db_inventaire_get_by_id()`
- `db_inventaire_search_sql()`
- `db_inventaire_update_statut()`
- `db_inventaire_lignes_get()`
- `db_inventaire_ligne_upsert()`
- `db_inventaire_appliquer_ecarts()`

### 29.4 Fichiers modifiés / créés

| Fichier | Statut | Description |
|---|---|---|
| `database/inventaire.sql` | **Créé** | Script SQL des tables d'inventaire et des permissions RBAC associées |
| `inventaire.php` | **Créé** | Contrôleur PHP (liste, création de session, saisie AJAX, validation des écarts, annulation) |
| `templates/inventaire.html.twig` | **Créé** | Template Twig avec vue liste, synthèse d'écart et saisie interactive par code-barres / recherche |
| `includes/db_functions.php` | **Modifié** | Ajout des fonctions BDD préparées pour la gestion d'inventaire |
| `templates/base.html.twig` | **Modifié** | Ajout du lien « Inventaire » dans la section Logistique de la sidebar |

---

## 30. Module 5 — Retours & avoirs (SAV)

### 30.1 Objectif
Gestion intégrée des retours d'articles après vente et émission d'avoirs SAV. Permet la sélection partielle des lignes d'une facture payée, le renseignement du motif et la réintégration automatique en stock via `process_stock_movement()` (type `Entree`).

### 30.2 Tables SQL
- `retours_factures` (`id`, `numero_retour`, `facture_id`, `magasin_id`, `utilisateur_id`, `montant_total`, `motif`, `date_retour`, `statut`)
- `lignes_retour` (`id`, `retour_id`, `ligne_facture_id`, `article_id`, `quantite`, `prix_unitaire`)
- Script de migration : `database/retours.sql`
- Permissions RBAC : `retours_gerer`, `retours_consulter`

### 30.3 Fonctions ajoutées (`includes/db_functions.php`)
- `db_retour_insert()`
- `db_retour_get_by_id()`
- `db_retour_lignes_get()`
- `db_retours_search_sql()`
- `db_retour_creer()`

### 30.4 Fichiers modifiés / créés

| Fichier | Statut | Description |
|---|---|---|
| `database/retours.sql` | **Créé** | Migration SQL des tables `retours_factures` et `lignes_retour` + permissions |
| `retours.php` | **Créé** | Contrôleur PHP de gestion des retours (recherche facture, création retour, consultation) |
| `templates/retours.html.twig` | **Créé** | Template Twig avec recherche de facture, sélection dynamique d'articles et fiche avoir |
| `includes/db_functions.php` | **Modifié** | Fonctions SQL préparées pour les retours SAV et la réintégration en stock |
| `templates/base.html.twig` | **Modifié** | Ajout du lien « Retours / SAV » dans le menu Ventes de la sidebar |

---

## 31. Module 6 — Remises et promotions (Codes promo & Remises ciblées)

### 31.1 Objectif
Gestion avancée des règles de promotions, remises ciblées (par article, par catégorie ou sur tout le panier) et des codes promo (coupons) réutilisables ou à limite d'utilisation avec vérification via API REST (`/api/promo`).

### 31.2 Tables SQL
- `promotions` (`id`, `nom`, `code_promo`, `type_reduction`, `valeur`, `article_id`, `categorie_id`, `montant_min_achat`, `date_debut`, `date_fin`, `limite_utilisations`, `nb_utilisations`, `actif`)
- Script de migration : `database/promotions.sql`
- Permissions RBAC : `promotions_gerer`, `promotions_consulter`

### 31.3 Fonctions ajoutées (`includes/db_functions.php`)
- `db_promotion_insert()`
- `db_promotion_update()`
- `db_promotion_get_by_id()`
- `db_promotion_get_by_code()`
- `db_promotion_increment_usage()`
- `db_promotion_deactivate()`
- `db_promotions_search_sql()`

### 31.4 Fichiers modifiés / créés

| Fichier | Statut | Description |
|---|---|---|
| `database/promotions.sql` | **Créé** | Migration SQL de la table `promotions` + permissions RBAC |
| `promotions.php` | **Créé** | Contrôleur PHP de gestion des règles de promotion et codes promo |
| `templates/promotions.html.twig` | **Créé** | Template Twig avec modal d'édition/création dynamique de promotion |
| `includes/db_functions.php` | **Modifié** | Ajout des fonctions SQL préparées pour les promotions et codes promo |
| `api/index.php` | **Modifié** | Ajout du point d'accès API `GET /api/promo?code=XXX&total=YYY` pour vérification caisse |
| `templates/base.html.twig` | **Modifié** | Ajout du lien « Promotions & Remises » dans la section Ventes de la sidebar |

---

## 32. Module 7 — Étiquetage rayon (Générateur EAN-13 & Impression A4)

### 32.1 Objectif
Outil complet de génération et d'impression d'étiquettes de rayon sur planches A4 (grilles 3x8 - 24 étiquettes/page ou 4x10 - 40 étiquettes/page). Intègre le rendu dynamique des codes-barres EAN-13 via JsBarcode, le prix TTC, le nom de la boutique et la quantité paramétrable par article.

### 32.2 Fichiers modifiés / créés

| Fichier | Statut | Description |
|---|---|---|
| `etiquettes.php` | **Créé** | Contrôleur PHP de sélection des articles et génération du flux d'impression |
| `templates/etiquettes.html.twig` | **Créé** | Template Twig avec interface de sélection et feuille de style d'impression A4 (`@media print`) |
| `templates/base.html.twig` | **Modifié** | Ajout du lien « Étiquettes rayon » dans le menu Logistique de la sidebar |

---

## 33. Module 8 — Exports CSV / Excel UTF-8

### 33.1 Objectif
Contrôleur d'export universel au format CSV / Excel avec encodage UTF-8 (BOM `\xEF\xBB\xBF` inclus pour compatibilité native Excel). Permet l'export filtré des stocks, factures, mouvements de stock et comptes-rendus d'inventaire.

### 33.2 Fichiers modifiés / créés

| Fichier | Statut | Description |
|---|---|---|
| `exports.php` | **Créé** | Contrôleur d'export CSV / Excel multi-entités (`type=articles`, `factures`, `mouvements`, `inventaire`) |
| `stock.php` | **Modifié** | Ajout du bouton « Export Excel / CSV » dans la barre d'outils de consultation des stocks |

---

## 34. Module 9 — Notifications Email Basiques

### 34.1 Objectif
Moteur léger de notifications par courriel HTML pour la diffusion automatique et sur demande des alertes de stock faible et de commandes d'achat. Intègre un système de secours (fallback) journalisant les emails simulés dans les logs d'erreurs en environnement de développement local (WampServer).

### 34.2 Fonctions ajoutées (`includes/helpers.php`)
- `envoyer_email_notification()`
- `notifier_stock_faible_email()`

### 34.3 Fichiers modifiés / créés

| Fichier | Statut | Description |
|---|---|---|
| `includes/helpers.php` | **Modifié** | Ajout des helpers d'envoi d'emails HTML et de déclenchement des alertes |
| `suggestions_achat.php` | **Modifié** | Ajout du déclencheur d'envoi d'alertes email sur la liste des réapprovisionnements |

---

## 35. Module 10 — Sécurité complémentaire (2FA TOTP, Backup CLI & Proxy IP)

### 35.1 Objectif
Renforcement de la sécurité de l'application par l'implémentation de la double authentification 2FA TOTP (compatible Google Authenticator / Authy selon la norme RFC 6238 en PHP procédural pur), la résolution sécurisée des adresses IP derrière un reverse proxy / CDN et la création d'un outil CLI d'archivage automatique BDD avec rotation sur 30 jours.

### 35.2 Tables SQL
- Alter `utilisateurs` : ajout des colonnes `totp_secret` (VARCHAR(32)) et `totp_actif` (TINYINT(1))
- Migration : `database/securite_comp.sql`

### 35.3 Fonctions ajoutées (`includes/helpers.php`)
- `obtenir_adresse_ip_client()`
- `totp_generer_secret()`
- `totp_calculer_code()`
- `totp_verifier_code()`

### 35.4 Fichiers modifiés / créés

| Fichier | Statut | Description |
|---|---|---|
| `database/securite_comp.sql` | **Créé** | Migration SQL d'ajout des colonnes 2FA TOTP sur `utilisateurs` |
| `bin/backup_db.php` | **Créé** | Script CLI autonome de sauvegarde BDD, compression ZIP et rotation sur 30 jours |
| `includes/helpers.php` | **Modifié** | Ajout du décodage Base32 RFC 4648, de la génération/validation TOTP RFC 6238 et de la résolution IP proxy |









