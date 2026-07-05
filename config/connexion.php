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
//  1. PARAMÈTRES DE CONNEXION  (à adapter à votre WampServer)
// ============================================================
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'estock_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
if (!getenv('DB_PASS')) {
    error_log('[SECURITE] DB_PASS non défini — utilizing fallback password. Set DB_PASS in production!');
}
define('DB_PASS', getenv('DB_PASS') ?: 'admin');
define('DB_CHARSET', 'utf8mb4');

define('ROLE_DIRECTEUR', 'Directeur');
define('ROLE_ADMIN', 'Admin');
define('ROLE_MAGASINIER', 'Magasinier');
define('ROLE_VENDEUR', 'Vendeur');

if (!getenv('SECRET_URL_KEY')) {
    error_log('[SECURITE] SECRET_URL_KEY non défini — utilizing default. Set SECRET_URL_KEY in production!');
}
define('SECRET_URL_KEY', getenv('SECRET_URL_KEY') ?: 'estock_hmac_changez_moi_en_prod_2024');

// ============================================================
//  1bis. URL RACINE DYNAMIQUE (s'adapte au sous-dossier Wamp)
// ============================================================
$_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
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
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Erreur connexion BDD: ' . $e->getMessage());
    die('Erreur de connexion à la base de données. Vérifiez la configuration.');
}

// ============================================================
//  3. SESSION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly'  => true,
        'samesite'  => 'Lax',
    ]);
    session_start();
}

// Générer un nonce CSP par requête
if (empty($_SESSION['csp_nonce'])) {
    $_SESSION['csp_nonce'] = bin2hex(random_bytes(16));
}

// ============================================================
//  3bis. EN-TÊTES DE SÉCURITÉ
// ============================================================
require_once __DIR__ . '/../includes/security_headers.php';

// ============================================================
//  3ter. PARAMÈTRES DE LA BOUTIQUE (configuration globale)
// ============================================================
require_once __DIR__ . '/parametres.php';

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

/**
 * Formater un montant avec la devise configurée dans les paramètres.
 * [CORRECTION FORMATAGE 3 - Séparateur obligatoire Espace]
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
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

// ============================================================
//  5. AUTHENTIFICATION & RÔLES
// ============================================================

/** Vrai si un utilisateur est connecté. */
function est_connecte(): bool {
    return !empty($_SESSION['user']);
}

/** Données utilisateur courant. */
function user_courant(): ?array {
    return $_SESSION['user'] ?? null;
}

/** Rôle courant. */
function user_role(): string {
    return $_SESSION['user']['role'] ?? '';
}

/**
 * Exige une connexion. Redirige vers login si absent.
 */
function exiger_connexion(): void {
    if (!est_connecte()) {
        header('Location: login.php');
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
function csrf_field(): void {
    echo '<input type="hidden" name="_csrf_token" value="' . h(csrf_token()) . '">';
}

/**
 * Valider un token CSRF.
 */
function csrf_validate(): bool {
    $token = $_POST['_csrf_token'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
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
//  8. PAGINATION
// ============================================================

function paginate(string $sql, array $params = [], int $par_page = 25): array {
    global $pdo;

    $countSql = preg_replace('/SELECT\s+.+?\s+FROM/is', 'SELECT COUNT(*) FROM', $sql, 1);
    $countSql = preg_replace('/GROUP BY\s+.+$/i', '', $countSql);
    $countSql = preg_replace('/ORDER BY\s+.+$/i', '', $countSql);
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $page = max(1, (int)($_GET['page'] ?? 1));
    $total_pages = max(1, (int)ceil($total / $par_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $par_page;

    $sql .= " LIMIT $par_page OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    return compact('items', 'page', 'total_pages', 'total');
}

function pagination_links(int $page, int $total_pages, string $base_url = '?'): void {
    if ($total_pages <= 1) return;
    echo '<nav><ul class="pagination pagination-sm justify-content-center mt-3">';
    if ($page > 1) {
        $sep = str_contains($base_url, '?') ? '&' : '?';
        echo "<li class=\"page-item\"><a class=\"page-link\" href=\"{$base_url}{$sep}page=" . ($page - 1) . "\">&laquo;</a></li>";
    }
    for ($i = 1; $i <= $total_pages; $i++) {
        $active = $i === $page ? ' active' : '';
        $sep = str_contains($base_url, '?') ? '&' : '?';
        echo "<li class=\"page-item$active\"><a class=\"page-link\" href=\"{$base_url}{$sep}page=$i\">$i</a></li>";
    }
    if ($page < $total_pages) {
        $sep = str_contains($base_url, '?') ? '&' : '?';
        echo "<li class=\"page-item\"><a class=\"page-link\" href=\"{$base_url}{$sep}page=" . ($page + 1) . "\">&raquo;</a></li>";
    }
    echo '</ul></nav>';
}

// ============================================================
//  9. MOTEUR DE TEMPLATE TWIG
// ============================================================
$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../templates');
$twig = new \Twig\Environment($loader, [
    'cache'       => false,
    'auto_reload' => true,
    'strict_variables' => false,
]);

$twig->addFunction(new \Twig\TwigFunction('money', 'money'));
$twig->addFunction(new \Twig\TwigFunction('date_fr', 'date_fr'));
$twig->addFunction(new \Twig\TwigFunction('h', 'h'));
$twig->addFunction(new \Twig\TwigFunction('csrf_field', 'csrf_field'));
$twig->addFunction(new \Twig\TwigFunction('csp_nonce', 'csp_nonce'));
$twig->addFunction(new \Twig\TwigFunction('param', 'param'));
$twig->addFunction(new \Twig\TwigFunction('param_app_name', 'param_app_name'));
$twig->addFunction(new \Twig\TwigFunction('peut', 'peut'));

$twig->addGlobal('base_url', BASE_URL);
$twig->addGlobal('current_user', user_courant());
$twig->addGlobal('current_role', user_role());
$twig->addGlobal('is_logged_in', est_connecte());
$twig->addGlobal('can_manage_stock', peut_gerer_stock());
$twig->addGlobal('can_bill', peut_facturer());
$twig->addGlobal('can_admin', peut_administrer());
$twig->addGlobal('csp_nonce_val', csp_nonce());
$twig->addGlobal('flash_messages', $_SESSION['flash'] ?? []);
$twig->addGlobal('page_courante', $_GET['_page'] ?? str_replace('.php', '', basename($_SERVER['PHP_SELF'])));

$twig->addGlobal('nb_alertes_topbar', (est_connecte() && peut_gerer_stock()) ? db_articles_low_stock_count($pdo) : 0);
$twig->addGlobal('nb_alertes_peremption', (est_connecte() && peut('stock_consulter')) ? db_peremption_alert_count($pdo, 30) : 0);