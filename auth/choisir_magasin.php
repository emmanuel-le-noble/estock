<?php
/**
 * choisir_magasin.php - Choix du magasin de suivi (Directeur global).
 *
 * À la connexion, le Directeur choisit le magasin pour lequel il
 * souhaite faire le suivi de la gestion. Le choix est conservé en session
 * et peut être modifié à tout moment depuis la topbar.
 */
// Guard: compatible avec le routeur front controller (index.php)
if (!function_exists('est_connecte')) { require_once __DIR__ . '/../config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/../includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/../includes/helpers.php'; }
require_once __DIR__ . '/../config/connexion.php';
require_once __DIR__ . '/../includes/db_functions.php';
require_once __DIR__ . '/../includes/helpers.php';

// Page réservée au Directeur : lui seul choisit le magasin de suivi.
exiger_role(ROLE_DIRECTEUR);

$app_nom = param_app_name();
$logo_boutique = param_shop_logo();
$magasins = db_magasins_list($pdo);

// Retour vers la page d'origine (choix fait depuis la topbar), validé côté serveur.
$retour = (string)($_POST['retour'] ?? ($_GET['retour'] ?? ''));
if (!preg_match('#^[a-z0-9_]+\.php$#i', $retour) || !file_exists(__DIR__ . '/../' . $retour)) {
    $retour = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard('choisir_magasin.php');
    $magasin_id = (int)($_POST['magasin_id'] ?? 0);
    if ($magasin_id <= 0) {
        flash_error('Veuillez sélectionner un magasin.');
        redirect($retour !== '' ? '../' . $retour : 'choisir_magasin.php');
    }
    $stmt = $pdo->prepare("SELECT nom FROM magasins WHERE id = ? AND actif = 1");
    $stmt->execute([$magasin_id]);
    $nom_magasin = $stmt->fetchColumn();
    if ($nom_magasin === false) {
        flash_error('Magasin sélectionné invalide ou inactif.');
        redirect($retour !== '' ? '../' . $retour : 'choisir_magasin.php');
    }

    $_SESSION['magasin_actif'] = $magasin_id;
    suivre_activite('CHOIX_MAGASIN', 'Magasin de suivi sélectionné: ' . $nom_magasin);
    redirect($retour !== '' ? '../' . $retour : '../tableau_bord.php');
}

