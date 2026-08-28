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
$google_oauth_actif = param_bool('google_oauth_actif', false);

if (est_connecte()) redirect('../tableau_bord.php');

$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate()) {
        $erreur = 'Session invalide. Rechargez la page.';
    } elseif (login_rate_limited()) {
        $erreur = 'Trop de tentatives. Réessayez dans 15 minutes.';
        suivre_activite('ECHEC_CONNEXION', 'Rate limited depuis ' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
    } else {
        $login = input_string($_POST['login'] ?? '');
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
                if (!empty($u['magasin_id'])) {
                    $_SESSION['magasin_actif'] = (int)$u['magasin_id'];
                } else {
                    unset($_SESSION['magasin_actif']);
                }
                suivre_activite('CONNEXION', 'Connexion réussie: ' . $login);
                if (user_role() === ROLE_DIRECTEUR) {
                    redirect('choisir_magasin.php');
                }
                redirect('../tableau_bord.php');
            } else {
                usleep(random_int(100000, 300000)); // Anti-timing même si l'utilisateur existe
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
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="../assets/images/logo-eStock.ico">
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
        .login-aside .logo-img {
            width: 46px; height: 46px; border-radius: 13px;
            object-fit: cover; box-shadow: 0 10px 26px rgba(99,102,241,.5);
        }
        .login-aside .brand-name { font-size: 1.5rem; font-weight: 800; letter-spacing: -.02em; }
        .login-aside .pitch { position: relative; z-index: 1; }
        .login-aside .pitch h2 { color: #fff; font-size: 2.3rem; font-weight: 800; line-height: 1.15; letter-spacing: -.02em; }
        .login-aside .pitch p { color: #c7d2fe; font-size: 1.05rem; max-width: 420px; margin-top: 14px; }
        .login-aside .feat { display: flex; gap: 10px; align-items: center; color: #e0e7ff; margin-top: 14px; }
        .login-aside .feat i { color: #a5b4fc; }
        .login-aside .footnote { position: relative; z-index: 1; color: #94a3b8; font-size: .82rem; }

        .login-form-side { display: flex; align-items: center; justify-content: center; padding: 40px 28px; background: var(--bg); }
        .login-card { width: 100%; max-width: 500px; padding: 50px 38px;}
        .login-title { font-size: 1.7rem; font-weight: 800; color: var(--ink); letter-spacing: -.02em; }
        .login-sub { color: var(--ink-3); margin-top: 4px; margin-bottom: 28px; }
    </style>
</head>
<body>
<div class="login-split">

    <!-- Panneau gauche vitrine -->
    <aside class="login-aside">
        <div class="brand-row">
            <img src="../assets/images/logo-eStock-3.png" alt="Logo <?= h($app_nom) ?>" class="logo-img">
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
        <div class="card login-card">
            <h1 class="login-title">Bon retour</h1>
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

            <?php if ($google_oauth_actif): ?>
            <div class="position-relative my-3">
                <hr class="text-muted">
                <span class="position-absolute top-50 start-50 translate-middle bg-white px-3 text-muted small">ou</span>
            </div>
            <a href="google_login.php" class="btn btn-outline-dark btn-lg w-100 d-flex align-items-center justify-content-center gap-2">
                <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                Se connecter avec Google
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1090;" id="flashToastContainer"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script nonce="<?= h(csp_nonce()) ?>">
(function(){
    var msgs = <?= json_encode($flash_captured ?? [], JSON_UNESCAPED_UNICODE) ?>;
    if (!msgs || !msgs.length) return;
    var icons = {success:'check-circle-fill', danger:'exclamation-triangle-fill', warning:'exclamation-triangle-fill', info:'info-circle-fill'};
    var container = document.getElementById('flashToastContainer');
    msgs.forEach(function(f){
        var toastEl = document.createElement('div');
        toastEl.className = 'toast align-items-center text-bg-' + (f.type === 'danger' ? 'danger' : f.type) + ' border-0';
        toastEl.setAttribute('role','alert');
        toastEl.innerHTML =
            '<div class="d-flex">' +
              '<div class="toast-body"><i class="bi bi-' + (icons[f.type]||'bell') + ' me-2"></i>' + f.message + '</div>' +
              '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
            '</div>';
        container.appendChild(toastEl);
        var toast = new bootstrap.Toast(toastEl, {delay: 5000});
        toast.show();
        toastEl.addEventListener('hidden.bs.toast', function(){ toastEl.remove(); });
    });
})();
</script>
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
