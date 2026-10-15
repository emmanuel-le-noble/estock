<?php
/**
 * connexion.php - Connexion PDO à la base MySQL + fonctions communes
 * Inclut aussi le démarrage de session et les utilitaires d'authentification.
 */

// ============================================================
//  0. AUTOLOADER COMPOSER
// ============================================================
require_once __DIR__ . '/../vendor/autoload.php';

// ============================================================
//  0bis. CHARGEUR .env (si vlucas/phpdotenv n'est pas installé)
// ============================================================
function _load_dotenv(string $path): void {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        // Retirer les guillemets
        if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
            $value = substr($value, 1, -1);
        } elseif (strlen($value) >= 2 && $value[0] === "'" && $value[strlen($value) - 1] === "'") {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

// Charger le fichier .env s'il existe
_load_dotenv(__DIR__ . '/../.env');

// ============================================================
//  0ter. FUSEAU HORAIRE (priorité : .env > BDD > défaut Africa/Lome)
// ============================================================
$_app_tz = getenv('APP_TIMEZONE') ?: 'Africa/Lome';
// Sera confirmé/ajusté après chargement des paramètres BDD (voir plus bas)

// ============================================================
//  1. PARAMÈTRES DE CONNEXION  (à adapter à votre WampServer)
// ============================================================

// En production par défaut : l'absence ou une faute de frappe dans APP_ENV ne
// doit jamais activer silencieusement les fallbacks de développement.
$__app_env = getenv('APP_ENV');
$__app_env = $__app_env === false || $__app_env === '' ? 'production' : $__app_env;
if (!in_array($__app_env, ['development', 'production'], true)) {
    error_log('[CRITIQUE] APP_ENV invalide. Valeurs admises : development ou production.');
    http_response_code(500);
    die('Configuration invalide : APP_ENV doit valoir development ou production.');
}
define('APP_ENV', $__app_env);
$__is_cli = (php_sapi_name() === 'cli');
$__is_production = APP_ENV === 'production';

/**
 * En production, la configuration manquante est une erreur fatale (aucun
 * fallback silencieux). En développement, un fallback est accepté mais
 * toujours journalisé en alerte.
 */
function __require_env(string $key, string $default, string $label): string {
    $val = getenv($key);
    if ($val !== false && $val !== '') {
        return $val;
    }
    if (defined('APP_ENV') && APP_ENV === 'production') {
        error_log("[CRITIQUE] $label ($key) non définie en environnement production. Démarrage refusé.");
        http_response_code(500);
        die("Configuration incomplète : $label non définie en environnement production. Configurez la variable d'environnement $key.");
    }
    error_log("[ALERTE] $label ($key) non définie — valeur par défaut utilisée (mode développement uniquement).");
    return $default;
}

define('DB_HOST', __require_env('DB_HOST', '127.0.0.1', 'Hôte MySQL'));
define('DB_NAME', __require_env('DB_NAME', 'estock_db', 'Nom de la base MySQL'));

$dbUser = getenv('DB_USER');
if ($dbUser === false || $dbUser === '') {
    if ($__is_production) {
        error_log('[CRITIQUE] DB_USER non défini en environnement production.');
        http_response_code(500);
        die('Configuration incomplète : DB_USER non définie en environnement production.');
    }
    error_log('[ALERTE] DB_USER non défini — utilisateur "root" utilisé (mode développement uniquement).');
    $dbUser = 'root';
}
define('DB_USER', $dbUser);

// Un mot de passe vide est autorisé en développement local (ex. MySQL root
// sans mot de passe sous Wamp/XAMPP). En production, DB_PASS reste obligatoire.
$dbPass = getenv('DB_PASS');
if ($dbPass === false || $dbPass === '') {
    if ($__is_production) {
        error_log('[CRITIQUE] DB_PASS non défini en environnement production.');
        if ($__is_cli) {
            die("Erreur : DB_PASS non définie. Configurez votre fichier .env ou les variables d'environnement avant de lancer les scripts CLI.\n");
        }
        http_response_code(500);
        die('Variable DB_PASS non définie en production. Configurez votre fichier .env ou les variables d\'environnement.');
    }
    error_log('[ALERTE] DB_PASS vide — connexion MySQL sans mot de passe (mode développement uniquement).');
    $dbPass = '';
}
define('DB_PASS', $dbPass);

define('DB_CHARSET', 'utf8mb4');

define('ROLE_DIRECTEUR', 'Directeur');
define('ROLE_ADMIN', 'Admin');
define('ROLE_MAGASINIER', 'Magasinier');
define('ROLE_VENDEUR', 'Vendeur');

$secretKey = getenv('SECRET_URL_KEY');
$exampleSecretKey = 'votre_cle_secrete_au_moins_32_caracteres_ici';
if ($secretKey === false || strlen($secretKey) < 32 || hash_equals($exampleSecretKey, $secretKey)) {
    error_log('[CRITIQUE] SECRET_URL_KEY non défini ou trop court (< 32 caractères).');
    if ($__is_cli) {
        die("Erreur : SECRET_URL_KEY manquante ou trop courte (minimum 32 caractères). Configurez votre fichier .env.\n");
    }
    http_response_code(500);
    die('SECRET_URL_KEY manquant ou trop court. Configurez votre fichier .env (minimum 32 caractères).');
}
define('SECRET_URL_KEY', $secretKey);

// Version applicative centralisée (affichée en pied de page / paramètres)
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '2.0.0');
}

