<?php
/**
 * header.php - En-tête commun : layout sidebar moderne + topbar.
 *
 * Variables attendues (optionnelles) :
 *   $titre_page  : titre affiché dans la topbar
 *   $sous_titre  : petit libellé sous le titre
 */
$titre_page  = $titre_page  ?? param_app_name();
$sous_titre  = $sous_titre  ?? '';
$user = user_courant();
$role = user_role();
$app_nom = param_app_name();

// ----- Sécurité : Initialisation automatique des compteurs d'alertes topbar -----
if (est_connecte() && (!isset($nb_alertes_topbar) || !isset($nb_alertes_peremption))) {
    $magasin_id_topbar = $_SESSION['user']['magasin_id'] ?? 0;
    
    if (!isset($nb_alertes_topbar)) {
        $nb_alertes_topbar = function_exists('db_articles_low_stock_count') ? db_articles_low_stock_count($pdo, $magasin_id_topbar) : 0;
    }
    
    if (!isset($nb_alertes_peremption)) {
        $nb_alertes_peremption = function_exists('db_peremption_alert_count') ? db_peremption_alert_count($pdo, 30, $magasin_id_topbar) : 0;
    }
} else {
    $nb_alertes_topbar = $nb_alertes_topbar ?? 0;
    $nb_alertes_peremption = $nb_alertes_peremption ?? 0;
}

// ----- Icône (initiale) pour l'avatar -----
$initiale = mb_substr(trim($user['nom'] ?? 'U'), 0, 1, 'UTF-8');

// ----- Construction du menu (liste plate, sans sous-menus) selon les droits -----
$menu = [];
$can_manage_stock = peut_gerer_stock();
$can_bill = peut_facturer();
$can_admin = peut_administrer();

if (est_connecte()) {
    $menu[] = ['slug' => 'tableau_bord', 'label' => 'Accueil', 'icon' => 'bi-speedometer2', 'color' => 'clr-indigo'];
    if ($can_admin) {
        $menu[] = ['slug' => 'statistiques', 'label' => 'Statistiques', 'icon' => 'bi-graph-up', 'color' => 'clr-indigo'];
    }

    if ($can_manage_stock) {
        $menu[] = ['slug' => 'stock', 'label' => 'Stock', 'icon' => 'bi-boxes', 'color' => 'clr-emerald'];
        $menu[] = ['slug' => 'articles', 'label' => 'Articles', 'icon' => 'bi-box-seam', 'color' => 'clr-emerald'];
        if (peut('articles_gerer')) {
            $menu[] = ['slug' => 'categories', 'label' => 'Catégories', 'icon' => 'bi-tags', 'color' => 'clr-emerald'];
        }
        $menu[] = ['slug' => 'fournisseurs', 'label' => 'Fournisseurs', 'icon' => 'bi-truck', 'color' => 'clr-emerald'];
        if (peut('achats_consulter')) {
            $menu[] = ['slug' => 'commandes_fournisseur', 'label' => "Commandes d'achat", 'icon' => 'bi-cart-plus', 'color' => 'clr-emerald'];
        }
        if (peut('inventaire_consulter')) {
            $menu[] = ['slug' => 'inventaire', 'label' => 'Inventaire', 'icon' => 'bi-clipboard-check', 'color' => 'clr-emerald'];
        }
        $menu[] = ['slug' => 'mouvements', 'label' => 'Mouvements', 'icon' => 'bi-arrow-left-right', 'color' => 'clr-emerald'];
        $menu[] = ['slug' => 'peremptions', 'label' => 'Péremptions', 'icon' => 'bi-calendar-week', 'color' => 'clr-emerald'];
        if (in_array($role, [ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_MAGASINIER], true)) {
            $menu[] = ['slug' => 'etiquettes', 'label' => 'Étiquettes rayon', 'icon' => 'bi-tag', 'color' => 'clr-emerald'];
            $menu[] = ['slug' => 'transferts', 'label' => 'Transferts', 'icon' => 'bi-arrow-repeat', 'color' => 'clr-emerald'];
        }
    }

    if ($can_bill) {
        $menu[] = ['slug' => 'caisse', 'label' => 'Caisse (POS)', 'icon' => 'bi-cash-coin', 'color' => 'clr-sky'];
        $menu[] = ['slug' => 'factures', 'label' => 'Factures', 'icon' => 'bi-receipt', 'color' => 'clr-sky'];
        if (peut('retours_consulter')) {
            $menu[] = ['slug' => 'retours', 'label' => 'Retours / SAV', 'icon' => 'bi-arrow-counterclockwise', 'color' => 'clr-sky'];
        }
        if (peut('promotions_consulter')) {
            $menu[] = ['slug' => 'promotions', 'label' => 'Promotions & Remises', 'icon' => 'bi-percent', 'color' => 'clr-sky'];
        }
        $menu[] = ['slug' => 'cloture', 'label' => 'Clôture de Caisse', 'icon' => 'bi-lock-fill', 'color' => 'clr-sky'];
    }

    if ($can_admin) {
        $menu[] = ['slug' => 'depenses', 'label' => 'Dépenses', 'icon' => 'bi-wallet2', 'color' => 'clr-rose'];
        $menu[] = ['slug' => 'utilisateurs', 'label' => 'Utilisateurs', 'icon' => 'bi-people', 'color' => 'clr-violet'];
        $menu[] = ['slug' => 'magasins', 'label' => 'Magasins', 'icon' => 'bi-shop', 'color' => 'clr-violet'];
        $menu[] = ['slug' => 'parametres', 'label' => 'Paramètres', 'icon' => 'bi-gear', 'color' => 'clr-violet'];
    }

    if (peut('audit_consulter')) {
        $menu[] = ['slug' => 'audit', 'label' => 'Journal d\'activité', 'icon' => 'bi-clock-history', 'color' => 'clr-slate'];
    }
}

