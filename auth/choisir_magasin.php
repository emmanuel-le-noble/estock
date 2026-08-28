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
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="../assets/images/logo-eStock.ico">
    <style>
        .magasin-split { min-height: 100vh; display: grid; grid-template-columns: 1fr 1.4fr; }
        @media (max-width: 991.98px) { .magasin-split { grid-template-columns: 1fr; } .magasin-aside { display: none; } }

        .magasin-aside {
            position: relative; overflow: hidden; color: #fff;
            background: radial-gradient(120% 120% at 0% 0%, #10b981 0%, #047857 45%, #0b1020 100%);
            padding: 56px; display: flex; flex-direction: column; justify-content: space-between;
        }
        .magasin-aside::before, .magasin-aside::after {
            content: ''; position: absolute; border-radius: 50%; filter: blur(60px); pointer-events: none;
        }
        .magasin-aside::before { width: 320px; height: 320px; background: rgba(52,211,153,.5); top: -80px; right: -60px; }
        .magasin-aside::after  { width: 280px; height: 280px; background: rgba(251,191,36,.3); bottom: -80px; left: -40px; }
        .magasin-aside .brand-row { display: flex; align-items: center; gap: 12px; position: relative; z-index: 1; }
        .magasin-aside .logo-img {
            width: 46px; height: 46px; border-radius: 13px;
            object-fit: cover; box-shadow: 0 10px 26px rgba(16,185,129,.5);
        }
        .magasin-aside .brand-name { font-size: 1.5rem; font-weight: 800; letter-spacing: -.02em; }
        .magasin-aside .pitch { position: relative; z-index: 1; }
        .magasin-aside .pitch h2 { color: #fff; font-size: 2.3rem; font-weight: 800; line-height: 1.15; letter-spacing: -.02em; }
        .magasin-aside .pitch p { color: #d1fae5; font-size: 1.05rem; max-width: 420px; margin-top: 14px; }
        .magasin-aside .footnote { position: relative; z-index: 1; color: #a7f3d0; font-size: .82rem; }

        .magasin-form-side { display: flex; align-items: center; justify-content: center; padding: 40px 28px; background: var(--bg); }
        .magasin-card { cursor: pointer; transition: border-color .15s ease, box-shadow .15s ease; border: 2px solid var(--bs-secondary-border-subtle); }
        .magasin-card:hover { border-color: #10b981; }
        .magasin-card.magasin-active { border-color: #10b981; box-shadow: 0 4px 14px rgba(16,185,129,.25); background: #ecfdf5; }
        .magasin-card input:checked + .card-body { }
    </style>
</head>
<body>
<div class="magasin-split">

    <!-- Panneau gauche -->
    <aside class="magasin-aside">
        <div class="brand-row">
            <img src="../assets/images/logo-eStock-2.png" alt="Logo <?= h($app_nom) ?>" class="logo-img">
            <span class="brand-name"><?= h($app_nom) ?></span>
        </div>
        <div class="pitch">
            <h2>Bienvenue 👋</h2>
            <p>Pour quel magasin souhaitez-vous faire le suivi de la gestion&nbsp;? Vous pourrez changer à tout moment depuis la barre du haut.</p>
        </div>
        <div class="footnote">© <?= date('Y') ?> <?= h($app_nom) ?></div>
    </aside>

    <!-- Panneau droit : liste des magasins -->
    <div class="magasin-form-side">
        <div class="w-100" style="max-width: 640px;">
            <h1 class="fw-bold" style="letter-spacing:-.02em;">Choisir un magasin</h1>
            <p class="text-muted mb-4">Sélectionnez le magasin à suivre pour cette session.</p>

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
                            <input type="radio" name="magasin_id" value="<?= (int)$m['id'] ?>" class="d-none" <?= $sel ? 'checked' : '' ?>>
                            <div class="card-body d-flex align-items-center gap-3">
                                <i class="bi bi-shop fs-3 <?= $sel ? 'text-success' : 'text-primary-soft text-muted' ?>"></i>
                                <div class="flex-grow-1">
                                    <div class="fw-bold"><?= h($m['nom']) ?></div>
                                    <div class="small text-muted"><?= h($m['adresse'] ?: 'Aucune adresse') ?></div>
                                </div>
                                <?php if ($sel): ?>
                                    <i class="bi bi-check-circle-fill text-success fs-5"></i>
                                <?php endif; ?>
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