$magasin_courant = (int)($_SESSION['magasin_actif'] ?? 0);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choisir un magasin · <?= h($app_nom) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../assets/css/style.css?v=18" rel="stylesheet">
    <link rel="icon" href="../<?= h($logo_boutique ?: 'assets/images/estock-mark.svg') ?>">
    <style>
        .magasin-split { --magasin-ink: #f5f8ff; min-height: 100vh; min-height: 100dvh; display: grid; grid-template-columns: minmax(0, .92fr) minmax(510px, 1.08fr); overflow: hidden; color: var(--magasin-ink); background: radial-gradient(ellipse at 85% 8%, rgba(40,105,198,.25), transparent 34%), linear-gradient(145deg, #101d35, #0b172c 72%); }
        .magasin-aside { position: relative; isolation: isolate; overflow: hidden; min-height: 100vh; padding: clamp(30px, 5vw, 72px); display: flex; flex-direction: column; justify-content: space-between; border-right: 1px solid rgba(255,255,255,.08); background: radial-gradient(ellipse at 12% 12%, rgba(39,107,206,.42), transparent 48%), linear-gradient(155deg, #18365f, #0d1f3b); }
        .magasin-aside::before { content: ''; position: absolute; z-index: -1; inset: 0; opacity: .18; background-image: linear-gradient(rgba(184,211,248,.16) 1px, transparent 1px), linear-gradient(90deg, rgba(184,211,248,.16) 1px, transparent 1px); background-size: 52px 52px; mask-image: linear-gradient(135deg, #000, transparent 72%); }
        .magasin-aside::after { content: ''; position: absolute; z-index: -1; width: 500px; height: 500px; right: -250px; bottom: -300px; border: 1px solid rgba(151,194,249,.18); border-radius: 50%; box-shadow: 0 0 0 42px rgba(151,194,249,.035), 0 0 0 88px rgba(151,194,249,.025); animation: magasinOrbit 18s ease-in-out infinite alternate; }
        .magasin-aside .brand-row { display: flex; align-items: center; gap: 13px; position: relative; z-index: 1; animation: magasinRise .7s both; }
        .magasin-aside .logo-img { width: 48px; height: 48px; border-radius: 14px; object-fit: contain; box-shadow: 0 10px 26px rgba(3,17,38,.3); }
        .magasin-aside .brand-name { color: #fff; font-size: 1.42rem; font-weight: 750; letter-spacing: -.035em; }
        .magasin-aside .pitch { position: relative; z-index: 1; max-width: 520px; margin: 52px 0 26px; animation: magasinRise .8s .08s both; }
        .magasin-kicker { display: inline-flex; align-items: center; gap: 9px; margin-bottom: 18px; color: #c5dbfa; font-size: .69rem; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; }
        .magasin-kicker i { color: #86baff; font-size: 1rem; animation: magasinSpark 2.5s ease-in-out infinite; }
        .magasin-aside .pitch h2 { max-width: 500px; margin: 0; color: #fff; font-size: clamp(2.1rem, 4vw, 3.6rem); font-weight: 720; line-height: 1.08; letter-spacing: -.055em; text-wrap: balance; }
        .magasin-aside .pitch p { color: rgba(231,240,253,.76); font-size: .98rem; line-height: 1.75; max-width: 440px; margin-top: 18px; }
        .magasin-aside .footnote { position: relative; z-index: 1; color: rgba(208,224,244,.58); font-size: .76rem; letter-spacing: .02em; animation: magasinRise .7s .2s both; }
        .magasin-art { position: absolute; z-index: -1; right: clamp(20px, 5vw, 74px); bottom: 19%; width: 210px; height: 180px; animation: magasinRise .9s .24s both; }
        .magasin-art-ring { position: absolute; inset: 12px 8px 0 20px; border: 1px solid rgba(205,226,255,.2); border-radius: 28px; background: linear-gradient(145deg, rgba(255,255,255,.10), rgba(10,34,69,.3)); transform: rotate(-8deg); }
        .magasin-art-card { position: absolute; width: 128px; height: 82px; display: grid; place-items: center; border: 1px solid rgba(205,226,255,.26); border-radius: 20px; background: linear-gradient(145deg, rgba(255,255,255,.18), rgba(19,57,101,.62)); color: #c9e0ff; font-size: 2rem; box-shadow: 0 20px 44px rgba(1,12,29,.25); backdrop-filter: blur(14px); }
        .magasin-art-card.one { top: 20px; left: 0; animation: magasinFloat 4.5s ease-in-out infinite; }
        .magasin-art-card.two { right: 0; bottom: 10px; color: #80e2bc; animation: magasinFloat 5s ease-in-out -1.2s infinite reverse; }
        .magasin-form-side { position: relative; display: flex; align-items: center; justify-content: center; padding: 42px clamp(22px, 5vw, 76px); background: radial-gradient(ellipse at 100% 100%, rgba(39,107,206,.14), transparent 48%); color: #f5f8ff; }
        .magasin-content { width: 100%; max-width: 720px; animation: magasinRise .75s .12s both; }
        .magasin-heading-kicker { color: #9ebde4; font-size: .66rem; font-weight: 700; letter-spacing: .13em; text-transform: uppercase; }
        .magasin-content h1 { margin: 7px 0 0; color: #fff; font-size: clamp(1.7rem, 3vw, 2.2rem); font-weight: 720; letter-spacing: -.045em; }
        .magasin-content > p { margin: 8px 0 26px; color: #b4c5dc; font-size: .88rem; }
        .magasin-card { position: relative; cursor: pointer; transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease, background .2s ease; border: 1px solid rgba(203,221,247,.16); border-radius: 17px; background: linear-gradient(145deg, rgba(255,255,255,.09), rgba(255,255,255,.035)); color: #f5f8ff; box-shadow: inset 0 1px rgba(255,255,255,.06); backdrop-filter: blur(16px); }
        .magasin-card .card-body { min-height: 96px; padding: 18px; }
        .magasin-card .bi-shop { width: 46px; height: 46px; display: grid; place-items: center; flex: 0 0 46px; border: 1px solid rgba(132,181,255,.18); border-radius: 14px; background: rgba(48,117,203,.17); color: #a6cbff; font-size: 1.2rem !important; }
        .magasin-card .fw-bold { color: #f5f8ff; font-size: .91rem; }
        .magasin-card .text-muted { color: #9fb1ca !important; font-size: .75rem; }
        .magasin-card .bi-check-circle-fill { color: #70e0b5 !important; filter: drop-shadow(0 0 7px rgba(112,224,181,.3)); }
        .magasin-card:hover { border-color: rgba(132,181,255,.5); background: linear-gradient(145deg, rgba(59,130,246,.17), rgba(255,255,255,.06)); box-shadow: 0 12px 28px rgba(2,14,34,.23); transform: translateY(-2px); }
        .magasin-card.magasin-active { border: 1px solid rgba(112,224,181,.68); background: linear-gradient(145deg, rgba(16,185,129,.17), rgba(255,255,255,.055)); box-shadow: inset 0 0 0 1px rgba(112,224,181,.1), 0 10px 28px rgba(2,14,34,.2); }
        .magasin-card:focus-within { outline: 2px solid #93c5fd; outline-offset: 3px; }
        .magasin-content .btn-success { min-height: 50px; border: 0; border-radius: 12px; background: linear-gradient(105deg, #10a879, #07875f); box-shadow: 0 9px 22px rgba(7,128,82,.24); font-size: .88rem; font-weight: 650; transition: transform .2s, box-shadow .2s; }
        .magasin-content .btn-success:hover { transform: translateY(-2px); box-shadow: 0 13px 28px rgba(7,128,82,.34); }
        .magasin-content .alert-warning { border-color: rgba(251,191,36,.3); border-radius: 14px; background: rgba(180,83,9,.16); color: #fde8b0; }
        .magasin-content .btn-outline-secondary { border-color: rgba(206,224,249,.22); border-radius: 10px; background: rgba(255,255,255,.06); color: #eff5ff; }
        @keyframes magasinRise { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes magasinFloat { 0%, 100% { transform: translateY(0) rotate(-4deg); } 50% { transform: translateY(-9px) rotate(1deg); } }
        @keyframes magasinOrbit { from { transform: translate(-14px, 0) scale(.96); } to { transform: translate(12px, -12px) scale(1.04); } }
        @keyframes magasinSpark { 0%, 100% { opacity: .75; transform: scale(.94); } 50% { opacity: 1; transform: scale(1.12); } }
        @media (max-width: 991.98px) { .magasin-split { grid-template-columns: 1fr; } .magasin-aside { min-height: 0; padding: 25px clamp(22px, 6vw, 54px) 29px; border-right: 0; border-bottom: 1px solid rgba(255,255,255,.08); } .magasin-aside .pitch { margin: 26px 0 0; } .magasin-aside .pitch h2 { font-size: clamp(1.8rem, 5vw, 2.7rem); } .magasin-aside .pitch p { margin: 10px 0 0; } .magasin-art, .magasin-aside .footnote { display: none; } .magasin-form-side { min-height: 58vh; padding: 34px 22px; } }
        @media (max-width: 575.98px) { .magasin-aside { padding: 20px 22px 23px; } .magasin-aside .logo-img { width: 42px; height: 42px; } .magasin-aside .pitch { margin-top: 22px; } .magasin-aside .pitch h2 { font-size: 1.8rem; } .magasin-aside .pitch p { font-size: .86rem; line-height: 1.55; } .magasin-form-side { min-height: auto; align-items: flex-start; padding: 25px 16px 32px; } .magasin-card .card-body { min-height: 82px; padding: 14px; } }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; scroll-behavior: auto !important; } }
    </style>
</head>
<body>
<div class="magasin-split">

    <!-- Panneau gauche -->
    <aside class="magasin-aside">
        <div class="brand-row">
            <img src="../<?= h($logo_boutique ?: 'assets/images/estock-mark.svg') ?>" alt="Logo <?= h($app_nom) ?>" class="logo-img">
            <span class="brand-name"><?= h($app_nom) ?></span>
        </div>
        <div class="pitch">
            <div class="magasin-kicker"><i class="bi bi-stars" aria-hidden="true"></i> Votre espace de travail</div>
            <h2>Bienvenue</h2>
            <p>Pour quel magasin souhaitez-vous faire le suivi de la gestion&nbsp;? Vous pourrez changer à tout moment depuis la barre du haut.</p>
        </div>
        <div class="magasin-art" aria-hidden="true"><div class="magasin-art-ring"></div><span class="magasin-art-card one"><i class="bi bi-shop-window"></i></span><span class="magasin-art-card two"><i class="bi bi-geo-alt"></i></span></div>
        <div class="footnote">© <?= date('Y') ?> <?= h($app_nom) ?></div>
    </aside>

    <!-- Panneau droit : liste des magasins -->
    <div class="magasin-form-side">
        <div class="magasin-content">
            <div class="magasin-heading-kicker">ÉTAPE 1 · SÉLECTION DU SITE</div>
            <h1>Choisir un magasin</h1>
            <p>Sélectionnez le magasin à suivre pour cette session.</p>

            <?php
            $flashMessages = $_SESSION['flash'] ?? [];
            unset($_SESSION['flash']);
            if (!empty($flashMessages)):
            ?>
            <div id="choisirFlashData" style="display:none;" data-flash="<?= h(json_encode($flashMessages, JSON_UNESCAPED_UNICODE)) ?>"></div>
            <?php endif; ?>

            <?php if (empty($magasins)): ?>
                <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Aucun magasin actif n'est disponible. Créez un magasin dans <strong>Magasins</strong> puis reconnectez-vous.</div>
                <a href="../tableau_bord.php" class="btn btn-outline-secondary">Continuer sans choisir</a>
            <?php else: ?>
            <form method="post" action="choisir_magasin.php">
                <?= csrf_field() ?>
                <input type="hidden" name="retour" value="<?= h($retour) ?>">
                <div class="row g-3">
                    <?php foreach ($magasins as $m): $sel = $magasin_courant === (int)$m['id']; ?>
                    <div class="col-md-6">
                        <label class="card magasin-card h-100 mb-0 <?= $sel ? 'magasin-active' : '' ?>" data-maga-card="<?= (int)$m['id'] ?>">
                            <input type="radio" name="magasin_id" value="<?= (int)$m['id'] ?>" class="visually-hidden" <?= $sel ? 'checked' : '' ?>>
                            <div class="card-body d-flex align-items-center gap-3">
                                <i class="bi bi-shop fs-3 <?= $sel ? 'text-success' : 'text-primary-soft text-muted' ?>"></i>
                                <div class="flex-grow-1">
                                    <div class="fw-bold"><?= h($m['nom']) ?></div>
                                    <div class="small text-muted"><?= h($m['adresse'] ?: 'Aucune adresse') ?></div>
                                </div>
                                <i class="bi bi-check-circle-fill text-success fs-5 <?= $sel ? '' : 'd-none' ?>" aria-hidden="true"></i>
                            </div>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button class="btn btn-success btn-lg w-100 mt-4" type="submit" style="justify-content:center;">
                    Suivre ce magasin <i class="bi bi-arrow-right"></i>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script nonce="<?= h(csp_nonce()) ?>">
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[name="magasin_id"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
            document.querySelectorAll('.magasin-card').forEach(function(card) {
                const actif = card.querySelector('input').checked;
                card.classList.toggle('magasin-active', actif);
                card.querySelector('.bi-check-circle-fill')?.classList.toggle('d-none', !actif);
                card.querySelector('.bi-shop')?.classList.toggle('text-success', actif);
                card.querySelector('.bi-shop')?.classList.toggle('text-muted', !actif);
            });
        });
    });
});
</script>
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1090;" id="flashToastContainer"></div>
<script nonce="<?= h(csp_nonce()) ?>">
(function(){
    var el = document.getElementById('choisirFlashData');
    if (!el) return;
    var msgs;
    try { msgs = JSON.parse(el.dataset.flash); } catch(e) { return; }
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
</body>
</html>