$page_courante = $_GET['_page'] ?? str_replace('.php', '', basename($_SERVER['PHP_SELF']));
$theme_palettes = [
    'indigo' => ['#6366f1', '#4f46e5', '#eef2ff'],
    'emerald' => ['#10b981', '#059669', '#ecfdf5'],
    'sky' => ['#0ea5e9', '#0284c7', '#e0f2fe'],
    'rose' => ['#f43f5e', '#e11d48', '#fff1f2'],
    'amber' => ['#f59e0b', '#d97706', '#fffbeb'],
];
$theme_couleur = param('theme_couleur', 'indigo');
$theme_vars = $theme_palettes[$theme_couleur] ?? $theme_palettes['indigo'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($titre_page) ?> · <?= h($app_nom) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="<?= BASE_URL ?>assets/images/logo-eStock.ico">
    <link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
    <style>
        :root {
            --brand: <?= h($theme_vars[0]) ?>;
            --brand-2: <?= h($theme_vars[1]) ?>;
            --brand-soft: <?= h($theme_vars[2]) ?>;
        }
    </style>
</head>
<body>

<?php if (est_connecte()): ?>
<div class="app-shell">

    <!-- ============ SIDEBAR ============ -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <img src="<?= BASE_URL ?>assets/images/logo-eStock-3.png" alt="Logo <?= h($app_nom) ?>" class="logo-img">
            <span class="brand-text"><?= h($app_nom) ?></span>
        </div>

        <nav class="sidebar-nav">
            <?php foreach ($menu as $item): ?>
                <a class="nav-link <?= $page_courante === $item['slug'] ? 'active' : '' ?>"
                   href="<?= $item['slug'] . '.php' ?>">
                    <span class="nav-icon <?= $item['color'] ?>"><i class="bi <?= $item['icon'] ?>"></i></span>
                    <span><?= h($item['label']) ?></span>
                    <?php if ($item['slug'] === 'peremptions' && $nb_alertes_peremption > 0): ?>
                        <span class="badge bg-danger ms-auto" style="font-size:.65rem;"><?= $nb_alertes_peremption ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <a class="side-user <?= $page_courante === 'profil' ? 'active' : '' ?>" href="profil.php" title="Mon profil">
                <span class="avatar avatar-initial"><?= h(strtoupper($initiale)) ?></span>
                <span class="meta">
                    <span class="nm"><?= h($user['nom'] ?? '') ?></span>
                    <span class="rl"><?= h($role) ?></span>
                </span>
            </a>
        </div>
    </aside>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- ============ CONTENU ============ -->
    <div class="app-main">
        <header class="app-topbar">
            <div class="d-flex align-items-center gap-2">
                <button class="burger" id="burger" aria-label="Menu"><i class="bi bi-list"></i></button>
                <div>
                    <h1><?= h($titre_page) ?></h1>
                    <?php if ($sous_titre): ?><div class="crumb"><?= h($sous_titre) ?></div><?php endif; ?>
                </div>
            </div>
            <div class="d-none d-md-flex align-items-center gap-2">
                <span id="connectionBadge" class="badge-offline badge-online"></span>
                <?php if (peut('caisse_gerer')): ?>
                    <a href="<?= 'caisse.php' ?>" class="btn btn-success btn-sm"><i class="bi bi-cart-check"></i> Caisse</a>
                <?php endif; ?>
                <?php if (peut('stock_consulter')): ?>
                    <a href="suggestions_achat.php" class="btn btn-sm position-relative <?= $nb_alertes_topbar > 0 ? 'btn-outline-warning' : 'btn-outline-secondary' ?>" title="Alertes stock">
                        <i class="bi bi-bell"></i>
                        <?php if ($nb_alertes_topbar > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?= $nb_alertes_topbar ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <?php if ($nb_alertes_peremption > 0): ?>
                        <a href="peremptions.php" class="btn btn-sm btn-outline-danger position-relative" title="Alertes péremption">
                            <i class="bi bi-calendar-week"></i>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?= $nb_alertes_peremption ?>
                            </span>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
                <a href="<?= 'tableau_bord.php' ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-house-door"></i></a>
            </div>
            <form method="POST" action="auth/logout.php" class="m-0 d-flex align-items-center">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-danger btn-sm" title="Se déconnecter">
                    <i class="bi bi-box-arrow-right"></i>
                    <span class="d-none d-md-inline ms-1">Déconnexion</span>
                </button>
            </form>
        </header>

        <main class="app-content" id="appMain">
        <?php afficher_flash(); ?>
<?php else: ?>
<main class="app-content">
<?php endif; ?>