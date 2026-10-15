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

// ----- Monogramme pour l'avatar du compte -----
$nom_parts = preg_split('/\s+/u', trim((string)($user['nom'] ?? 'U')), -1, PREG_SPLIT_NO_EMPTY) ?: ['U'];
$initiale = mb_strtoupper(
    mb_substr($nom_parts[0], 0, 1, 'UTF-8') . mb_substr($nom_parts[1] ?? '', 0, 1, 'UTF-8'),
    'UTF-8'
);

// ----- Page courante (pour surligner la navigation) -----
$page_route = str_replace('.php', '', basename($_SERVER['PHP_SELF']));
$page_courante = $_GET['_page'] ?? ($page_route === 'parametres' && ($_GET['tab'] ?? '') === 'droits' ? 'droits' : $page_route);

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
        if (peut('clients_consulter')) {
            $sec_ventes['items'][] = ['slug' => 'clients', 'label' => 'Clients & fidélité', 'icon' => 'bi-people', 'color' => 'clr-sky'];
        }
    }

    $sec_admin = ['key' => 'admin', 'titre' => 'Administration', 'icon' => 'bi-gear', 'items' => []];
    if ($can_admin) {
        $sec_admin['items'][] = ['slug' => 'depenses', 'label' => 'Dépenses', 'icon' => 'bi-wallet2', 'color' => 'clr-rose'];
        $sec_admin['items'][] = ['slug' => 'utilisateurs', 'label' => 'Utilisateurs', 'icon' => 'bi-people', 'color' => 'clr-violet'];
        $sec_admin['items'][] = ['slug' => 'magasins', 'label' => 'Magasins', 'icon' => 'bi-shop', 'color' => 'clr-violet'];
        $sec_admin['items'][] = ['slug' => 'parametres', 'label' => 'Paramètres', 'icon' => 'bi-gear', 'color' => 'clr-violet'];
        if (peut('utilisateurs_gerer')) {
            $sec_admin['items'][] = ['slug' => 'droits', 'href' => 'parametres.php?tab=droits', 'label' => 'Droits d’utilisation', 'icon' => 'bi-shield-lock', 'color' => 'clr-violet'];
        }
    }
        if (peut('audit_consulter')) {
            $sec_admin['items'][] = ['slug' => 'audit', 'label' => "Journal d'activité", 'icon' => 'bi-clock-history', 'color' => 'clr-slate'];
        }
        if (peut('audit_consulter')) {
            $sec_admin['items'][] = ['slug' => 'conformite', 'label' => 'Traçabilité des ventes', 'icon' => 'bi-shield-check', 'color' => 'clr-slate'];
        }

    foreach ([$sec_pilotage, $sec_stock, $sec_ventes, $sec_admin] as $sec) {
        if (!empty($sec['items'])) {
            $nav_sections[] = $sec;
        }
    }
}
$theme_palettes = [
    'indigo' => ['#155bd0', '#1049ad', '#edf4ff'],
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
    <meta name="theme-color" content="#155bd0">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title><?= h($titre_page) ?> · <?= h($app_nom) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="<?= BASE_URL ?>assets/images/logo-eStock.ico">
    <link href="<?= BASE_URL ?>assets/css/style.css?v=13" rel="stylesheet">
    <style>
        :root {
            --brand: <?= h($theme_vars[0]) ?>;
            --brand-2: <?= h($theme_vars[1]) ?>;
            --brand-soft: <?= h($theme_vars[2]) ?>;
        }
    </style>
</head>
<body>

<canvas id="particleCanvas" aria-hidden="true"></canvas>

<script nonce="<?= h(csp_nonce()) ?>">window.BASE_URL = <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php if (est_connecte()): ?>
<div class="app-shell">
    <header class="app-header">
        <div class="app-header-main">
            <a class="app-brand" href="tableau_bord.php" aria-label="<?= h($app_nom) ?>, accueil">
                <span class="app-brand-mark"><img src="<?= BASE_URL ?>assets/images/logo-eStock-3.png" alt=""></span>
                <span class="app-brand-copy"><strong><?= h($app_nom) ?></strong><small>Gestion commerciale</small></span>
            </a>
            <button class="burger" id="burger" type="button" aria-label="Ouvrir la navigation" aria-expanded="false" aria-controls="sidebar">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>
            <div class="app-header-tools">
                <span id="connectionBadge" class="connection-status badge-offline badge-online" aria-label="État de connexion"></span>
                <?php if (user_role() === ROLE_DIRECTEUR && function_exists('db_magasins_list')): 
                    $magasins_sel = db_magasins_list($pdo);
                    $retour_page = basename($_SERVER['PHP_SELF']);
                ?>
                <div class="dropdown">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle store-switcher" data-bs-toggle="dropdown" title="Magasin de suivi">
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
                    <a href="<?= 'caisse.php' ?>" class="btn btn-success btn-sm quick-pos"><i class="bi bi-cart-check"></i><span>Caisse</span></a>
                <?php endif; ?>
                <?php if (peut_gerer_stock()): ?>
                    <a href="suggestions_achat.php" aria-label="Alertes de stock" class="btn btn-sm position-relative tool-icon <?= $nb_alertes_topbar > 0 ? 'btn-outline-warning' : 'btn-outline-secondary' ?>" title="Alertes stock">
                        <i class="bi bi-bell"></i>
                        <?php if ($nb_alertes_topbar > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?= $nb_alertes_topbar ?>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
                <?php if (peut('stock_consulter') && $nb_alertes_peremption > 0): ?>
                    <a href="peremptions.php" aria-label="Alertes de péremption" class="btn btn-sm btn-outline-danger position-relative tool-icon" title="Alertes péremption">
                        <i class="bi bi-calendar-week"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?= $nb_alertes_peremption ?>
                        </span>
                    </a>
                <?php endif; ?>
                <a href="profil.php" class="account-link" title="Mon profil" aria-label="Profil de <?= h($user['nom'] ?? '') ?>, rôle <?= h($role) ?>">
                    <span class="avatar avatar-initial"><?= h(strtoupper($initiale)) ?></span>
                    <span class="account-meta"><strong><?= h($user['nom'] ?? '') ?></strong><small><i class="bi bi-person-badge" aria-hidden="true"></i><?= h($role) ?></small></span>
                    <i class="bi bi-chevron-right account-caret" aria-hidden="true"></i>
                </a>
            </div>
            <form method="POST" action="auth/logout.php" class="logout-form">
                <?= csrf_field() ?>
                <button type="submit" class="logout-button" title="Se déconnecter" aria-label="Se déconnecter"><i class="bi bi-box-arrow-right"></i></button>
            </form>
        </div>

    </header>

    <nav class="primary-nav" id="sidebar" aria-label="Navigation principale">
            <div class="primary-nav-inner">
                <div class="nav-caption" aria-hidden="true">Espace de travail</div>
                <a href="tableau_bord.php" class="nav-home <?= $page_courante === 'tableau_bord' ? 'active' : '' ?>" <?= $page_courante === 'tableau_bord' ? 'aria-current="page"' : '' ?>>
                    <i class="bi bi-house-door"></i><span>Accueil</span>
                </a>
                <?php foreach ($nav_sections as $sec):
                    $section_active = $page_courante !== 'tableau_bord' && in_array($page_courante, array_column($sec['items'], 'slug'), true);
                ?>
                <div class="nav-section <?= $section_active ? 'has-active' : '' ?>" data-section="<?= h($sec['key']) ?>">
                    <button type="button" class="nav-section-head" aria-expanded="false" aria-controls="nav-menu-<?= h($sec['key']) ?>">
                        <span class="nav-section-title"><i class="bi <?= h($sec['icon']) ?>"></i><span><?= h($sec['titre']) ?></span></span>
                        <i class="bi bi-chevron-down nav-section-chevron" aria-hidden="true"></i>
                    </button>
                    <div class="nav-section-body" id="nav-menu-<?= h($sec['key']) ?>">
                        <span class="nav-menu-caption"><?= h($sec['titre']) ?></span>
                        <?php foreach ($sec['items'] as $item): if ($item['slug'] === 'tableau_bord') continue; ?>
                            <a class="nav-link <?= $page_courante === $item['slug'] ? 'active' : '' ?>"
                               href="<?= h($item['href'] ?? ($item['slug'] . '.php')) ?>" <?= $page_courante === $item['slug'] ? 'aria-current="page"' : '' ?>>
                                <span class="nav-icon <?= $item['color'] ?>"><i class="bi <?= $item['icon'] ?>" aria-hidden="true"></i></span>
                                <span><?= h($item['label']) ?></span>
                                <?php if (!empty($item['badge']) && (int)$item['badge'] > 0): ?>
                                    <span class="badge bg-danger ms-auto"><?= (int)$item['badge'] ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
    </nav>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="app-main" id="appMain">
        <div class="page-heading">
            <div class="page-heading-crumb"><i class="bi bi-grid-1x2"></i><span><?= h($app_nom) ?></span><i class="bi bi-chevron-right" aria-hidden="true"></i><strong><?= h($titre_page) ?></strong></div>
            <div class="page-heading-row">
                <div><h1><?= h($titre_page) ?></h1><?php if ($sous_titre): ?><p><?= h($sous_titre) ?></p><?php endif; ?></div>
                <time class="page-date" datetime="<?= h(date('Y-m-d')) ?>"><i class="bi bi-calendar3"></i><span><?= h(date_fr(date('Y-m-d'))) ?></span></time>
            </div>
        </div>

        <main class="app-content" id="appContent">
<?php else: ?>
<main class="app-content">
<?php endif; ?>