// ============================================================
//  1bis. URL RACINE DYNAMIQUE (s'adapte au sous-dossier Wamp)
// ============================================================
$_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
// HTTP_HOST est contrôlé par le client : ne jamais le refléter tel quel dans
// BASE_URL (liens Twig, emails, redirections). Les hôtes valides incluent un
// port optionnel pour WampServer en développement.
if (!preg_match('/\A[a-zA-Z0-9.-]+(?::\d{1,5})?\z/', $_host)) {
    $_host = 'localhost';
}
$_path   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
define('BASE_URL', $_scheme . '://' . $_host . $_path . '/');

// ============================================================
//  2. CONNEXION PDO
// ============================================================
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    // Rendu disponible pour les inclusions en portée non globale (ex : bootstrap PHPUnit)
    $GLOBALS['pdo'] = $pdo;
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Erreur connexion BDD: ' . $e->getMessage());
    die('Erreur de connexion à la base de données. Vérifiez la configuration.');
}

// ============================================================
//  3. SESSION
// ============================================================
if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly'  => true,
        'samesite'  => 'Lax',
    ]);
    session_start();
}

// Session timeout : idle 30 min / absolu 8 h
if (php_sapi_name() !== 'cli' && isset($_SESSION['user'])) {
    $now = time();
    if (!isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = $now;
    }
    $IDLE_TIMEOUT  = 30 * 60;   // 30 minutes
    $ABSOLUTE_TIMEOUT = 8 * 3600; // 8 heures

    if (($now - $_SESSION['last_activity']) > $IDLE_TIMEOUT) {
        session_unset();
        session_destroy();
        header('Location: auth/login.php?timeout=1');
        exit;
    }
    if (!isset($_SESSION['login_time'])) {
        $_SESSION['login_time'] = $now;
    }
    if (($now - $_SESSION['login_time']) > $ABSOLUTE_TIMEOUT) {
        session_unset();
        session_destroy();
        header('Location: auth/login.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = $now;
}

// ============================================================
//  VERIFICATION INSTALLATION
//  Redirige vers install.php si aucun admin/magasin n'existe
//  (sauf si on est deja sur install.php ou en CLI)
// ============================================================
if (php_sapi_name() !== 'cli' && !$__is_cli) {
    $currentScript = basename($_SERVER['PHP_SELF'] ?? '');
    if ($currentScript !== 'install.php') {
        try {
            $checkAdmin = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role IN ('Directeur','Admin') AND actif = 1")->fetchColumn();
            $checkMag   = $pdo->query("SELECT COUNT(*) FROM magasins WHERE actif = 1")->fetchColumn();
            if ((int)$checkAdmin === 0 || (int)$checkMag === 0) {
                header('Location: install.php');
                exit;
            }
        } catch (\Throwable $e) {
            // Table n'existe pas encore → installation requise
            header('Location: install.php');
            exit;
        }
    }
}

// Générer un nonce CSP par requête (plus de réutilisation inter-requests)
if (php_sapi_name() === 'cli') {
    $_SESSION = [];
}
$_SESSION['csp_nonce'] = bin2hex(random_bytes(16));

// ============================================================
//  3bis. EN-TÊTES DE SÉCURITÉ
// ============================================================
require_once __DIR__ . '/../includes/security_headers.php';

// ============================================================
//  3ter. PARAMÈTRES DE LA BOUTIQUE (configuration globale)
// ============================================================
require_once __DIR__ . '/parametres.php';

// Appliquer le fuseau horaire configuré (priorité BDD > .env > défaut)
$tz_db = param('fuseau_horaire', '');
$tz = $tz_db !== '' ? $tz_db : $_app_tz;
if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
    $tz = 'Africa/Lome';
}
date_default_timezone_set($tz);

// Forcer aussi via ini_set (redondant mais sûr)
ini_set('date.timezone', $tz);

// [CORRECTION BUG 4 - Internationalisation Middleware]
if (!isset($_SESSION['langue'])) {
    $_SESSION['langue'] = param('langue', 'fr');
}
$langue_site = $_SESSION['langue'];
ini_set('default_charset', 'UTF-8');
setlocale(LC_ALL, $langue_site . '_' . strtoupper($langue_site) . '.UTF-8', $langue_site);

// ============================================================
//  4. HELPERS GLOBAUX
// ============================================================

/**
 * Sécuriser l'affichage (anti-XSS).
 */
function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Convertit une valeur HTTP/JSON en texte sans accepter les tableaux. */
function input_string(mixed $value): string {
    return is_scalar($value) ? trim((string)$value) : '';
}

/**
 * Formater un montant avec la devise configurée dans les paramètres.
 */
function money($montant): string {
    $decimales = (int)param('devise_decimales', '0');
    return number_format((float)$montant, $decimales, ',', ' ') . ' ' . param('devise_symbole', 'FCFA');
}

/**
 * Formater une date MySQL (YYYY-MM-DD HH:MM:SS) en FR.
 */
function date_fr($datetime, $avec_heure = true): string {
    if (empty($datetime)) return '';
    $ts = strtotime($datetime);
    return $avec_heure
        ? date('d/m/Y H:i', $ts)
        : date('d/m/Y', $ts);
}

/**
 * Rediriger proprement.
 */
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

/**
 * Retourne le nonce CSP courant pour la session.
 */
function csp_nonce(): string {
    return $_SESSION['csp_nonce'] ?? '';
}

/**
 * Messages flash (stockés en session puis affichés une fois).
 */
function flash(string $type, string $message): void {
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}
function flash_success(string $m): void { flash('success', $m); }
function flash_error(string $m): void   { flash('danger', $m); }
function flash_info(string $m): void    { flash('info', $m); }

/** Affiche et vide les messages flash (à appeler dans les pages). */
function afficher_flash(): void {
    if (empty($_SESSION['flash'])) return;
    foreach ($_SESSION['flash'] as $f) {
        $icone = ['success'=>'check-circle','danger'=>'exclamation-triangle',
                  'warning'=>'exclamation-triangle','info'=>'info-circle'][$f['type']] ?? 'bell';
        echo '<div class="alert alert-' . h($f['type']) . ' alert-dismissible fade show" role="alert">'
           . '<i class="bi bi-' . $icone . '"></i> ' . h($f['message'])
           . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>'
           . '</div>';
    }
    unset($_SESSION['flash']);
}

/**
 * Renvoie du JSON et termine le script (pour appels AJAX).
 */
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

// ============================================================
//  5. AUTHENTIFICATION & RÔLES
// ============================================================

/** Vrai si un utilisateur est connecté. */
function est_connecte(): bool {
    static $checked = false;
    static $authenticated = false;
    if ($checked) return $authenticated;
    $checked = true;

    $userId = (int)($_SESSION['user']['id'] ?? 0);
    if ($userId <= 0) return false;

    global $pdo;
    try {
        $stmt = $pdo->prepare('SELECT id, nom, login, role, magasin_id, actif FROM utilisateurs WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || (int)$user['actif'] !== 1) {
            unset($_SESSION['user'], $_SESSION['magasin_actif']);
            return false;
        }
        // Les changements de rôle et de magasin prennent effet à la prochaine
        // requête, sans attendre l'expiration complète de la session.
        $_SESSION['user'] = array_merge($_SESSION['user'], $user);
        if ($user['role'] !== ROLE_DIRECTEUR) {
            if ((int)$user['magasin_id'] > 0) {
                $_SESSION['magasin_actif'] = (int)$user['magasin_id'];
            } else {
                unset($_SESSION['magasin_actif']);
            }
        } elseif ((int)($_SESSION['magasin_actif'] ?? 0) > 0) {
            $storeStmt = $pdo->prepare('SELECT id FROM magasins WHERE id = ? AND actif = 1 LIMIT 1');
            $storeStmt->execute([(int)$_SESSION['magasin_actif']]);
            if (!$storeStmt->fetchColumn()) unset($_SESSION['magasin_actif']);
        }
        $authenticated = true;
        return true;
    } catch (Throwable $e) {
        error_log('Vérification de session impossible : ' . $e->getMessage());
        return false;
    }
}

/** Données utilisateur courant. */
function user_courant(): ?array {
    return $_SESSION['user'] ?? null;
}

/** Rôle courant. */
function user_role(): string {
    return $_SESSION['user']['role'] ?? '';
}

/** ID de l'utilisateur courant. */
function user_id(): int {
    return (int)($_SESSION['user']['id'] ?? 0);
}

/** ID du magasin de suivi actif (choisi par le directeur, sinon magasin d'appartenance, 1 par défaut). */
function user_magasin_id(): int {
    $actif = (int)($_SESSION['magasin_actif'] ?? 0);
    if ($actif > 0) {
        return $actif;
    }
    $mId = (int)($_SESSION['user']['magasin_id'] ?? 1);
    return $mId > 0 ? $mId : 1;
}

/** Vrai si l'utilisateur n'est rattaché à aucun magasin (directeur global). */
function user_magasin_global(): bool {
    return empty($_SESSION['user']['magasin_id']);
}

/** Nom du magasin de suivi actif (pour la topbar). */
function user_magasin_nom(PDO $pdo): string {
    $nom = $pdo->prepare("SELECT nom FROM magasins WHERE id = ?");
    $nom->execute([user_magasin_id()]);
    $res = $nom->fetchColumn();
    return $res !== false ? (string)$res : '—';
}

/**
 * Exige une connexion. Redirige vers login si absent.
 */
function exiger_connexion(): void {
    if (!est_connecte()) {
        header('Location: auth/login.php');
        exit;
    }
}

/**
 * Exige que l'utilisateur ait l'un des rôles autorisés.
 * Sinon : page 403.
 */
function exiger_role(string ...$roles_autorises): void {
    exiger_connexion();
    if (!in_array(user_role(), $roles_autorises, true)) {
        http_response_code(403);
        include __DIR__ . '/../includes/acces_refuse.php';
        exit;
    }
}

/**
 * Raccourcis de rôles pour la lisibilité.
 */
function peut_gerer_stock(): bool {
    return in_array(user_role(), [ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_MAGASINIER], true);
}
function peut_facturer(): bool {
    return in_array(user_role(), [ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_VENDEUR], true);
}
function peut_administrer(): bool {
    return in_array(user_role(), [ROLE_DIRECTEUR, ROLE_ADMIN], true);
}

// ============================================================
//  5bis. SYSTÈME D'AUTORISATIONS DYNAMIQUES (RBAC)
// ============================================================

/**
 * Charger les permissions du rôle courant depuis la BDD.
 */
function _load_user_permissions(): array {
    global $pdo;
    static $cache = [];
    
    $role = user_role();
    if (empty($role)) return [];
    
    if (isset($cache[$role])) return $cache[$role];
    
    try {
        $stmt = $pdo->prepare(
            'SELECT p.cle_permission
             FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_nom = :role'
        );
        $stmt->execute([':role' => $role]);
        $cache[$role] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        error_log('Erreur chargement permissions: ' . $e->getMessage());
        $cache[$role] = [];
    }
    
    return $cache[$role];
}

/**
 * Vérifier si le rôle de l'utilisateur possède une permission.
 */
function peut(string $cle_permission): bool {
    if (!est_connecte()) return false;
    $perms = _load_user_permissions();
    return in_array($cle_permission, $perms, true);
}

/**
 * Exiger une permission. Bloque le script avec 403 si refusé.
 */
function exiger_permission(string $cle_permission): void {
    exiger_connexion();
    if (!peut($cle_permission)) {
        http_response_code(403);
        include __DIR__ . '/../includes/acces_refuse.php';
        exit;
    }
}

// ============================================================
//  6. PROTECTION CSRF
// ============================================================

/**
 * Générer ou récupérer un token CSRF.
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Afficher un champ hidden avec le token CSRF.
 */
function csrf_field(): string {
    return '<input type="hidden" name="_csrf_token" value="' . h(csrf_token()) . '">';
}

/**
 * Valider un token CSRF.
 */
function csrf_validate(): bool {
    $token = input_string($_POST['_csrf_token'] ?? $_POST['csrf_token'] ?? '');
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function verify_csrf_token(mixed $token = ''): bool {
    if (empty($token)) {
        $token = $_POST['_csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }
    $token = input_string($token);
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ============================================================
//  7. RATE LIMITING (protection brute-force login)
// ============================================================
require_once __DIR__ . '/../includes/db_functions.php';

function login_attempt(string $login): void {
    global $pdo;
    db_login_attempt_insert($pdo, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $login);
}

function login_rate_limited(): bool {
    global $pdo;
    return db_login_attempt_count($pdo, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1') >= 5;
}

function clear_login_attempts(string $login): void {
    global $pdo;
    db_login_attempt_clear($pdo, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $login);
}

// ============================================================
//  8. PAGINATION (Version compatible avec paramètres ? et :)
// ============================================================

function paginate($sql, array $params = [], int $par_page = 25, $countSql = null): array {
    global $pdo;

    // Si le 1er paramètre est $pdo (PDO instance), décaler les arguments
    if ($sql instanceof PDO) {
        $sql = is_string($params) ? $params : '';
        $params = is_array($par_page) ? $par_page : (is_array($countSql) ? $countSql : []);
        $par_page = 25;
        $args = func_get_args();
        foreach ($args as $arg) {
            if (is_int($arg) && $arg > 0) {
                $par_page = $arg;
            }
        }
        $countSql = null;
    }

    if ($countSql === null || !is_string($countSql)) {
        // Compter via sous-requête : gère GROUP BY, DISTINCT et les collisions d'alias
        $countSql = "SELECT COUNT(*) FROM (" . trim(rtrim($sql, ";\t\n ")) . ") AS __count_rows";
    }
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $page = max(1, (int)($_GET['page'] ?? 1));
    $total_pages = max(1, (int)ceil($total / $par_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $par_page;

    // Détecter si la requête d'origine utilise des paramètres positionnels (?) ou nommés (:)
    $conserve_positional = empty($params) || isset($params[0]) || array_key_exists(0, $params);

    if ($conserve_positional) {
        // Si la requête utilise des "?", on ajoute des "?" pour LIMIT et OFFSET
        $sql .= " LIMIT ? OFFSET ?";
        $stmt = $pdo->prepare($sql);
        
        // On lie les paramètres d'origine un par un
        $idx = 1;
        foreach ($params as $val) {
            $stmt->bindValue($idx++, $val);
        }
        // On lie le LIMIT et l'OFFSET à la suite avec le bon type INTEGER
        $stmt->bindValue($idx++, (int)$par_page, PDO::PARAM_INT);
        $stmt->bindValue($idx++, (int)$offset, PDO::PARAM_INT);
    } else {
        // Si la requête d'origine utilise des paramètres nommés (:param)
        $sql .= " LIMIT :limit_val OFFSET :offset_val";
        $stmt = $pdo->prepare($sql);
        
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit_val', (int)$par_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset_val', (int)$offset, PDO::PARAM_INT);
    }

    $stmt->execute();
    $items = $stmt->fetchAll();

    return [
        'items'       => $items,
        'data'        => $items,
        'page'        => $page,
        'total_pages' => $total_pages,
        'total'       => $total,
    ];
}

function pagination_links(int $page, int $total_pages, string $base_url = '?'): void {
    if ($total_pages <= 1) return;
    echo '<nav><ul class="pagination pagination-sm justify-content-center mt-3">';
    if ($page > 1) {
        $sep = str_contains($base_url, '?') ? '&' : '?';
        echo '<li class="page-item"><a class="page-link" href="' . h($base_url . $sep . 'page=' . ($page - 1)) . '">&laquo;</a></li>';
    }
    for ($i = 1; $i <= $total_pages; $i++) {
        $active = $i === $page ? ' active' : '';
        $sep = str_contains($base_url, '?') ? '&' : '?';
        echo '<li class="page-item' . $active . '"><a class="page-link" href="' . h($base_url . $sep . 'page=' . $i) . '">' . $i . '</a></li>';
    }
    if ($page < $total_pages) {
        $sep = str_contains($base_url, '?') ? '&' : '?';
        echo '<li class="page-item"><a class="page-link" href="' . h($base_url . $sep . 'page=' . ($page + 1)) . '">&raquo;</a></li>';
    }
    echo '</ul></nav>';
}

require_once __DIR__ . '/../includes/db_functions.php';
require_once __DIR__ . '/../includes/helpers.php';

// ============================================================
//  9. MOTEUR DE TEMPLATE TWIG
// ============================================================
$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../templates');
$twig = new \Twig\Environment($loader, [
    'cache'       => false,
    'auto_reload' => true,
    'strict_variables' => $__is_production,
]);

$twig->addFunction(new \Twig\TwigFunction('money', 'money'));
$twig->addFunction(new \Twig\TwigFunction('date_fr', 'date_fr'));
$twig->addFunction(new \Twig\TwigFunction('h', 'h', ['is_safe' => ['html']]));
$twig->addFunction(new \Twig\TwigFunction('csrf_field', 'csrf_field', ['is_safe' => ['html']]));
$twig->addFunction(new \Twig\TwigFunction('csp_nonce', 'csp_nonce'));
$twig->addFunction(new \Twig\TwigFunction('param', 'param'));
$twig->addFunction(new \Twig\TwigFunction('param_app_name', 'param_app_name'));
$twig->addFunction(new \Twig\TwigFunction('peut', 'peut'));
$twig->addFunction(new \Twig\TwigFunction('generate_signed_url', 'generate_signed_url'));
$twig->addFunction(new \Twig\TwigFunction('url_sign', 'url_sign'));
$twig->addFunction(new \Twig\TwigFunction('user_magasin_id', 'user_magasin_id'));
$twig->addFunction(new \Twig\TwigFunction('user_magasin_nom', function() use ($pdo) { return user_magasin_nom($pdo); }));
$twig->addFunction(new \Twig\TwigFunction('pagination_links', function($pagination, string $base_url = '?', array $extra = []) {
    if (is_array($pagination)) {
        $page = (int)($pagination['page'] ?? 1);
        $total_pages = (int)($pagination['total_pages'] ?? 1);
    } else {
        $page = (int)$pagination;
        $total_pages = (int)$base_url;
        $base_url = '?';
    }
    ob_start();
    pagination_links($page, $total_pages, $base_url);
    return ob_get_clean();
}, ['is_safe' => ['html']]));

$twig->addGlobal('base_url', BASE_URL);
$twig->addGlobal('current_user', user_courant());
$twig->addGlobal('current_role', user_role());
$twig->addGlobal('is_logged_in', est_connecte());
$twig->addGlobal('can_manage_stock', peut_gerer_stock());
$twig->addGlobal('can_bill', peut_facturer());
$twig->addGlobal('can_admin', peut_administrer());
$twig->addGlobal('csp_nonce_val', csp_nonce());
// Capturer les flash messages une seule fois (pour Twig + footer PHP)
$flash_captured = $_SESSION['flash'] ?? [];
$twig->addGlobal('flash_messages', $flash_captured);
unset($_SESSION['flash']);
$twig->addGlobal('csrf_token', csrf_token());
$page_route = str_replace('.php', '', basename($_SERVER['PHP_SELF']));
$page_nav = $_GET['_page'] ?? ($page_route === 'parametres' && ($_GET['tab'] ?? '') === 'droits' ? 'droits' : $page_route);
$twig->addGlobal('page_courante', $page_nav);

$twig->addGlobal('nb_alertes_topbar', (est_connecte() && peut_gerer_stock()) ? db_articles_low_stock_count($pdo, user_magasin_id()) : 0);
$twig->addGlobal('nb_alertes_peremption', (est_connecte() && peut('stock_consulter')) ? db_peremption_alert_count($pdo, 30, user_magasin_id()) : 0);

// ============================================================
//  GESTIONNAIRE GLOBAL D'EXCEPTIONS (filet de sécurité)
// ============================================================
set_exception_handler(function (\Throwable $e) {
    error_log('[EXCEPTION NON CAPTUREE] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (php_sapi_name() !== 'cli' && !headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    if (php_sapi_name() !== 'cli') {
        echo '<!DOCTYPE html><html><head><title>Erreur interne</title></head><body>';
        echo '<h1>Une erreur interne est survenue.</h1>';
        echo '<p>Veuillez réessayer ultérieurement. Si le problème persiste, contactez l\'administrateur.</p>';
        echo '</body></html>';
    }
    exit(1);
});
