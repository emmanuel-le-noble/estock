<?php
/**
 * inventaire.php — Gestion des inventaires physiques (comptage cyclique).
 * Actions : liste | nouveau | detail | saisie (AJAX JSON) | valider | annuler
 */
if (!function_exists('est_connecte'))    { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard'))      { require_once __DIR__ . '/includes/helpers.php'; }

exiger_permission('inventaire_consulter');

$action     = $_GET['action'] ?? 'liste';
$magasin_id = user_magasin_id();
$user       = user_courant();
$userId     = (int)($user['id'] ?? 0);

// ---- Créer une nouvelle session ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'nouveau') {
    csrf_guard('inventaire.php');
    exiger_permission('inventaire_gerer');

    $data = extract_post_data([
        'notes'      => ['type' => 'string', 'nullable' => true, 'max' => 500],
        'magasin_id' => ['type' => 'int', 'default' => $magasin_id],
    ], 'inventaire.php');

    $data['magasin_id'] = $magasin_id;

    db_transaction(
        function(PDO $pdo) use ($data, $userId, &$newId) {
            $ref   = generate_inventory_ref($pdo);
            $newId = db_inventaire_insert($pdo, [
                'reference'      => $ref,
                'magasin_id'     => (int)$data['magasin_id'],
                'utilisateur_id' => $userId,
                'notes'          => $data['notes'] ?? null,
            ]);
        },
        'Session d\'inventaire créée.',
        'Erreur lors de la création.',
        'inventaire.php'
    );
    suivre_activite('INVENTAIRE_CREATION', 'Nouvelle session #' . ($newId ?? '?'));
    redirect(generate_signed_url('inventaire.php', $newId, ['action' => 'detail']));
}

// ---- Saisie d'une ligne (appel AJAX POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saisie') {
    exiger_permission('inventaire_gerer');
    header('Content-Type: application/json');

    $csrfOk = verify_csrf_token($_POST['csrf_token'] ?? '');
    if (!$csrfOk) { echo json_encode(['ok' => false, 'msg' => 'CSRF invalide']); exit; }

    $inv_id      = (int)($_POST['inventaire_id'] ?? 0);
    $article_id  = (int)($_POST['article_id'] ?? 0);
    $qte_comptee = (int)($_POST['quantite_comptee'] ?? 0);
    $notes       = mb_substr(input_string($_POST['notes'] ?? ''), 0, 255);

    if ($inv_id <= 0 || $article_id <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Paramètres invalides']); exit;
    }
    $inv = db_inventaire_get_by_id($pdo, $inv_id);
    if (!$inv || $inv['statut'] !== 'En cours' || (int)$inv['magasin_id'] !== $magasin_id) {
        echo json_encode(['ok' => false, 'msg' => 'Session non active']); exit;
    }

    $art = db_article_get_for_update($pdo, $article_id, (int)($inv['magasin_id']));
    if (!$art) { echo json_encode(['ok' => false, 'msg' => 'Article introuvable']); exit; }

    $qte_theorique = (int)($art['quantite_stock'] ?? 0);
    db_inventaire_ligne_upsert($pdo, $inv_id, $article_id, $qte_comptee, $qte_theorique, $notes ?: null);

    echo json_encode([
        'ok'     => true,
        'ecart'  => $qte_comptee - $qte_theorique,
        'theor'  => $qte_theorique,
        'msg'    => 'Ligne enregistrée',
    ]);
    exit;
}

// ---- Valider l'inventaire (appliquer écarts) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'valider') {
    csrf_guard('inventaire.php');
    exiger_permission('inventaire_gerer');

    $inv_id = (int)($_POST['id'] ?? 0);
    $inv    = db_inventaire_get_by_id($pdo, $inv_id);
    if (!$inv || $inv['statut'] !== 'En cours' || (int)$inv['magasin_id'] !== $magasin_id) {
        flash_error('Inventaire introuvable ou déjà clôturé.');
        redirect('inventaire.php');
    }

    db_transaction(
        function(PDO $pdo) use ($inv_id, $userId) {
            db_inventaire_appliquer_ecarts($pdo, $inv_id, $userId);
            db_inventaire_update_statut($pdo, $inv_id, 'Validé');
        },
        'Inventaire validé — stock mis à jour.',
        'Erreur lors de la validation.',
        generate_signed_url('inventaire.php', $inv_id, ['action' => 'detail'])
    );
    suivre_activite('INVENTAIRE_VALIDATION', 'Validation inventaire #' . $inv_id);
    redirect(generate_signed_url('inventaire.php', $inv_id, ['action' => 'detail']));
}

// ---- Annuler une session ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'annuler') {
    csrf_guard('inventaire.php');
    exiger_permission('inventaire_gerer');
    $inv_id = (int)($_POST['id'] ?? 0);
    $inv    = db_inventaire_get_by_id($pdo, $inv_id);
    if (!$inv || (int)$inv['magasin_id'] !== $magasin_id) {
        flash_error('Session introuvable ou ne concernant pas votre magasin.');
        redirect('inventaire.php');
    }
    db_inventaire_update_statut($pdo, $inv_id, 'Annulé');
    suivre_activite('INVENTAIRE_ANNULATION', 'Annulation inventaire #' . $inv_id);
    flash_success('Inventaire annulé.');
    redirect('inventaire.php');
}

// ---- Vue détail d'une session ----
if ($action === 'detail') {
    $get_id = (int)($_GET['id'] ?? 0);
    $token  = input_string($_GET['token'] ?? '');
    if ($get_id <= 0 || !verify_url_signature($get_id, $token, ['action' => 'detail'])) {
        flash_error('Lien invalide ou expiré.'); redirect('inventaire.php');
    }
    $inv    = db_inventaire_get_by_id($pdo, $get_id);
    if (!$inv || (int)$inv['magasin_id'] !== $magasin_id) { flash_error('Inventaire introuvable.'); redirect('inventaire.php'); }

    $lignes    = db_inventaire_lignes_get($pdo, $get_id);
    $articles  = db_articles_search_stock($pdo, '', 1000, $magasin_id);
    $magasins  = function_exists('db_magasins_list') ? db_magasins_list($pdo) : [];

    echo $twig->render('inventaire.html.twig', [
        'view'     => 'detail',
        'titre_page' => "Inventaire {$inv['reference']}",
        'inv'      => $inv,
        'lignes'   => $lignes,
        'articles' => $articles,
        'magasins' => $magasins,
        'signed_url' => generate_signed_url('inventaire.php', $get_id, ['action' => 'detail']),
    ]);
    exit;
}

// ---- Liste paginée ----
$filters = [
    'statut'     => $_GET['statut'] ?? '',
    'magasin_id' => $magasin_id,
    'search'     => $_GET['search'] ?? '',
];
$built = db_inventaires_search_sql($filters);
$pagination = paginate($built['sql'], $built['params'], 15);

echo $twig->render('inventaire.html.twig', [
    'view'         => 'liste',
    'titre_page'   => 'Inventaire',
    'rows'         => $pagination['data'],
    'pagination'   => $pagination,
    'filters'      => $filters,
    'magasins'     => function_exists('db_magasins_list') ? db_magasins_list($pdo) : [],
    'peut_gerer'   => peut('inventaire_gerer'),
]);
