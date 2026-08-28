<?php
/**
 * install.php - Assistant d'installation initial de eStock
 *
 * Etapes :
 *   1. Verification des prerequis (PHP, extension, dossier writable)
 *   2. Configuration de la base de donnees + import du schema
 *   3. Creation du premier magasin
 *   4. Creation du compte Directeur
 *   5. Redirection vers la page de connexion
 *
 * Se lance automatiquement si la variable d'installation n'est pas definie.
 * Bloque l'acces une fois l'installation terminee.
 */

// ============================================================
//  GUARD : Si deja installe, rediriger vers login
// ============================================================
$envFile = __DIR__ . '/.env';
$installed = false;

// Verifier si le fichier .env contient les cles requises ET qu'un admin existe
if (is_file($envFile)) {
    $envContent = file_get_contents($envFile);
    if (preg_match('/^DB_PASS\s*=\s*.+$/m', $envContent)
        && preg_match('/^SECRET_URL_KEY\s*=\s*.{32,}$/m', $envContent)) {
        // Essayer de se connecter et verifier l'existence d'un admin
        try {
            require_once __DIR__ . '/config/connexion.php';
            if (function_exists('db_user_get_by_login') && function_exists('db_magasins_list_all')) {
                $admins = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role IN ('chef équipe','Admin') AND actif = 1")->fetchColumn();
                $magasins = $pdo->query("SELECT COUNT(*) FROM magasins WHERE actif = 1")->fetchColumn();
                if ($admins > 0 && $magasins > 0) {
                    $installed = true;
                }
            }
        } catch (\Throwable $e) {
            // Base non accessible, on continue l'installation
        }
    }
}

if ($installed) {
    header('Location: auth/login.php');
    exit;
}

// ============================================================
//  FONCTIONS UTILITAIRES LOCALES (hors connexion.php)
// ============================================================

function install_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function install_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['install_csrf'])) {
        $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['install_csrf'];
}

function install_csrf_field(): string {
    return '<input type="hidden" name="_csrf_token" value="' . install_csrf_token() . '">';
}

function install_csrf_validate(): bool {
    $token = $_POST['_csrf_token'] ?? '';
    return !empty($token) && hash_equals(install_csrf_token(), $token);
}

function install_step_class(int $current, int $step): string {
    if ($step < $current) return 'completed';
    if ($step === $current) return 'active';
    return '';
}

