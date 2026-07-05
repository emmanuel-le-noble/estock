<?php
/**
 * acces_refuse.php - Page 403 affichée quand un rôle n'est pas autorisé.
 */
if (!function_exists('est_connecte')) { require_once __DIR__ . '/../config/connexion.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/helpers.php'; }
exiger_connexion(); // s'assure d'avoir le contexte session/user
$app_nom = param_app_name();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accès refusé · <?= h($app_nom) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 text-center">
            <div class="card shadow-sm border-danger">
                <div class="card-body py-5">
                    <i class="bi bi-shield-lock display-1 text-danger"></i>
                    <h2 class="mt-3">Accès refusé</h2>
                    <p class="text-muted">
                        Votre rôle <strong><?= h(user_role()) ?></strong>
                        ne vous permet pas d'accéder à cette page.
                    </p>
                    <a href="<?= 'tableau_bord.php' ?>" class="btn btn-primary">
                        <i class="bi bi-house-door"></i> Retour au tableau de bord
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
