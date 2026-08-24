<?php
/**
 * header.php - En-tête commun : layout sidebar moderne + topbar.
 *
 * Variables attendues (optionnelles) :
 *   $titre_page  : titre affiché dans la topbar
 *   $sous_titre  : petit libellé sous le titre
 */

// Empêcher le navigateur de mettre en cache les pages dynamiques
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$titre_page  = $titre_page  ?? param_app_name();
$sous_titre  = $sous_titre  ?? '';
$user = user_courant();
$role = user_role();
$app_nom = param_app_name();

// ----- Sécurité : Initialisation automatique des compteurs d'alertes topbar -----
if (est_connecte() && (!isset($nb_alertes_topbar) || !isset($nb_alertes_peremption))) {
    $magasin_id_topbar = user_magasin_id();
    
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

// ----- Page courante (pour surligner la navigation) -----
$page_courante = $_GET['_page'] ?? str_replace('.php', '', basename($_SERVER['PHP_SELF']));

// ----- Construction du menu : sections regroupées (pliables via JS) -----
$can_manage_stock = peut_gerer_stock();
$can_bill = peut_facturer();
$can_admin = peut_administrer();

$nav_sections = [];
if (est_connecte()) {
    $sec_pilotage = [
        'key' => 'pilotage', 'titre' => 'Pilotage', 'icon' => 'bi-speedometer2',
        'items' => [['slug' => 'tableau_bord', 'label' => 'Accueil', 'icon' => 'bi-speedometer2', 'color' => 'clr-indigo']],
    ];
    if ($can_admin) {
        $sec_pilotage['items'][] = ['slug' => 'statistiques', 'label' => 'Statistiques', 'icon' => 'bi-graph-up', 'color' => 'clr-indigo'];
    }
    $sec_pilotage['items'][] = ['slug' => 'documentation', 'label' => 'Documentation', 'icon' => 'bi-journal-bookmark', 'color' => 'clr-indigo'];

    $sec_stock = ['key' => 'stock', 'titre' => 'Stock & Achats', 'icon' => 'bi-boxes', 'items' => []];
    if ($can_manage_stock) {
        $sec_stock['items'][] = ['slug' => 'stock', 'label' => 'Stock', 'icon' => 'bi-boxes', 'color' => 'clr-emerald'];
        $sec_stock['items'][] = ['slug' => 'articles', 'label' => 'Articles', 'icon' => 'bi-box-seam', 'color' => 'clr-emerald'];
        if (peut('articles_gerer')) {
            $sec_stock['items'][] = ['slug' => 'categories', 'label' => 'Catégories', 'icon' => 'bi-tags', 'color' => 'clr-emerald'];
        }
        $sec_stock['items'][] = ['slug' => 'fournisseurs', 'label' => 'Fournisseurs', 'icon' => 'bi-truck', 'color' => 'clr-emerald'];
        if (peut('achats_consulter')) {
            $sec_stock['items'][] = ['slug' => 'commandes_fournisseur', 'label' => "Commandes d'achat", 'icon' => 'bi-cart-plus', 'color' => 'clr-emerald'];
        }
        if (peut('inventaire_consulter')) {
            $sec_stock['items'][] = ['slug' => 'inventaire', 'label' => 'Inventaire', 'icon' => 'bi-clipboard-check', 'color' => 'clr-emerald'];
        }
        $sec_stock['items'][] = ['slug' => 'mouvements', 'label' => 'Mouvements', 'icon' => 'bi-arrow-left-right', 'color' => 'clr-emerald'];
        $sec_stock['items'][] = ['slug' => 'peremptions', 'label' => 'Péremptions', 'icon' => 'bi-calendar-week', 'color' => 'clr-emerald', 'badge' => $nb_alertes_peremption];
        $sec_stock['items'][] = ['slug' => 'suggestions_achat', 'label' => "Suggestions d'achat", 'icon' => 'bi-bag-check', 'color' => 'clr-emerald', 'badge' => $nb_alertes_topbar];
        if (in_array($role, [ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_MAGASINIER], true)) {
            $sec_stock['items'][] = ['slug' => 'etiquettes', 'label' => 'Étiquettes rayon', 'icon' => 'bi-tag', 'color' => 'clr-emerald'];
            $sec_stock['items'][] = ['slug' => 'transferts', 'label' => 'Transferts', 'icon' => 'bi-arrow-repeat', 'color' => 'clr-emerald'];
        }
    }

    $sec_ventes = ['key' => 'ventes', 'titre' => 'Ventes & Caisse', 'icon' => 'bi-cash-coin', 'items' => []];
    if ($can_bill) {
        $sec_ventes['items'][] = ['slug' => 'caisse', 'label' => 'Caisse (POS)', 'icon' => 'bi-cash-coin', 'color' => 'clr-sky'];
        $sec_ventes['items'][] = ['slug' => 'factures', 'label' => 'Factures', 'icon' => 'bi-receipt', 'color' => 'clr-sky'];
        if (peut('retours_consulter')) {
            $sec_ventes['items'][] = ['slug' => 'retours', 'label' => 'Retours / SAV', 'icon' => 'bi-arrow-counterclockwise', 'color' => 'clr-sky'];
        }
        if (peut('promotions_consulter')) {
            $sec_ventes['items'][] = ['slug' => 'promotions', 'label' => 'Promotions & Remises', 'icon' => 'bi-percent', 'color' => 'clr-sky'];
        }
        $sec_ventes['items'][] = ['slug' => 'cloture', 'label' => 'Clôture de Caisse', 'icon' => 'bi-lock-fill', 'color' => 'clr-sky'];
    }

    $sec_admin = ['key' => 'admin', 'titre' => 'Administration', 'icon' => 'bi-gear', 'items' => []];
    if ($can_admin) {
        $sec_admin['items'][] = ['slug' => 'depenses', 'label' => 'Dépenses', 'icon' => 'bi-wallet2', 'color' => 'clr-rose'];
        $sec_admin['items'][] = ['slug' => 'utilisateurs', 'label' => 'Utilisateurs', 'icon' => 'bi-people', 'color' => 'clr-violet'];
        $sec_admin['items'][] = ['slug' => 'magasins', 'label' => 'Magasins', 'icon' => 'bi-shop', 'color' => 'clr-violet'];
        $sec_admin['items'][] = ['slug' => 'parametres', 'label' => 'Paramètres', 'icon' => 'bi-gear', 'color' => 'clr-violet'];
    }
    if (peut('audit_consulter')) {
        $sec_admin['items'][] = ['slug' => 'audit', 'label' => "Journal d'activité", 'icon' => 'bi-clock-history', 'color' => 'clr-slate'];
    }

    foreach ([$sec_pilotage, $sec_stock, $sec_ventes, $sec_admin] as $sec) {
        if (!empty($sec['items'])) {
            $nav_sections[] = $sec;
        }
    }
}
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
    <link rel="manifest" href="<?= BASE_URL ?>manifest.php">
    <meta name="theme-color" content="#4f46e5">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
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

<script nonce="<?= h(csp_nonce()) ?>">window.BASE_URL = <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php if (est_connecte()): ?>
<div class="app-shell">

    <!-- ============ SIDEBAR ============ -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <img src="<?= BASE_URL ?>assets/images/logo-eStock-2.png" alt="Logo <?= h($app_nom) ?>" class="logo-img">
            <span class="brand-text"><?= h($app_nom) ?></span>
        </div>

        <nav class="sidebar-nav">
            <?php foreach ($nav_sections as $sec):
                $page_actuelle_dans = in_array($page_courante, array_column($sec['items'], 'slug'), true);
            ?>
            <div class="nav-section <?= $page_actuelle_dans ? 'open' : '' ?>" data-section="<?= h($sec['key']) ?>">
                <button type="button" class="nav-section-head" aria-expanded="<?= $page_actuelle_dans ? 'true' : 'false' ?>">
                    <span class="nav-section-title"><i class="bi <?= h($sec['icon']) ?>"></i> <?= h($sec['titre']) ?></span>
                    <i class="bi bi-chevron-down nav-section-chevron"></i>
                </button>
                <div class="nav-section-body">
                    <?php foreach ($sec['items'] as $item): ?>
                        <a class="nav-link <?= $page_courante === $item['slug'] ? 'active' : '' ?>"
                           href="<?= $item['slug'] . '.php' ?>">
                            <span class="nav-icon <?= $item['color'] ?>"><i class="bi <?= $item['icon'] ?>"></i></span>
                            <span><?= h($item['label']) ?></span>
                            <?php if (!empty($item['badge']) && (int)$item['badge'] > 0): ?>
                                <span class="badge bg-danger ms-auto" style="font-size:.65rem;"><?= (int)$item['badge'] ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
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
                <?php if (user_role() === ROLE_DIRECTEUR && function_exists('db_magasins_list')): 
                    $magasins_sel = db_magasins_list($pdo);
                    $retour_page = basename($_SERVER['PHP_SELF']);
                ?>
                <div class="dropdown">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" title="Magasin de suivi">
                        <i class="bi bi-shop"></i>
                        <span class="d-none d-lg-inline ms-1"><?= h(user_magasin_nom($pdo)) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><span class="dropdown-header"><i class="bi bi-shop"></i> Magasin de suivi</span></li>
                        <?php foreach ($magasins_sel as $m): ?>
                        <li>
                            <form method="post" action="auth/choisir_magasin.php" class="m-0">
                                <?= csrf_field() ?>
                                <input type="hidden" name="magasin_id" value="<?= (int)$m['id'] ?>">
                                <input type="hidden" name="retour" value="<?= h($retour_page) ?>">
                                <button type="submit" class="dropdown-item">
                                    <i class="bi bi-<?= user_magasin_id() === (int)$m['id'] ? 'check-circle-fill text-success' : 'circle' ?> me-1"></i>
                                    <?= h($m['nom']) ?>
                                </button>
                            </form>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <?php if (peut_facturer()): ?>
                    <a href="<?= 'caisse.php' ?>" class="btn btn-success btn-sm"><i class="bi bi-cart-check"></i> Caisse</a>
                <?php endif; ?>
                <?php if (peut_gerer_stock()): ?>
                    <a href="suggestions_achat.php" class="btn btn-sm position-relative <?= $nb_alertes_topbar > 0 ? 'btn-outline-warning' : 'btn-outline-secondary' ?>" title="Alertes stock">
                        <i class="bi bi-bell"></i>
                        <?php if ($nb_alertes_topbar > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?= $nb_alertes_topbar ?>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
                <?php if (peut('stock_consulter') && $nb_alertes_peremption > 0): ?>
                    <a href="peremptions.php" class="btn btn-sm btn-outline-danger position-relative" title="Alertes péremption">
                        <i class="bi bi-calendar-week"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?= $nb_alertes_peremption ?>
                        </span>
                    </a>
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