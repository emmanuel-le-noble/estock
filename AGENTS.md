# AGENTS.md — eStock

## Project Overview

PHP/MySQL POS & stock management app. Procedural PHP, Twig 3, Bootstrap 5, Vanilla JS. No framework, no MVC.

## Key Commands

```bash
# No build system, no test framework, no linter configured
# This is a traditional PHP app served by WampServer

# Install dependencies (if vendor/ missing)
composer install

# Access the app
http://localhost/eSotck/
```

## Architecture

### Entry Points
- `index.php` — Front controller (AES-256-CBC encrypted routes)
- `api/index.php` — REST API router (JSON responses)
- `login.php` / `logout.php` — Auth pages (accessible directly)

### Core Files
- `config/connexion.php` — PDO, session, auth helpers, CSRF, RBAC, Twig setup, pagination
- `config/parametres.php` — Shop parameters (auto-creates table on first run)
- `includes/db_functions.php` — All SQL queries (4200+ lines, 180+ functions)
- `includes/helpers.php` — Validation, business logic, HTML rendering, HMAC URLs
- `includes/security_headers.php` — HTTP security headers (CSP with nonce)

### Frontend
- `assets/css/style.css` — Global styles
- `assets/js/app.js` — Global JS (sidebar, filters)
- `assets/js/caisse.js` — POS logic (barcode scan, cart, offline sync)

### Templates
- `templates/base.html.twig` — Master layout (sidebar + topbar)
- Other `.html.twig` files in `templates/` for specific pages

## Code Conventions

### PHP
- **Procedural style** — no classes, no namespaces, no autoloading (except Twig via Composer)
- **Every function receives `$pdo` as first parameter** — no `global $pdo` inside functions
- **All SQL uses prepared statements** — PDO with `PDO::ATTR_EMULATE_PREPARES => false`
- **Constants for roles**: `ROLE_DIRECTEUR`, `ROLE_ADMIN`, `ROLE_MAGASINIER`, `ROLE_VENDEUR`
- **Functions prefixed by entity**: `db_article_*`, `db_user_*`, `db_facture_*`, etc.
- **Search functions return SQL + params**: `db_articles_search_sql()` returns `['sql' => ..., 'params' => [...]]`

### Security Pattern (mandatory for all POST handlers)
```php
// 1. CSRF guard
csrf_guard('redirect_page.php');

// 2. Extract & validate POST data
$data = extract_post_data([
    'field' => ['type' => 'string', 'required' => true, 'max' => 200, 'redirect' => ''],
], 'redirect_page.php');

// 3. Use db_transaction for write operations
db_transaction(
    function(PDO $pdo) use ($data) { /* ... */ },
    'Success message',
    'Error message',
    'redirect_page.php'
);
```

### URLs Signées HMAC
- All links with `?id=` must use `generate_signed_url()` / `verify_url_signature()`
- Token expires after 24h
- Example: `generate_signed_url('articles.php', $id, ['action' => 'editer'])`

### Twig Templates
- Autoescaping enabled (HTML)
- Global variables: `base_url`, `current_user`, `current_role`, `csrf_token`, `csp_nonce_val`
- Functions available: `h()`, `money()`, `date_fr()`, `csrf_field()`, `peut()`

## Database

- **Engine**: MySQL (WampServer compatible)
- **Charset**: `utf8mb4`
- **Connection**: `127.0.0.1` (not `localhost` to avoid socket issues)
- **Schema files**: `database/*.sql` (run in order if manual install)
- **Auto-setup**: `config/parametres.php` creates `parametres` table if missing

### Key Tables
- `articles`, `stock_magasins` (multi-store), `article_lots` (FEFO tracking)
- `factures`, `lignes_facture`, `paiements`
- `utilisateurs`, `permissions`, `role_permissions` (RBAC)
- `logs_activite` (audit trail), `login_attempts` (rate limiting)

## Environment Variables (.env)

Required:
- `DB_PASS` — MySQL password (app won't start without it)
- `SECRET_URL_KEY` — HMAC key, min 32 chars (app won't start without it)

Optional:
- `DB_HOST`, `DB_NAME`, `DB_USER` — have defaults
- `APP_ENV` — `development` or `production`

## Common Pitfalls

1. **Never add `global $pdo` inside functions** — always receive as first parameter
2. **Never use `getMessage()` in user-facing errors** — use generic messages + `error_log()`
3. **Always use `h()` for HTML output** — XSS protection
4. **Always use `csrf_guard()` on POST** — CSRF protection
5. **Always use `generate_signed_url()` for links with `?id=`** — IDOR protection
6. **Stock operations must use `FOR UPDATE`** — race condition prevention
7. **Transactions**: `db_article_insert()` already initializes stock — don't add redundant movements

## RBAC Permissions

Check with `peut('permission_key')` or `exiger_permission('permission_key')`.

Key permissions: `stock_gerer`, `articles_gerer`, `caisse_gerer`, `facturation_gerer`, `utilisateurs_gerer`, `parametres_gerer`.

## Audit Trail

All sensitive actions must call:
```php
suivre_activite('ACTION_NAME', 'Description');
```
- Never log sensitive data (passwords, tokens)
- Use generic messages for user-facing errors

## Frontend Notes

- Bootstrap 5 via CDN (no local build)
- JsBarcode for barcode rendering (CDN)
- Chart.js for statistics (CDN)
- CSP nonce required on all inline `<script>` tags: `<script nonce="<?= h(csp_nonce()) ?>">`
- Offline POS support: sales queued in localStorage, synced via `api/caisse/sync`
