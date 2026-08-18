<?php
/**
 * desinscription.php - Désinscription e-mails (droit d'opposition).
 *
 * Page publique, accessible sans authentification : un lien personnel
 * (token 64 hex non devinable) est inclus dans chaque e-mail de prospection.
 * La réponse est volontairement générique : elle ne révèle jamais si
 * l'adresse existait dans les systèmes (protection contre l'énumération).
 */
// Guard: compatible avec le routeur front controller (index.php)
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';

$app_nom = param_app_name();
$nom_boutique = param_shop_name();

// Session minimale pour le jeton CSRF (sans authentification)
if (!isset($_SESSION['desinscription_csrf'])) {
    $_SESSION['desinscription_csrf'] = bin2hex(random_bytes(32));
}

$token   = preg_replace('/[^a-f0-9]/', '', (string)($_GET['token'] ?? ($_POST['token'] ?? '')));
$message = '';
$statut  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postToken = (string)($_POST['_csrf_token'] ?? '');
    if (!hash_equals($_SESSION['desinscription_csrf'] ?? '', $postToken)) {
        $statut  = 'danger';
        $message = 'Session expirée. Rechargez la page et réessayez.';
    } else {
        // Réponse générique systématique (anti-énumération)
        if ($token !== '' && strlen($token) === 64) {
            email_desinscrire_par_token($pdo, $token);
            suivre_activite('DESINSCRIPTION', 'Désinscription e-mail via lien (opposition)');
        } else {
            suivre_activite('DESINSCRIPTION_INVALID', 'Lien de désinscription invalide ou vide');
        }
        $_SESSION['desinscription_csrf'] = bin2hex(random_bytes(32)); // invalide les vieux formulaires
        $statut  = 'success';
        $message = 'Votre demande de désinscription a été enregistrée. Si votre adresse était dans nos fichiers, vous ne recevrez plus nos e-mails.';
    }
    $token = preg_replace('/[^a-f0-9]/', '', (string)($_POST['token'] ?? ''));
}

$token_valide = ($token !== '' && strlen($token) === 64);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Désinscription · <?= h($app_nom) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6fa; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card-desinscription { max-width: 460px; width: 100%; border: 0; border-radius: 16px; box-shadow: 0 10px 40px rgba(30,41,59,.12); }
        .badge-opposition { position: absolute; top: 14px; right: 14px; }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="card card-desinscription position-relative">
            <span class="badge bg-secondary badge-opposition">Droit d'opposition</span>
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary bg-opacity-10 text-primary" style="width:64px;height:64px;font-size:26px;">
                        <i class="bi bi-envelope-dash"></i>
                    </span>
                    <h1 class="h4 mt-3 mb-1">Désinscription e-mails</h1>
                    <p class="text-muted small mb-0"><?= h($nom_boutique) ?></p>
                </div>

                <?php if ($statut): ?>
                    <div class="alert alert-<?= h($statut) ?>"><?= h($message) ?></div>
                    <div class="text-center text-muted small mt-2">
                        Vous pouvez fermer cette page. <a href="<?= h(email_base_url()) ?>" class="text-decoration-none">Retour au site</a>
                    </div>
                <?php elseif (!$token_valide): ?>
                    <div class="alert alert-warning">Lien de désinscription invalide ou incomplet.</div>
                    <p class="text-muted small mb-0">
                        Vérifiez l'adresse du lien dans l'e-mail reçu, ou contactez-nous pour exercer
                        votre droit d'opposition.
                    </p>
                <?php else: ?>
                    <p class="text-muted mb-4">
                        Voulez-vous vraiment ne plus recevoir les e-mails de
                        <strong><?= h($nom_boutique) ?></strong> ?
                    </p>
                    <form method="post" action="desinscription.php">
                        <input type="hidden" name="_csrf_token" value="<?= h($_SESSION['desinscription_csrf']) ?>">
                        <input type="hidden" name="token" value="<?= h($token) ?>">
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-check2"></i> Confirmer ma désinscription
                            </button>
                        </div>
                    </form>
                    <p class="text-center text-muted small mt-4 mb-0">
                        Vous pourrez vous réinscrire à tout moment. Aucune autre action n'est nécessaire.
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <p class="text-center text-muted small mt-4">
            Powered by <strong><?= h($app_nom) ?></strong> · Protection des données personnelles
        </p>
    </div>
</body>
</html>