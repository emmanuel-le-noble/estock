<?php
/**
 * login.php - Authentification (design split-screen moderne).
 */
// Guard: compatible avec le routeur front controller (index.php)
if (!function_exists('est_connecte')) { require_once __DIR__ . '/../config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/../includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/../includes/helpers.php'; }
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/connexion.php';
require_once __DIR__ . '/../includes/db_functions.php';

$app_nom = param_app_name();
$nom_boutique = param_shop_name();
$slogan_boutique = param('slogan_boutique', 'Gérez votre stock et votre caisse. Simplement.');

if (est_connecte()) redirect('../tableau_bord.php');

$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $erreur = 'Session invalide. Rechargez la page.';
    } elseif (login_rate_limited()) {
        $erreur = 'Trop de tentatives. Réessayez dans 15 minutes.';
        suivre_activite('ECHEC_CONNEXION', 'Rate limited depuis ' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
    } else {
        $login = trim($_POST['login'] ?? '');
        $mdp   = $_POST['mot_de_passe'] ?? '';
        if ($login === '' || $mdp === '') {
            $erreur = 'Veuillez renseigner tous les champs.';
        } else {
            $u = db_user_get_by_login($pdo, $login);
            if (!$u) {
                usleep(random_int(100000, 300000)); // Protection anti-timing
            }
            if ($u && password_verify($mdp, $u['mot_de_passe'])) {
                db_login_attempt_clear($pdo, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $login);
                session_regenerate_id(true);
                unset($u['mot_de_passe']);
                $_SESSION['user'] = $u;
                suivre_activite('CONNEXION', 'Connexion réussie: ' . $login);
                redirect('../tableau_bord.php');
            } else {
                db_login_attempt_insert($pdo, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $login);
                suivre_activite('ECHEC_CONNEXION', 'Tentative échouée: ' . $login);
                $erreur = 'Identifiant ou mot de passe incorrect.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion · <?= h($app_nom) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
    <style>
        .login-split { min-height: 100vh; display: grid; grid-template-columns: 1.1fr 1fr; }
        @media (max-width: 991.98px) { .login-split { grid-template-columns: 1fr; } .login-aside { display: none; } }

        .login-aside {
            position: relative; overflow: hidden; color: #fff;
            background: radial-gradient(120% 120% at 0% 0%, #312e81 0%, #1e1b4b 45%, #0b1020 100%);
            padding: 56px; display: flex; flex-direction: column; justify-content: space-between;
        }
        .login-aside::before, .login-aside::after {
            content: ''; position: absolute; border-radius: 50%; filter: blur(60px); pointer-events: none;
        }
        .login-aside::before { width: 320px; height: 320px; background: rgba(129,140,248,.5); top: -80px; right: -60px; }
        .login-aside::after  { width: 280px; height: 280px; background: rgba(244,114,182,.35); bottom: -80px; left: -40px; }
        .login-aside .brand-row { display: flex; align-items: center; gap: 12px; position: relative; z-index: 1; }
        .login-aside .logo-mark {
            width: 46px; height: 46px; border-radius: 13px;
            background: linear-gradient(135deg, #818cf8, #4f46e5);
            display: grid; place-items: center; font-size: 1.5rem;
            box-shadow: 0 10px 26px rgba(99,102,241,.5);
        }
        .login-aside .brand-name { font-size: 1.5rem; font-weight: 800; letter-spacing: -.02em; }
        .login-aside .pitch { position: relative; z-index: 1; }
        .login-aside .pitch h2 { color: #fff; font-size: 2.3rem; font-weight: 800; line-height: 1.15; letter-spacing: -.02em; }
        .login-aside .pitch p { color: #c7d2fe; font-size: 1.05rem; max-width: 420px; margin-top: 14px; }
        .login-aside .feat { display: flex; gap: 10px; align-items: center; color: #e0e7ff; margin-top: 14px; }
        .login-aside .feat i { color: #a5b4fc; }
        .login-aside .footnote { position: relative; z-index: 1; color: #94a3b8; font-size: .82rem; }

        .login-form-side { display: flex; align-items: center; justify-content: center; padding: 40px 28px; background: var(--bg); }
        .login-card { width: 100%; max-width: 400px; }
        .login-title { font-size: 1.7rem; font-weight: 800; color: var(--ink); letter-spacing: -.02em; }
        .login-sub { color: var(--ink-3); margin-top: 4px; margin-bottom: 28px; }
    </style>
</head>
<body>
<div class="login-split">

    <!-- Panneau gauche vitrine -->
    <aside class="login-aside">
        <div class="brand-row">
            <span class="logo-mark"><i class="bi bi-boxes"></i></span>
            <span class="brand-name"><?= h($app_nom) ?></span>
        </div>
        <div class="pitch">
            <h2><?= h($nom_boutique) ?></h2>
            <p><?= h($slogan_boutique) ?></p>
            <div class="feat"><i class="bi bi-check-circle-fill"></i> Lecteur de code-barres intégré</div>
            <div class="feat"><i class="bi bi-check-circle-fill"></i> Stocks &amp; alertes en temps réel</div>
            <div class="feat"><i class="bi bi-check-circle-fill"></i> Factures &amp; tickets imprimables</div>
        </div>
        <div class="footnote">© <?= date('Y') ?> <?= h($nom_boutique) ?> · <?= h($app_nom) ?></div>
    </aside>

    <!-- Panneau droit formulaire -->
    <div class="login-form-side">
        <div class="login-card">
            <h1 class="login-title">Bon retour 👋</h1>
            <p class="login-sub">Connectez-vous pour accéder à votre espace.</p>

            <?php if ($erreur): ?>
                <div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= h($erreur) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label">Identifiant</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" name="login" class="form-control"
                               value="<?= h($_POST['login'] ?? '') ?>" required autofocus
                               placeholder="votre identifiant">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label">Mot de passe</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="mot_de_passe" id="mdp" class="form-control" required placeholder="••••••••">
                        <button class="btn btn-outline-secondary" type="button" id="btnToggleMdp" style="border-radius:0 var(--radius-sm) var(--radius-sm) 0;">
                            <i class="bi bi-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>
                <button class="btn btn-primary btn-lg w-100" type="submit" style="justify-content:center;">
                    Se connecter <i class="bi bi-arrow-right"></i>
                </button>
            </form>
        </div>
    </div>
</div>

<script nonce="<?= h(csp_nonce()) ?>">
document.addEventListener('DOMContentLoaded', function() {
    const btnToggle = document.getElementById('btnToggleMdp');
    const mdp = document.getElementById('mdp');
    const icn = document.getElementById('eyeIcon');

    if (btnToggle && mdp && icn) {
        btnToggle.addEventListener('click', function() {
            if (mdp.type === 'password') {
                mdp.type = 'text';
                icn.className = 'bi bi-eye-slash';
            } else {
                mdp.type = 'password';
                icn.className = 'bi bi-eye';
            }
        });
    }
});
</script>
</body>
</html>