// ============================================================
//  GESTION DU FORMULAIRE MULTI-ETAPES
// ============================================================
$etape = (int)($_GET['etape'] ?? 1);
$erreur = '';
$success = '';
$errors_list = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!install_csrf_validate()) {
        $erreur = 'Token de securite invalide. Rechargez la page.';
    } else {
        $etape = (int)($_POST['etape'] ?? 1);

        // ---- ETAPE 1 : Verification des prerequis ----
        if ($etape === 1) {
            $redirect_next = 'install.php?etape=2';
            header('Location: ' . $redirect_next);
            exit;
        }

        // ---- ETAPE 2 : Configuration DB ----
        if ($etape === 2) {
            $db_host = trim($_POST['db_host'] ?? '127.0.0.1');
            $db_name = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['db_name'] ?? 'estock_db'));
            $db_user = trim($_POST['db_user'] ?? 'root');
            $db_pass = $_POST['db_pass'] ?? '';
            $db_charset = 'utf8mb4';

            if ($db_name === '') {
                $erreur = "Nom de base de données invalide.";
            }

            // Tester la connexion
            try {
                $dsn = "mysql:host=$db_host;charset=$db_charset";
                $testPdo = new PDO($dsn, $db_user, $db_pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);

                // Creer la base si elle n'existe pas
                $testPdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET $db_charset COLLATE {$db_charset}_unicode_ci");
                $testPdo->exec("USE `$db_name`");

                // Verifier si les tables existent déjà
                $stmtCount = $testPdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?");
                $stmtCount->execute([$db_name]);
                $tableCount = (int)$stmtCount->fetchColumn();

                if ($tableCount === 0) {
                    // Importer le schema SQL
                    $sqlFile = __DIR__ . '/database/estock_db.sql';
                    if (!is_file($sqlFile)) {
                        $erreur = "Fichier SQL introuvable : database/estock_db.sql";
                    } else {
                        $sql = file_get_contents($sqlFile);
                        // Decouper par lignes et executer chaque requete
                        $testPdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                        $statements = array_filter(
                            array_map('trim', explode(';', $sql)),
                            fn($s) => !empty($s) && $s !== '--' && !str_starts_with($s, '--')
                        );
                        foreach ($statements as $stmt) {
                            // Ignorer les lignes de commentaires pures
                            $cleaned = preg_replace('/^\s*--.*$/m', '', $stmt);
                            $cleaned = trim($cleaned);
                            if (!empty($cleaned) && $cleaned !== 'SET FOREIGN_KEY_CHECKS = 0') {
                                try {
                                    $testPdo->exec($cleaned);
                                } catch (\Throwable $e) {
                                    error_log('[INSTALL] SQL non-critique ignoré: ' . $e->getMessage());
                                }
                            }
                        }
                        $testPdo->exec("SET FOREIGN_KEY_CHECKS = 1");

                        // Appliquer la migration corrective
                        $migrationFile = __DIR__ . '/database/migration_fix_2026_08_22.sql';
                        if (is_file($migrationFile)) {
                            $migration = file_get_contents($migrationFile);
                            $migStatements = array_filter(
                                array_map('trim', explode(';', $migration)),
                                fn($s) => !empty($s) && !str_starts_with($s, '--')
                            );
                            foreach ($migStatements as $stmt) {
                                $cleaned = preg_replace('/^\s*--.*$/m', '', $stmt);
                                $cleaned = trim($cleaned);
                                if (!empty($cleaned)) {
                                    try {
                                        $testPdo->exec($cleaned);
                                    } catch (\Throwable $e) {
                                        error_log('[INSTALL] Migration non-critique ignorée: ' . $e->getMessage());
                                    }
                                }
                            }
                        }
                    }
                }

                // Ecrire le fichier .env
                $secretKey = bin2hex(random_bytes(32));
                $envContent = "APP_ENV=production\n"
                    . "DB_HOST=$db_host\n"
                    . "DB_NAME=$db_name\n"
                    . "DB_USER=$db_user\n"
                    . "DB_PASS=$db_pass\n"
                    . "SECRET_URL_KEY=$secretKey\n";

                if (file_put_contents($envFile, $envContent) === false) {
                    $erreur = "Impossible d'ecrire le fichier .env. Verifiez les permissions.";
                } else {
                    // Stocker les infos DB en session pour les etapes suivantes
                    $_SESSION['install_db'] = [
                        'host' => $db_host,
                        'name' => $db_name,
                        'user' => $db_user,
                        'pass' => $db_pass,
                    ];
                    header('Location: install.php?etape=3');
                    exit;
                }
            } catch (\Throwable $e) {
                $erreur = "Connexion echouee : " . $e->getMessage();
            }
        }

        // ---- ETAPE 3 : Premier magasin ----
        if ($etape === 3) {
            $mag_nom       = trim($_POST['mag_nom'] ?? '');
            $mag_adresse   = trim($_POST['mag_adresse'] ?? '');
            $mag_cp        = trim($_POST['mag_code_postal'] ?? '');
            $mag_nif       = trim($_POST['mag_nif'] ?? '');
            $mag_rccm      = trim($_POST['mag_rccm'] ?? '');

            if ($mag_nom === '') {
                $erreur = 'Le nom du magasin est obligatoire.';
            } else {
                $_SESSION['install_magasin'] = [
                    'nom'      => $mag_nom,
                    'adresse'  => $mag_adresse,
                    'cp'       => $mag_cp,
                    'nif'      => $mag_nif,
                    'rccm'     => $mag_rccm,
                ];
                header('Location: install.php?etape=4');
                exit;
            }
        }

        // ---- ETAPE 4 : Compte Directeur ----
        if ($etape === 4) {
            $nom      = trim($_POST['admin_nom'] ?? '');
            $login    = trim($_POST['admin_login'] ?? '');
            $mdp      = $_POST['admin_mdp'] ?? '';
            $mdp2     = $_POST['admin_mdp2'] ?? '';

            if ($nom === '' || $login === '' || $mdp === '') {
                $erreur = 'Tous les champs sont obligatoires.';
            } elseif (strlen($mdp) < 8) {
                $erreur = 'Le mot de passe doit contenir au moins 8 caracteres.';
            } elseif ($mdp !== $mdp2) {
                $erreur = 'Les mots de passe ne correspondent pas.';
            } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $login)) {
                $erreur = 'Le login ne doit contenir que des lettres, chiffres, points, tirets ou underscores.';
            } else {
                // Tout est OK, creer la base de donnees
                try {
                    $dbInfo = $_SESSION['install_db'] ?? null;
                    if (!$dbInfo) {
                        $erreur = 'Session expiree. Recommencez depuis l\'etape 2.';
                    } else {
                        $dsn = "mysql:host={$dbInfo['host']};charset=utf8mb4";
                        $pdo = new PDO($dsn, $dbInfo['user'], $dbInfo['pass'], [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        ]);
                        $pdo->exec("USE `{$dbInfo['name']}`");

                        // Creer le magasin
                        $stmtMag = $pdo->prepare("INSERT INTO magasins (nom, adresse, code_postal, nif, rccm) VALUES (?, ?, ?, ?, ?)");
                        $stmtMag->execute([
                            $_SESSION['install_magasin']['nom'],
                            $_SESSION['install_magasin']['adresse'] ?: null,
                            $_SESSION['install_magasin']['cp'] ?: null,
                            $_SESSION['install_magasin']['nif'] ?: null,
                            $_SESSION['install_magasin']['rccm'] ?: null,
                        ]);
                        $magasinId = (int)$pdo->lastInsertId();

                        // Creer l'admin
                        $mdpHash = password_hash($mdp, PASSWORD_DEFAULT);
                        $stmtUser = $pdo->prepare("INSERT INTO utilisateurs (nom, login, mot_de_passe, role, actif, magasin_id) VALUES (?, ?, ?, 'chef équipe', 1, ?)");
                        $stmtUser->execute([$nom, $login, $mdpHash, $magasinId]);

                        // Inserer les permissions manquantes (migration)
                        try {
                            $pdo->exec("INSERT IGNORE INTO permissions (cle_permission, description, categorie) VALUES
                                ('clients_consulter', 'Consulter la fiche client', 'Clients'),
                                ('clients_gerer', 'Creer / modifier / supprimer les clients', 'Clients'),
                                ('conformite_archives', 'Gerer les archives de conformite', 'Conformite'),
                                ('conformite_export_syscohada', 'Exporter en format SYSCOHADA', 'Conformite'),
                                ('articles_modifier', 'Modifier les articles via l''API', 'Articles')");

                            $pdo->exec("INSERT IGNORE INTO role_permissions (role_nom, permission_id)
                                SELECT 'chef équipe', p.id FROM permissions p
                                WHERE p.cle_permission IN ('clients_consulter','clients_gerer','conformite_archives','conformite_export_syscohada','articles_modifier')");

                            $pdo->exec("INSERT IGNORE INTO role_permissions (role_nom, permission_id)
                                SELECT 'Admin', p.id FROM permissions p
                                WHERE p.cle_permission IN ('clients_consulter','clients_gerer','articles_modifier')");

                            $pdo->exec("INSERT IGNORE INTO role_permissions (role_nom, permission_id)
                                SELECT 'Magasinier', p.id FROM permissions p
                                WHERE p.cle_permission = 'articles_modifier'");
                        } catch (\Throwable $e) {
                            // Non-critique
                        }

                        // Nettoyer la session
                        unset($_SESSION['install_db'], $_SESSION['install_magasin'], $_SESSION['install_csrf']);

                        header('Location: install.php?etape=5');
                        exit;
                    }
                } catch (\Throwable $e) {
                    $erreur = "Erreur lors de la creation : " . $e->getMessage();
                }
            }
        }
    }
}

// ============================================================
//  DONNEES D'AFFICHAGE
// ============================================================
$etape_titles = [
    1 => 'Prerequis',
    2 => 'Base de donnees',
    3 => 'Magasin',
    4 => 'Compte chef équipe',
    5 => 'Termine',
];

$prerequis = [
    ['PHP 8.0+',              version_compare(PHP_VERSION, '8.0.0', '>=')],
    ['Extension PDO MySQL',   extension_loaded('pdo_mysql')],
    ['Extension mbstring',    extension_loaded('mbstring')],
    ['Extension JSON',        extension_loaded('json')],
    ['Extension OpenSSL',     extension_loaded('openssl')],
    ['Extension session',     extension_loaded('session')],
    ['Dossier logs/ writable', is_dir(__DIR__ . '/logs') && is_writable(__DIR__ . '/logs')],
    ['Dossier var/cache/ writable', is_dir(__DIR__ . '/var/cache') && is_writable(__DIR__ . '/var/cache')],
    ['Fichier .env.example present', is_file(__DIR__ . '/.env.example')],
];

$tous_ok = !in_array(false, array_column($prerequis, 1), true);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation · eStock</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --brand: #4f46e5; --brand-2: #7c3aed; --brand-soft: rgba(79,70,229,.08);
            --ink: #1e1b4b; --ink-2: #374151; --ink-3: #6b7280;
            --bg: #f8fafc; --card-bg: #fff; --border: #e5e7eb;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--bg); }

        .install-split { min-height: 100vh; display: grid; grid-template-columns: 1fr 1.1fr; }
        @media (max-width: 991.98px) { .install-split { grid-template-columns: 1fr; } .install-aside { display: none; } }

        .install-aside {
            position: relative; overflow: hidden; color: #fff;
            background: radial-gradient(120% 120% at 100% 100%, #312e81 0%, #1e1b4b 45%, #0b1020 100%);
            padding: 56px; display: flex; flex-direction: column; justify-content: space-between;
        }
        .install-aside::before {
            content: ''; position: absolute; width: 320px; height: 320px; border-radius: 50%;
            background: rgba(129,140,248,.4); filter: blur(60px); top: -80px; left: -60px; pointer-events: none;
        }
        .install-aside .brand-row { display: flex; align-items: center; gap: 12px; position: relative; z-index: 1; }
        .install-aside .logo-img {
            width: 46px; height: 46px; border-radius: 13px;
            object-fit: cover; box-shadow: 0 10px 26px rgba(99,102,241,.5);
        }
        .install-aside .brand-name { font-size: 1.5rem; font-weight: 800; letter-spacing: -.02em; }
        .install-aside .pitch { position: relative; z-index: 1; }
        .install-aside .pitch h2 { color: #fff; font-size: 2.1rem; font-weight: 800; line-height: 1.15; letter-spacing: -.02em; }
        .install-aside .pitch p { color: #c7d2fe; font-size: 1rem; max-width: 400px; margin-top: 14px; }
        .install-aside .feat { display: flex; gap: 10px; align-items: center; color: #e0e7ff; margin-top: 12px; }
        .install-aside .feat i { color: #a5b4fc; }
        .install-aside .footnote { position: relative; z-index: 1; color: #94a3b8; font-size: .82rem; }

        .install-form-side { display: flex; align-items: center; justify-content: center; padding: 40px 28px; background: var(--bg); }
        .install-card { width: 100%; max-width: 560px; padding: 0; }
        .install-title { font-size: 1.5rem; font-weight: 800; color: var(--ink); letter-spacing: -.02em; margin-bottom: 4px; }
        .install-sub { color: var(--ink-3); margin-bottom: 24px; font-size: .92rem; }

        /* Stepper */
        .stepper { display: flex; gap: 0; margin-bottom: 28px; }
        .step { flex: 1; text-align: center; position: relative; padding-top: 28px; }
        .step::before {
            content: ''; position: absolute; top: 10px; left: 0; right: 0; height: 3px;
            background: var(--border);
        }
        .step:first-child::before { left: 50%; }
        .step:last-child::before { right: 50%; }
        .step .dot {
            position: absolute; top: 2px; left: 50%; transform: translateX(-50%);
            width: 20px; height: 20px; border-radius: 50%;
            background: var(--border); color: #fff; font-size: .7rem; font-weight: 700;
            display: flex; align-items: center; justify-content: center; z-index: 1;
        }
        .step.active .dot { background: var(--brand); box-shadow: 0 0 0 4px var(--brand-soft); }
        .step.completed .dot { background: #10b981; }
        .step .label { font-size: .72rem; color: var(--ink-3); font-weight: 600; }
        .step.active .label { color: var(--brand); }
        .step.completed .label { color: #10b981; }

        .form-label { font-weight: 600; font-size: .88rem; color: var(--ink-2); }
        .form-control:focus { border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-soft); }
        .form-text { font-size: .8rem; }

        .btn-brand { background: var(--brand); border: none; color: #fff; font-weight: 600; }
        .btn-brand:hover { background: var(--brand-2); color: #fff; }

        .prereq-item { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--border); }
        .prereq-item:last-child { border-bottom: none; }
        .prereq-icon { font-size: 1.1rem; }
        .prereq-ok { color: #10b981; }
        .prereq-ko { color: #ef4444; }

        .alert-success-custom { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; border-radius: 12px; padding: 20px; }
    </style>
</head>
<body>
<div class="install-split">

    <!-- Panneau gauche vitrine -->
    <aside class="install-aside">
        <div class="brand-row">
            <img src="assets/images/logo-eStock-3.png" alt="Logo eStock" class="logo-img">
            <span class="brand-name">eStock</span>
        </div>
        <div class="pitch">
            <h2>Configuration initiale</h2>
            <p>Cet assistant vous guide pour configurer eStock : base de donnees, magasin et compte administrateur.</p>
            <div class="feat"><i class="bi bi-database-check"></i> Installation automatique de la base</div>
            <div class="feat"><i class="bi bi-shop"></i> Configuration de votre premier magasin</div>
            <div class="feat"><i class="bi bi-person-check"></i> Creation du compte chef équipe</div>
        </div>
        <div class="footnote">&copy; <?= date('Y') ?> eStock &mdash; Assistant d'installation</div>
    </aside>

    <!-- Panneau droit formulaire -->
    <div class="install-form-side">
        <div class="install-card">

            <!-- Stepper -->
            <div class="stepper">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <div class="step <?= install_step_class($etape, $i) ?>">
                    <span class="dot"><?= $i < 5 ? $i : '<i class="bi bi-check-lg"></i>' ?></span>
                    <span class="label"><?= $etape_titles[$i] ?></span>
                </div>
                <?php endfor; ?>
            </div>

            <?php if ($erreur): ?>
                <div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= install_h($erreur) ?></div>
            <?php endif; ?>

            <!-- ============================================================ -->
            <!-- ETAPE 1 : Prerequis -->
            <!-- ============================================================ -->
            <?php if ($etape === 1): ?>
            <h2 class="install-title">Verification des prerequis</h2>
            <p class="install-sub">Votre serveur doit repondre aux conditions suivantes.</p>

            <div class="card border-0 shadow-sm mb-4" style="border-radius:12px;">
                <div class="card-body p-3">
                    <?php foreach ($prerequis as [$label, $ok]): ?>
                    <div class="prereq-item">
                        <span class="prereq-icon <?= $ok ? 'prereq-ok' : 'prereq-ko' ?>">
                            <i class="bi <?= $ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill' ?>"></i>
                        </span>
                        <span><?= install_h($label) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <form method="post">
                <?= install_csrf_field() ?>
                <input type="hidden" name="etape" value="1">
                <?php if ($tous_ok): ?>
                <button type="submit" class="btn btn-brand btn-lg w-100" style="justify-content:center;">
                    Continuer <i class="bi bi-arrow-right"></i>
                </button>
                <?php else: ?>
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle"></i> Corrigez les problemes ci-dessus avant de continuer.
                </div>
                <?php endif; ?>
            </form>

            <!-- ============================================================ -->
            <!-- ETAPE 2 : Base de donnees -->
            <!-- ============================================================ -->
            <?php elseif ($etape === 2): ?>
            <h2 class="install-title">Configuration de la base de donnees</h2>
            <p class="install-sub">Renseignez les identifiants de connexion MySQL.</p>

            <form method="post">
                <?= install_csrf_field() ?>
                <input type="hidden" name="etape" value="2">

                <div class="mb-3">
                    <label class="form-label">Serveur MySQL</label>
                    <input type="text" name="db_host" class="form-control" value="127.0.0.1" required>
                    <div class="form-text">Adresse IP ou hostname du serveur MySQL.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Nom de la base</label>
                    <input type="text" name="db_name" class="form-control" value="estock_db" required>
                    <div class="form-text">La base sera creee si elle n'existe pas.</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Utilisateur</label>
                        <input type="text" name="db_user" class="form-control" value="root" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Mot de passe</label>
                        <input type="password" name="db_pass" class="form-control" value="" placeholder="Laisser vide si aucun">
                    </div>
                </div>

                <button type="submit" class="btn btn-brand btn-lg w-100" style="justify-content:center;">
                    Tester et installer <i class="bi bi-arrow-right"></i>
                </button>
            </form>

            <!-- ============================================================ -->
            <!-- ETAPE 3 : Magasin -->
            <!-- ============================================================ -->
            <?php elseif ($etape === 3): ?>
            <h2 class="install-title">Premier magasin</h2>
            <p class="install-sub">Creez le magasin principal de votre application.</p>

            <form method="post">
                <?= install_csrf_field() ?>
                <input type="hidden" name="etape" value="3">

                <div class="mb-3">
                    <label class="form-label">Nom du magasin *</label>
                    <input type="text" name="mag_nom" class="form-control" required
                           placeholder="Ex: eStock Market - Lome" maxlength="150">
                </div>
                <div class="mb-3">
                    <label class="form-label">Adresse</label>
                    <input type="text" name="mag_adresse" class="form-control" placeholder="Ex: Blvd du 13 Janvier" maxlength="255">
                </div>
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Code postal</label>
                        <input type="text" name="mag_code_postal" class="form-control" maxlength="10">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">NIF</label>
                        <input type="text" name="mag_nif" class="form-control" maxlength="30">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">RCCM</label>
                        <input type="text" name="mag_rccm" class="form-control" maxlength="30">
                    </div>
                </div>

                <button type="submit" class="btn btn-brand btn-lg w-100" style="justify-content:center;">
                    Continuer <i class="bi bi-arrow-right"></i>
                </button>
            </form>

            <!-- ============================================================ -->
            <!-- ETAPE 4 : Compte Directeur -->
            <!-- ============================================================ -->
            <?php elseif ($etape === 4): ?>
            <h2 class="install-title">Compte chef équipe</h2>
            <p class="install-sub">Creez le compte administrateur principal.</p>

            <form method="post">
                <?= install_csrf_field() ?>
                <input type="hidden" name="etape" value="4">

                <div class="mb-3">
                    <label class="form-label">Nom complet *</label>
                    <input type="text" name="admin_nom" class="form-control" required
                           placeholder="Ex: Kofi Agbekple" maxlength="150">
                </div>
                <div class="mb-3">
                    <label class="form-label">Identifiant de connexion *</label>
                    <input type="text" name="admin_login" class="form-control" required
                           placeholder="Ex: admin" maxlength="60"
                           pattern="[a-zA-Z0-9._\-]+" title="Lettres, chiffres, points, tirets ou underscores uniquement">
                    <div class="form-text">Sera utilise pour se connecter.</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Mot de passe *</label>
                        <input type="password" name="admin_mdp" class="form-control" required minlength="8"
                               placeholder="Minimum 8 caracteres" id="mdp1">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Confirmer *</label>
                        <input type="password" name="admin_mdp2" class="form-control" required minlength="8"
                               placeholder="Retapez le mot de passe" id="mdp2">
                    </div>
                </div>

                <button type="submit" class="btn btn-brand btn-lg w-100" style="justify-content:center;">
                    Creer le compte <i class="bi bi-arrow-right"></i>
                </button>
            </form>

            <!-- ============================================================ -->
            <!-- ETAPE 5 : Termine -->
            <!-- ============================================================ -->
            <?php elseif ($etape === 5): ?>
            <div class="text-center mb-4">
                <div style="font-size:3.5rem;color:#10b981;"><i class="bi bi-check-circle-fill"></i></div>
            </div>
            <h2 class="install-title text-center">Installation terminee !</h2>
            <p class="install-sub text-center">Votre application eStock est prete. Vous pouvez maintenant vous connecter.</p>

            <div class="alert-success-custom mb-4">
                <strong><i class="bi bi-shield-check"></i> Securite</strong><br>
                Le fichier <code>.env</code> a ete cree avec une cle secrete generee automatiquement.
                Protegez ce fichier et ne le partagez jamais.
            </div>

            <a href="auth/login.php" class="btn btn-brand btn-lg w-100" style="justify-content:center;text-decoration:none;">
                <i class="bi bi-box-arrow-in-right"></i> Se connecter
            </a>

            <div class="text-center mt-3">
                <small class="text-muted">
                    Conseil : pour des raisons de securite, supprimez ou renommez le fichier <code>install.php</code>.
                </small>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<script nonce="<?= install_h(install_csrf_token()) ?>">
document.addEventListener('DOMContentLoaded', function() {
    const mdp1 = document.getElementById('mdp1');
    const mdp2 = document.getElementById('mdp2');
    if (mdp1 && mdp2) {
        mdp2.addEventListener('input', function() {
            if (mdp2.value && mdp1.value !== mdp2.value) {
                mdp2.setCustomValidity('Les mots de passe ne correspondent pas.');
            } else {
                mdp2.setCustomValidity('');
            }
        });
    }
});
</script>
</body>
</html>
