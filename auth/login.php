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
$logo_boutique = param_shop_logo();
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
    <link href="../assets/css/style.css?v=18" rel="stylesheet">
    <link rel="icon" href="../<?= h($logo_boutique ?: 'assets/images/estock-mark.svg') ?>">
    <style>
        .login-split { --login-ink: #f5f8ff; --login-muted: #b4c5dc; min-height: 100vh; min-height: 100dvh; display: grid; grid-template-columns: minmax(0, 1.08fr) minmax(440px, .92fr); overflow: hidden; color: var(--login-ink); background: radial-gradient(ellipse at 85% 8%, rgba(40,105,198,.25), transparent 34%), linear-gradient(145deg, #101d35, #0b172c 72%); }
        .login-aside { position: relative; isolation: isolate; overflow: hidden; min-height: 100vh; padding: clamp(30px, 5vw, 72px); display: flex; flex-direction: column; justify-content: space-between; border-right: 1px solid rgba(255,255,255,.08); background: radial-gradient(ellipse at 12% 12%, rgba(39,107,206,.42), transparent 48%), linear-gradient(155deg, #18365f, #0d1f3b); }
        .login-aside::before { content: ''; position: absolute; z-index: -1; inset: 0; opacity: .18; background-image: linear-gradient(rgba(184,211,248,.16) 1px, transparent 1px), linear-gradient(90deg, rgba(184,211,248,.16) 1px, transparent 1px); background-size: 52px 52px; mask-image: linear-gradient(135deg, #000, transparent 72%); }
        .login-aside::after { content: ''; position: absolute; z-index: -1; width: 500px; height: 500px; right: -220px; bottom: -300px; border: 1px solid rgba(151,194,249,.18); border-radius: 50%; box-shadow: 0 0 0 42px rgba(151,194,249,.035), 0 0 0 88px rgba(151,194,249,.025); animation: loginOrbit 18s ease-in-out infinite alternate; }
        .login-aside .brand-row { display: flex; align-items: center; gap: 13px; position: relative; z-index: 1; animation: loginRise .7s both; }
        .login-aside .logo-img { width: 48px; height: 48px; border-radius: 14px; object-fit: contain; box-shadow: 0 10px 26px rgba(3,17,38,.3); }
        .login-aside .brand-name { color: #fff; font-size: 1.42rem; font-weight: 750; letter-spacing: -.035em; }
        .login-aside .pitch { position: relative; z-index: 1; max-width: 610px; margin: 52px 0 26px; animation: loginRise .8s .08s both; }
        .login-kicker { display: inline-flex; align-items: center; gap: 9px; margin-bottom: 18px; color: #c5dbfa; font-size: .69rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; }
        .login-kicker i { color: #86baff; font-size: 1rem; animation: loginSpark 2.5s ease-in-out infinite; }
        .login-aside .pitch h1 { max-width: 590px; margin: 0; color: #fff; font-size: clamp(2.25rem, 4.2vw, 4rem); font-weight: 720; line-height: 1.08; letter-spacing: -.055em; text-wrap: balance; }
        .login-aside .pitch p { max-width: 460px; margin: 18px 0 26px; color: rgba(231,240,253,.76); font-size: clamp(.95rem, 1.2vw, 1.08rem); line-height: 1.75; }
        .login-features { display: grid; gap: 11px; }
        .login-aside .feat { display: flex; gap: 11px; align-items: center; color: #e0ebf9; font-size: .86rem; }
        .login-aside .feat i { width: 26px; height: 26px; display: grid; place-items: center; border: 1px solid rgba(110,231,183,.2); border-radius: 8px; background: rgba(16,185,129,.11); color: #6ee7b7; font-size: .8rem; }
        .login-art { position: absolute; z-index: -1; right: clamp(24px, 7vw, 108px); bottom: 17%; width: min(34vw, 340px); height: 190px; transform: perspective(700px) rotateY(-12deg) rotateX(4deg); animation: loginRise .9s .24s both; }
        .login-art-panel { position: absolute; inset: 12px 0 0 18px; padding: 18px 20px; border: 1px solid rgba(205,226,255,.25); border-radius: 18px; background: linear-gradient(145deg, rgba(255,255,255,.14), rgba(10,34,69,.52)); box-shadow: inset 0 1px rgba(255,255,255,.2), 0 22px 54px rgba(1,12,29,.28); backdrop-filter: blur(16px); }
        .login-art-label { display: flex; align-items: center; justify-content: space-between; color: rgba(232,242,255,.68); font-size: .59rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
        .login-art-label i { color: #9ccaff; font-size: 1rem; }
        .login-art-bars { position: absolute; inset: 53px 21px 27px; display: flex; align-items: flex-end; gap: 10px; }
        .login-art-bars i { flex: 1; height: var(--h); border-radius: 6px 6px 2px 2px; background: linear-gradient(180deg, rgba(181,218,255,.9), rgba(74,137,221,.24)); transform-origin: bottom; animation: loginBar .9s cubic-bezier(.2,.8,.2,1) var(--d) both; }
        .login-art-bars i:nth-child(1) { --h: 36%; --d: .1s; } .login-art-bars i:nth-child(2) { --h: 53%; --d: .16s; } .login-art-bars i:nth-child(3) { --h: 44%; --d: .22s; } .login-art-bars i:nth-child(4) { --h: 72%; --d: .28s; } .login-art-bars i:nth-child(5) { --h: 60%; --d: .34s; } .login-art-bars i:nth-child(6) { --h: 88%; --d: .4s; }
        .login-art-float { position: absolute; display: grid; place-items: center; width: 42px; height: 42px; border: 1px solid rgba(255,255,255,.32); border-radius: 13px; background: rgba(35,91,158,.58); color: #d9eaff; box-shadow: 0 10px 24px rgba(4,20,44,.24); backdrop-filter: blur(10px); }
        .login-art-float.box { top: 0; left: -1px; animation: loginFloat 4.4s ease-in-out infinite; } .login-art-float.trend { right: -14px; bottom: -7px; color: #74e0b5; animation: loginFloat 5s ease-in-out -1s infinite reverse; }
        .login-aside .footnote { position: relative; z-index: 1; color: rgba(208,224,244,.58); font-size: .76rem; letter-spacing: .02em; animation: loginRise .7s .2s both; }
        .login-form-side { position: relative; display: flex; align-items: center; justify-content: center; padding: 42px clamp(22px, 5vw, 76px); background: radial-gradient(ellipse at 100% 100%, rgba(39,107,206,.14), transparent 48%); }
        .login-card { width: 100%; max-width: 460px; padding: clamp(28px, 4vw, 46px); border: 1px solid rgba(203,221,247,.16); border-radius: 24px; background: linear-gradient(145deg, rgba(255,255,255,.105), rgba(255,255,255,.045)); box-shadow: inset 0 1px rgba(255,255,255,.12), 0 28px 80px rgba(1,10,25,.28); backdrop-filter: blur(24px) saturate(135%); animation: loginRise .75s .12s both; }
        .login-card::before { content: ''; display: block; width: 44px; height: 3px; margin-bottom: 27px; border-radius: 4px; background: linear-gradient(90deg, #66aaff, #75e0b5); box-shadow: 0 0 16px rgba(102,170,255,.45); }
        .login-card .login-kicker { margin-bottom: 9px; color: #9ebde4; font-size: .62rem; }
        .login-title { font-size: clamp(1.65rem, 2.5vw, 2rem); font-weight: 720; color: #fff; letter-spacing: -.04em; }
        .login-sub { color: #b4c5dc; margin-top: 7px; margin-bottom: 30px; font-size: .88rem; }
        .login-card .form-label { color: #e5edf8; font-size: .78rem; font-weight: 600; }
        .login-card .input-group-text, .login-card .form-control, .login-card .btn-outline-secondary { min-height: 48px; border-color: rgba(206,224,249,.19); background-color: rgba(5,18,39,.28); color: #f5f8ff; }
        .login-card .input-group-text { color: #9dbbe0; }
        .login-card .form-control::placeholder { color: #879bb7; }
        .login-card .form-control:focus { border-color: rgba(114,174,245,.72); background-color: rgba(5,18,39,.4); box-shadow: 0 0 0 .22rem rgba(48,117,203,.18); }
        .login-card .btn-outline-secondary:hover { border-color: rgba(206,224,249,.36); background: rgba(255,255,255,.1); color: #fff; }
        .login-card .btn-primary { min-height: 50px; border: 0; border-radius: 12px; background: linear-gradient(105deg, #2877e4, #1556b5); box-shadow: 0 9px 22px rgba(17,81,171,.3); font-size: .9rem; font-weight: 650; transition: transform .2s, box-shadow .2s, filter .2s; }
        .login-card .btn-primary:hover { transform: translateY(-2px); filter: brightness(1.08); box-shadow: 0 13px 28px rgba(17,81,171,.4); }
        .login-card .btn-primary i { transition: transform .2s; } .login-card .btn-primary:hover i { transform: translateX(3px); }
        .login-card .alert-danger { border-color: rgba(251,113,133,.28); border-radius: 12px; background: rgba(190,24,93,.14); color: #ffd3da; }
        .login-divider { display: flex; align-items: center; gap: 14px; margin: 22px 0; color: #93a8c2; font-size: .73rem; }
        .login-divider::before, .login-divider::after { content: ''; height: 1px; flex: 1; background: rgba(203,221,247,.17); }
        .login-card .google-login { min-height: 48px; border-color: rgba(206,224,249,.22); border-radius: 12px; background: rgba(255,255,255,.06); color: #eff5ff; font-size: .83rem; }
        .login-card .google-login:hover { background: rgba(255,255,255,.12); border-color: rgba(206,224,249,.38); }
        @keyframes loginRise { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes loginBar { from { transform: scaleY(.15); opacity: .25; } to { transform: scaleY(1); opacity: 1; } }
        @keyframes loginFloat { 0%, 100% { transform: translateY(0) rotate(0); } 50% { transform: translateY(-9px) rotate(3deg); } }
        @keyframes loginOrbit { from { transform: translate(-14px, 0) scale(.96); } to { transform: translate(12px, -12px) scale(1.04); } }
        @keyframes loginSpark { 0%, 100% { opacity: .75; transform: scale(.94); } 50% { opacity: 1; transform: scale(1.12); } }
        @media (max-width: 991.98px) { .login-split { grid-template-columns: 1fr; } .login-aside { min-height: 0; padding: 25px clamp(22px, 6vw, 54px) 29px; border-right: 0; border-bottom: 1px solid rgba(255,255,255,.08); } .login-aside .pitch { margin: 28px 0 0; } .login-aside .pitch h1 { max-width: 700px; font-size: clamp(1.8rem, 5vw, 2.7rem); } .login-aside .pitch p { margin: 10px 0 16px; } .login-features { display: flex; flex-wrap: wrap; gap: 9px 20px; } .login-aside .feat { font-size: .76rem; } .login-aside .footnote, .login-art { display: none; } .login-form-side { min-height: 58vh; padding: 34px 22px; } }
        @media (max-width: 575.98px) { .login-aside { padding: 20px 22px 23px; } .login-aside .logo-img { width: 42px; height: 42px; } .login-aside .pitch { margin-top: 23px; } .login-aside .pitch h1 { font-size: 1.85rem; } .login-aside .pitch p { font-size: .88rem; line-height: 1.55; } .login-features { display: none; } .login-form-side { min-height: auto; align-items: flex-start; padding: 22px 16px 32px; } .login-card { padding: 28px 23px; border-radius: 20px; } .login-card::before { margin-bottom: 21px; } }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; scroll-behavior: auto !important; } }
    </style>
</head>
<body>
<div class="login-split">

    <!-- Panneau gauche vitrine -->
    <aside class="login-aside">
        <div class="brand-row">
            <img src="../<?= h($logo_boutique ?: 'assets/images/estock-mark.svg') ?>" alt="Logo <?= h($app_nom) ?>" class="logo-img">
            <span class="brand-name"><?= h($app_nom) ?></span>
        </div>
        <div class="pitch">
            <div class="login-kicker"><i class="bi bi-stars" aria-hidden="true"></i> Votre gestion, en un seul espace</div>
            <h1><?= h($nom_boutique) ?></h1>
            <p><?= h($slogan_boutique) ?></p>
            <div class="login-features">
                <div class="feat"><i class="bi bi-upc-scan" aria-hidden="true"></i> Lecteur de code-barres intégré</div>
                <div class="feat"><i class="bi bi-boxes" aria-hidden="true"></i> Stocks et alertes en temps réel</div>
                <div class="feat"><i class="bi bi-receipt" aria-hidden="true"></i> Factures et tickets imprimables</div>
            </div>
        </div>
        <div class="login-art" aria-hidden="true">
            <span class="login-art-float box"><i class="bi bi-box-seam"></i></span>
            <div class="login-art-panel"><div class="login-art-label"><span>Vue du stock</span><i class="bi bi-graph-up-arrow"></i></div><div class="login-art-bars"><i></i><i></i><i></i><i></i><i></i><i></i></div></div>
            <span class="login-art-float trend"><i class="bi bi-arrow-up-right"></i></span>
        </div>
        <div class="footnote">© <?= date('Y') ?> <?= h($nom_boutique) ?> · <?= h($app_nom) ?></div>
    </aside>

    <!-- Panneau droit formulaire -->
    <div class="login-form-side">
        <div class="card login-card">
            <div class="login-kicker"><i class="bi bi-shield-lock" aria-hidden="true"></i> Accès sécurisé</div>
            <h2 class="login-title">Bon retour</h2>
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
            <div class="login-divider">ou</div>
            <a href="google_login.php" class="btn btn-outline-secondary btn-lg w-100 d-flex align-items-center justify-content-center gap-2 google-login">
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
