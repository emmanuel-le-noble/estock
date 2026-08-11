<?php
/**
 * retours.php — Gestion des retours d'articles et avoirs SAV.
 * Actions : liste | nouveau | chercher_facture | enregistrer | detail
 */
if (!function_exists('est_connecte'))    { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard'))      { require_once __DIR__ . '/includes/helpers.php'; }

exiger_permission('retours_consulter');

$action     = $_GET['action'] ?? 'liste';
$magasin_id = user_magasin_id();
$user       = user_courant();
$userId     = (int)($user['id'] ?? 0);

// ---- Enregistrer un retour (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'enregistrer') {
    csrf_guard('retours.php');
    exiger_permission('retours_gerer');

    $facture_id = (int)($_POST['facture_id'] ?? 0);
    $motif      = trim($_POST['motif'] ?? '');
    $lignes_post = $_POST['lignes'] ?? [];

    if ($facture_id <= 0 || empty($lignes_post) || !is_array($lignes_post)) {
        flash_error('Veuillez sélectionner au moins un article à retourner.');
        redirect('retours.php?action=nouveau');
    }

    $facture = db_facture_get_by_id($pdo, $facture_id);
    if (!$facture || $facture['statut'] !== 'Payee') {
        flash_error('Facture introuvable ou invalide pour un retour.');
        redirect('retours.php');
    }

    // Filtrer les lignes ayant une quantité > 0
    $lignesRetour = [];
    foreach ($lignes_post as $lf_id => $row) {
        $qte = (int)($row['quantite'] ?? 0);
        if ($qte > 0) {
            $lignesRetour[] = [
                'ligne_facture_id' => (int)$lf_id,
                'article_id'       => (int)($row['article_id'] ?? 0),
                'quantite'         => $qte,
                'prix_unitaire'    => (float)($row['prix_unitaire'] ?? 0),
            ];
        }
    }

    if (empty($lignesRetour)) {
        flash_error('Aucune quantité valide saisie pour le retour.');
        redirect(generate_signed_url('retours.php', $facture_id, ['action' => 'nouveau_facture']));
    }

    $retourId = 0;
    db_transaction(
        function(PDO $pdo) use ($facture_id, $lignesRetour, $motif, $userId, $magasin_id, &$retourId) {
            $retourId = db_retour_creer($pdo, $facture_id, $lignesRetour, $motif, $userId, $magasin_id);
        },
        'Retour enregistré et stock mis à jour.',
        'Erreur lors de l\'enregistrement du retour.',
        'retours.php'
    );

    suivre_activite('RETOUR_SAV', 'Création retour #' . $retourId . ' sur facture #' . $facture['numero_facture']);
    flash_success('Retour enregistré avec succès.');
    redirect(generate_signed_url('retours.php', $retourId, ['action' => 'detail']));
}

// ---- Détail d'un retour ----
if ($action === 'detail') {
    $get_id = (int)($_GET['id'] ?? 0);
    $token  = $_GET['token'] ?? '';
    if ($get_id <= 0 || !verify_url_signature($get_id, $token, ['action' => 'detail'])) {
        flash_error('Lien invalide ou expiré.'); redirect('retours.php');
    }

    $retour = db_retour_get_by_id($pdo, $get_id);
    if (!$retour) { flash_error('Retour introuvable.'); redirect('retours.php'); }

    $lignes = db_retour_lignes_get($pdo, $get_id);

    echo $twig->render('retours.html.twig', [
        'view'   => 'detail',
        'retour' => $retour,
        'lignes' => $lignes,
        'devise' => param('devise_symbole', 'FCFA'),
    ]);
    exit;
}

// ---- Formulaire nouveau retour pour une facture spécifique ----
if ($action === 'nouveau_facture') {
    $facture_id = (int)($_GET['id'] ?? 0);
    $facture    = db_facture_get_by_id($pdo, $facture_id);
    if (!$facture || $facture['statut'] !== 'Payee') {
        flash_error('Facture introuvable ou non éligible.'); redirect('retours.php');
    }
    $lignes = db_facture_get_lignes($pdo, $facture_id);

    echo $twig->render('retours.html.twig', [
        'view'    => 'nouveau',
        'facture' => $facture,
        'lignes'  => $lignes,
        'devise'  => param('devise_symbole', 'FCFA'),
    ]);
    exit;
}

// ---- Chercher une facture par numéro ----
if ($action === 'nouveau') {
    $num_search = trim($_GET['num'] ?? '');
    $facture    = null;
    $lignes     = [];

    if ($num_search !== '') {
        $stmt = $pdo->prepare("SELECT * FROM factures WHERE numero_facture = ? AND statut = 'Payee'");
        $stmt->execute([$num_search]);
        $facture = $stmt->fetch() ?: null;
        if ($facture) {
            $lignes = db_facture_get_lignes($pdo, (int)$facture['id']);
        } else {
            flash_error('Aucune facture payée trouvée avec le numéro ' . h($num_search));
        }
    }

    echo $twig->render('retours.html.twig', [
        'view'       => 'nouveau',
        'num_search' => $num_search,
        'facture'    => $facture,
        'lignes'     => $lignes,
        'devise'     => param('devise_symbole', 'FCFA'),
    ]);
    exit;
}

// ---- Liste des retours ----
$filters = [
    'magasin_id' => $magasin_id,
    'search'     => $_GET['search'] ?? '',
];
$built = db_retours_search_sql($filters);
$page  = max(1, (int)($_GET['page'] ?? 1));
$pagination = paginate($pdo, $built['sql'], $built['params'], $page, 15);

echo $twig->render('retours.html.twig', [
    'view'        => 'liste',
    'rows'        => $pagination['data'],
    'pagination'  => $pagination,
    'filters'     => $filters,
    'devise'      => param('devise_symbole', 'FCFA'),
    'peut_gerer'  => peut('retours_gerer'),
]);
