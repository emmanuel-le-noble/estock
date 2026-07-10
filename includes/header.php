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

// ----- Construction du menu selon les droits -----
$groupes = [];

if (est_connecte()) {
    $principal = [];
    $principal[] = ['slug' => 'tableau_bord', 'label' => 'Tableau de bord', 'icon' => 'bi-speedometer2'];

    if (peut('stock_consulter')) {
        $logistique = [];
        $logistique[] = ['slug' => 'stock',        'label' => 'Stock',        'icon' => 'bi-boxes'];
        if (peut('articles_consulter')) {
            $logistique[] = ['slug' => 'articles',     'label' => 'Articles',     'icon' => 'bi-box-seam'];
        }
        if (peut('fournisseurs_consulter')) {
            $logistique[] = ['slug' => 'fournisseurs', 'label' => 'Fournisseurs', 'icon' => 'bi-truck'];
        }
        if (peut('stock_gerer')) {
            $logistique[] = ['slug' => 'mouvements',   'label' => 'Mouvements',   'icon' => 'bi-arrow-left-right'];
        }
        if (peut('stock_transfert')) {
            $logistique[] = ['slug' => 'transferts', 'label' => 'Transferts', 'icon' => 'bi-arrow-left-right'];
        }
        $logistique[] = ['slug' => 'peremptions', 'label' => 'Péremptions', 'icon' => 'bi-calendar-week'];
        $principal[] = ['type' => 'group', 'label' => 'Logistique', 'icon' => 'bi-boxes', 'items' => $logistique];
    }

    $groupes[] = ['label' => 'Navigation', 'items' => $principal];

    if (peut('caisse_gerer')) {
        $ventes = [];
        $ventes[] = ['slug' => 'caisse',   'label' => 'Caisse',   'icon' => 'bi-cart-check'];
        if (peut('facturation_consulter')) {
            $ventes[] = ['slug' => 'factures', 'label' => 'Factures', 'icon' => 'bi-receipt'];
        }
        $groupes[] = ['label' => '', 'items' => [
            ['type' => 'group', 'label' => 'Ventes', 'icon' => 'bi-cart-check', 'items' => $ventes],
        ]];
    }

    if (peut('utilisateurs_gerer')) {
        $admin_items = [];
        $admin_items[] = ['slug' => 'utilisateurs', 'label' => 'Utilisateurs', 'icon' => 'bi-people'];
        if (peut('magasins_consulter')) {
            $admin_items[] = ['slug' => 'magasins',     'label' => 'Magasins',     'icon' => 'bi-shop'];
        }
        if (peut('depenses_consulter')) {
            $admin_items[] = ['slug' => 'depenses', 'label' => 'Dépenses', 'icon' => 'bi-cash-stack'];
        }
        $admin_items[] = ['slug' => 'statistiques', 'label' => 'Statistiques', 'icon' => 'bi-graph-up'];
        $admin_items[] = ['slug' => 'parametres',   'label' => 'Paramètres',   'icon' => 'bi-gear'];
        $groupes[] = ['label' => 'Administration', 'items' => [
            ['type' => 'group', 'label' => 'Administration', 'icon' => 'bi-gear', 'items' => $admin_items],
        ]];
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
            <span class="logo-mark"><i class="bi bi-boxes"></i></span>
            <span class="brand-text"><?= h($app_nom) ?></span>
        </div>

        <nav class="sidebar-nav">
            <?php foreach ($groupes as $g): ?>
                <?php if (!empty($g['label'])): ?>
                    <div class="nav-section-label"><?= h($g['label']) ?></div>
                <?php endif; ?>
                <?php foreach ($g['items'] as $item): ?>
                    <?php if (($item['type'] ?? '') === 'group'):
                        $group_slugs = array_column($item['items'], 'slug');
                        $group_open = in_array($page_courante, $group_slugs, true);
                    ?>
                        <div class="nav-group <?= $group_open ? 'active' : '' ?>">
                            <button class="sidebar-dropdown-toggle"
                                    aria-expanded="<?= $group_open ? 'true' : 'false' ?>">
                                <i class="bi <?= h($item['icon']) ?>"></i>
                                <span><?= h($item['label']) ?></span>
                                <i class="bi bi-chevron-right dropdown-arrow"></i>
                            </button>
                            <div class="sidebar-submenu">
                                <?php foreach ($item['items'] as $sub): ?>
                                    <a class="nav-link <?= $page_courante === $sub['slug'] ? 'active' : '' ?>"
                                       href="<?= $sub['slug'] . '.php' ?>">
                                        <i class="bi <?= h($sub['icon']) ?>"></i>
                                        <span><?= h($sub['label']) ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <a class="nav-link <?= $page_courante === $item['slug'] ? 'active' : '' ?>"
                           href="<?= $item['slug'] . '.php' ?>">
                            <i class="bi <?= h($item['icon']) ?>"></i>
                            <span><?= h($item['label']) ?></span>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>

        </nav>

        <div class="sidebar-footer">
            <?php if (peut('audit_consulter')): ?>
            <a class="side-user <?= $page_courante === 'audit' ? 'active' : '' ?>" href="audit.php" title="Journal d'activité">
                <span class="avatar avatar-accent"><i class="bi bi-clock-history"></i></span>
                <span class="meta">
                    <span class="nm">Journal d'activité</span>
                </span>
            </a>
            <?php endif; ?>
            <?php if (in_array($role, [ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_VENDEUR])): ?>
            <a class="side-user <?= $page_courante === 'cloture' ? 'active' : '' ?>" href="cloture.php" title="Clôturer la caisse">
                <span class="avatar" style="background:#f59e0b;"><i class="bi bi-lock-fill"></i></span>
                <span class="meta">
                    <span class="nm">Clôturer la Caisse</span>
                </span>
            </a>
            <?php endif; ?>
            <a class="side-user <?= $page_courante === 'profil' ? 'active' : '' ?>" href="profil.php" title="Mon profil">
                <span class="avatar avatar-initial"><?= h(strtoupper($initiale)) ?></span>
                <span class="meta">
                    <span class="nm"><?= h($user['nom'] ?? '') ?></span>
                    <span class="rl"><?= h($role) ?></span>
                </span>
            </a>
            <form method="POST" action="auth/logout.php" class="d-inline">
                <?= csrf_field() ?>
                <button type="submit" class="side-user btn btn-link text-decoration-none" title="Se déconnecter" style="background:none;border:none;">
                    <span class="avatar avatar-logout"><i class="bi bi-box-arrow-right"></i></span>
                    <span class="meta">
                        <span class="nm">Déconnexion</span>
                    </span>
                </button>
            </form>
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
        </header>

        <main class="app-content" id="appMain">
        <?php afficher_flash(); ?>
<?php else: ?>
<main class="app-content">
<?php endif; ?>