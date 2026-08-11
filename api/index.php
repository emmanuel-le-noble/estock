<?php
/**
 * api/index.php — Routeur API REST centralisé pour eStock.
 *
 * Point d'entrée unique : toutes les requêtes /api/* passent par ici.
 * Les réponses sont toujours en JSON avec le code HTTP approprié.
 */

// ============================================================
//  BOOTSTRAP
// ============================================================
require_once __DIR__ . '/../config/connexion.php';
require_once __DIR__ . '/../includes/db_functions.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

// ============================================================
//  DISPATCHER
// ============================================================

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$path   = preg_replace('#^' . preg_quote($base) . '#', '', $uri);
$path   = trim($path, '/');
$path   = preg_replace('#^api/#', '', $path);
$method = $_SERVER['REQUEST_METHOD'];

$segments = array_values(array_filter(explode('/', $path)));
$resource = $segments[0] ?? '';
$id       = isset($segments[1]) ? (int)$segments[1] : 0;

match (true) {
    // --- Auth ---
    $resource === 'login' && $method === 'POST'
        => handle_login(),
    $resource === 'logout' && $method === 'POST'
        => handle_logout(),
    $resource === 'me' && $method === 'GET'
        => handle_me(),

    // --- Articles ---
    $resource === 'articles' && $method === 'GET'
        => handle_articles_list(),
    $resource === 'articles' && $method === 'POST'
        => handle_article_create(),
    $resource === 'articles' && $id > 0 && $method === 'PUT'
        => handle_article_update($id),
    $resource === 'articles' && $id > 0 && $method === 'DELETE'
        => handle_article_delete($id),

    // --- Scan (code-barres) ---
    $resource === 'scan' && $method === 'GET'
        => handle_scan(),

    // --- Caisse hors-ligne ---
    $resource === 'caisse' && $method === 'POST'
        && isset($segments[1]) && $segments[1] === 'sync'
        => handle_caisse_sync(),

    // --- État de la caisse ---
    $resource === 'caisse' && $method === 'GET'
        && isset($segments[1]) && $segments[1] === 'status'
        => handle_caisse_status(),

    // --- Générateur code-barres EAN-13 ---
    $resource === 'generer_code_barre' && $method === 'GET'
        => handle_generer_code_barre(),

    // --- Code promo ---
    $resource === 'promo' && $method === 'GET'
        => handle_promo_check(),

    // --- 404 ---
    default
        => json_out(['error' => 'Route introuvable.'], 404),
};

// ============================================================
//  AUTH HANDLERS
// ============================================================

function handle_login(): void {
    // SEC-11 : Vérifier que la requête vient d'une interface interne (pas cross-origin)
    $isXHR = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    $isAPI = !empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json');
    if (!$isXHR && !$isAPI) {
        json_out(['error' => 'Requête non autorisée.'], 403);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];

    $login = trim($input['login'] ?? $_POST['login'] ?? '');
    $mdp   = $input['mot_de_passe'] ?? $_POST['mot_de_passe'] ?? '';

    if ($login === '' || $mdp === '') {
        json_out(['success' => false, 'message' => 'Identifiants requis.'], 422);
    }

    if (login_rate_limited()) {
        suivre_activite('ECHEC_CONNEXION_API', 'Rate limited depuis ' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
        json_out(['success' => false, 'message' => 'Trop de tentatives. Réessayez dans 15 minutes.'], 429);
    }

    global $pdo;
    $u = db_user_get_by_login($pdo, $login);

    if ($u && password_verify($mdp, $u['mot_de_passe'])) {
        db_login_attempt_clear($pdo, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $login);
        session_regenerate_id(true);
        unset($u['mot_de_passe']);
        $_SESSION['user'] = $u;
        suivre_activite('CONNEXION_API', 'Connexion API réussie: ' . $login);
        json_out([
            'success' => true,
            'message' => 'Connexion réussie.',
            'user'    => $u,
        ]);
    } else {
        db_login_attempt_insert($pdo, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $login);
        suivre_activite('ECHEC_CONNEXION_API', 'Tentative échouée: ' . $login);
        json_out(['success' => false, 'message' => 'Identifiant ou mot de passe incorrect.'], 401);
    }
}

function handle_logout(): void {
    // SEC-12 : Vérifier le token CSRF pour le logout
    $csrfToken = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrfToken) || !hash_equals(csrf_token(), $csrfToken)) {
        json_out(['error' => 'Token CSRF invalide.'], 403);
    }

    if (!est_connecte()) {
        json_out(['success' => false, 'message' => 'Déjà déconnecté.'], 401);
    }
    $login = user_courant()['login'] ?? 'unknown';
    suivre_activite('DECONNEXION_API', 'Déconnexion API: ' . $login);
    session_destroy();
    json_out(['success' => true, 'message' => 'Déconnecté.']);
}

function handle_me(): void {
    if (!est_connecte()) {
        json_out(['error' => 'Non autorisé. Connectez-vous.'], 401);
    }
    $user = user_courant();
    unset($user['mot_de_passe']);
    json_out(['user' => $user]);
}

// ============================================================
//  ARTICLES HANDLERS
// ============================================================

function handle_articles_list(): void {
    if (!est_connecte()) {
        json_out(['error' => 'Non autorisé. Connectez-vous.'], 401);
    }
    if (!peut('articles_consulter')) {
        json_out(['error' => 'Accès refusé. Droits insuffisants.'], 403);
    }

    global $pdo;
    $search    = trim($_GET['q'] ?? '');
    $search_sql = db_articles_search_sql($search);
    $stmt = $pdo->prepare($search_sql['sql']);
    $stmt->execute($search_sql['params']);
    $articles = $stmt->fetchAll();

    // NOU-7 : Ajouter l'URL signée pour chaque article
    foreach ($articles as &$a) {
        $a['edit_url'] = generate_signed_url('articles.php', (int)$a['id'], ['action' => 'editer']);
    }
    unset($a);

    json_out([
        'data'  => $articles,
        'total' => count($articles),
    ]);
}

function handle_article_create(): void {
    if (!est_connecte()) {
        json_out(['error' => 'Non autorisé. Connectez-vous.'], 401);
    }
    if (!peut_administrer()) {
        json_out(['error' => 'Accès refusé. Droits insuffisants.'], 403);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];

    $code_barre    = trim($input['code_barre'] ?? '');
    $nom           = trim($input['nom'] ?? '');
    $sku           = trim($input['sku'] ?? '');

    if ($code_barre === '' || $nom === '') {
        json_out(['error' => 'Les champs code_barre et nom sont obligatoires.'], 422);
    }

    global $pdo;

    if (db_article_code_barre_exists($pdo, $code_barre)) {
        json_out(['error' => 'Ce code-barres existe déjà.'], 409);
    }

    $data = [
        'code_barre'     => $code_barre,
        'nom'            => $nom,
        'sku'            => $sku,
        'prix_achat'     => (float)($input['prix_achat'] ?? 0),
        'prix_vente'     => (float)($input['prix_vente'] ?? 0),
        'quantite_stock' => (int)($input['quantite_stock'] ?? 0),
        'seuil_alerte'   => (int)($input['seuil_alerte'] ?? 5),
        'emplacement'    => trim($input['emplacement'] ?? ''),
        'fournisseur_id' => (int)($input['fournisseur_id'] ?? 0) ?: null,
    ];

    try {
        $pdo->beginTransaction();
        $new_id = db_article_insert($pdo, $data);
        // db_article_insert() initialise déjà le stock via db_stock_magasin_init_for_article().
        // Aucun mouvement 'Entree' supplémentaire ne doit être créé ici.
        $pdo->commit();
        suivre_activite('MODIFICATION_ARTICLE', 'Création article #' . $new_id . ' via API — ' . $nom);
        json_out(['success' => true, 'id' => $new_id, 'message' => 'Article créé.'], 201);
    } catch (\Throwable $e) {
        $pdo->rollBack();
        error_log('API article create error: ' . $e->getMessage());
        json_out(['error' => 'Erreur lors de la création.'], 500);
    }
}

function handle_article_update(int $id): void {
    if (!est_connecte()) {
        json_out(['error' => 'Non autorisé. Connectez-vous.'], 401);
    }
    if (!peut_administrer()) {
        json_out(['error' => 'Accès refusé. Droits insuffisants.'], 403);
    }

    global $pdo;
    $existing = db_article_get_by_id($pdo, $id);
    if (!$existing) {
        json_out(['error' => 'Article introuvable.'], 404);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];

    $code_barre = trim($input['code_barre'] ?? $existing['code_barre']);
    $nom        = trim($input['nom'] ?? $existing['nom']);

    if ($code_barre === '' || $nom === '') {
        json_out(['error' => 'Les champs code_barre et nom sont obligatoires.'], 422);
    }

    if (db_article_code_barre_exists($pdo, $code_barre, $id)) {
        json_out(['error' => 'Ce code-barres existe déjà pour un autre article.'], 409);
    }

    $data = [
        'code_barre'     => $code_barre,
        'nom'            => $nom,
        'sku'            => trim($input['sku'] ?? $existing['sku']),
        'prix_achat'     => (float)($input['prix_achat'] ?? $existing['prix_achat']),
        'prix_vente'     => (float)($input['prix_vente'] ?? $existing['prix_vente']),
        'quantite_stock' => (int)($input['quantite_stock'] ?? $existing['quantite_stock']),
        'seuil_alerte'   => (int)($input['seuil_alerte'] ?? $existing['seuil_alerte']),
        'emplacement'    => trim($input['emplacement'] ?? $existing['emplacement']),
        'fournisseur_id' => isset($input['fournisseur_id']) ? ((int)$input['fournisseur_id'] ?: null) : $existing['fournisseur_id'],
    ];

    try {
        db_article_update($pdo, $id, $data);
        suivre_activite('MODIFICATION_ARTICLE', 'Modification article #' . $id . ' via API — ' . $nom);
        json_out(['success' => true, 'message' => 'Article mis à jour.']);
    } catch (\Throwable $e) {
        error_log('API article update error: ' . $e->getMessage());
        json_out(['error' => 'Erreur lors de la mise à jour.'], 500);
    }
}

function handle_article_delete(int $id): void {
    if (!est_connecte()) {
        json_out(['error' => 'Non autorisé. Connectez-vous.'], 401);
    }
    if (!peut_administrer()) {
        json_out(['error' => 'Accès refusé. Droits insuffisants.'], 403);
    }

    global $pdo;
    $existing = db_article_get_by_id($pdo, $id);
    if (!$existing) {
        json_out(['error' => 'Article introuvable.'], 404);
    }

    db_article_deactivate($pdo, $id);
    suivre_activite('SUPPRESSION_ARTICLE', 'Désactivation article #' . $id . ' via API');
    json_out(['success' => true, 'message' => 'Article désactivé.']);
}

// ============================================================
//  SCAN (code-barres)
// ============================================================

function handle_scan(): void {
    if (!est_connecte()) {
        json_out(['error' => 'Non autorisé. Connectez-vous.'], 401);
    }
    if (!peut('caisse_gerer')) {
        json_out(['error' => 'Accès refusé. Droits insuffisants.'], 403);
    }

    $code = trim($_GET['code'] ?? '');
    if ($code === '') {
        json_out(['found' => false, 'message' => 'Paramètre "code" requis.'], 422);
    }

    global $pdo;
    $u           = user_courant();
    $magasin_id  = (int)($u['magasin_id'] ?? 0);
    $article     = db_article_get_by_barcode($pdo, $code, $magasin_id);

    if (!$article) {
        json_out([
            'found'   => false,
            'code'    => $code,
            'message' => 'Aucun article trouvé pour ce code-barres.',
        ]);
    }

    $response = [];
    $alerte = '';
    if ((int)$article['quantite_stock'] <= 0) {
        $alerte = 'Stock épuisé !';
    } elseif ((int)$article['quantite_stock'] <= (int)$article['seuil_alerte']) {
        $alerte = 'Stock faible (' . (int)$article['quantite_stock'] . ' restant).';
    }

    if ($magasin_id > 0 && isset($article['id'])) {
        $lot_fefo = db_lot_get_fefo($pdo, (int)$article['id'], $magasin_id);
        if ($lot_fefo && !empty($lot_fefo['date_peremption'])) {
            $date_peremption = new DateTime((string)$lot_fefo['date_peremption']);
            $aujourd_hui = new DateTime('today');
            $jours = (int)$aujourd_hui->diff($date_peremption)->days;
            
            if ($date_peremption < $aujourd_hui || $jours <= 7) {
                $response['alerte_peremption'] = [
                    'numero_lot'     => $lot_fefo['numero_lot'],
                    'date_peremption'=> $lot_fefo['date_peremption'],
                    'jours_restants' => $date_peremption < $aujourd_hui ? -$jours : $jours,
                    'statut'         => $date_peremption < $aujourd_hui ? 'expire' : 'urgent',
                    'message'        => $date_peremption < $aujourd_hui 
                        ? '⚠ LOT PÉRIMÉ depuis ' . $jours . ' jours !'
                        : '⚠ Lot expire dans ' . $jours . ' jour(s) !',
                ];
            }
        }
    }

    $promotion = db_calculer_prix_article($pdo, (int)$article['id'], $magasin_id);
    $prix_final = (float)$article['prix_vente'];
    if ($promotion) {
        $prix_final = $promotion['prix_remise'];
    }

    $response['found']   = true;
    $response['article'] = [
        'id'            => (int)$article['id'],
        'code_barre'    => $article['code_barre'],
        'nom'           => $article['nom'],
        'prix_unitaire' => $prix_final,
        'prix_display'  => money($prix_final),
        'stock_dispo'   => (int)$article['quantite_stock'],
        'alerte'        => $alerte,
    ];

    if ($promotion) {
        $response['article']['promotion'] = [
            'prix_original' => $promotion['prix_original'],
            'prix_remise'   => $promotion['prix_remise'],
            'remise_pct'    => $promotion['remise_pct'],
            'label'         => $promotion['label'],
        ];
    }

    json_out($response);
}

// ============================================================
//  CAISSE HORS-LIGNE — Synchronisation de ventes
// ============================================================

function handle_caisse_sync(): void {
    if (!est_connecte()) {
        json_out(['error' => 'Non autorisé. Connectez-vous.'], 401);
    }
    if (!peut('caisse_gerer')) {
        json_out(['error' => 'Accès refusé. Droits insuffisants.'], 403);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];

    $clientSaleId = trim($input['client_sale_id'] ?? '');
    $lignes       = $input['lignes'] ?? [];
    $totalTtc     = (float)($input['total_ttc'] ?? 0);
    $montantPaye  = (float)($input['montant_paye'] ?? 0);
    $magasinId    = (int)($input['magasin_id'] ?? 0);
    $userId       = (int)($input['user_id'] ?? 0);

    // Valider que le magasin soumis correspond au magasin de l'utilisateur authentifié
    $userMagasinId = (int)(user_courant()['magasin_id'] ?? 0);
    if ($userMagasinId > 0 && $magasinId !== $userMagasinId) {
        json_out(['success' => false, 'error' => 'Magasin non autorisé.'], 403);
    }

    if ($clientSaleId === '' || empty($lignes) || $montantPaye <= 0) {
        json_out(['success' => false, 'error' => 'Données invalides.'], 422);
    }

    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $clientSaleId)) {
        json_out(['success' => false, 'error' => 'Format client_sale_id invalide.'], 422);
    }

    global $pdo;

    $stmt = $pdo->prepare("SELECT id FROM factures WHERE client_sale_id = ? LIMIT 1");
    $stmt->execute([$clientSaleId]);
    if ($row = $stmt->fetch()) {
        json_out([
            'success'        => true,
            'already_synced' => true,
            'facture_id'     => (int)$row['id'],
            'message'        => 'Vente déjà synchronisée.',
        ]);
    }

    try {
        $pdo->beginTransaction();

        $numero = generate_invoice_number($pdo);
        $totalHt = 0.0;
        $lignesValides = [];

        foreach ($lignes as $l) {
            $articleId    = (int)($l['article_id'] ?? 0);
            $quantite     = (int)($l['quantite'] ?? 0);

            if ($articleId <= 0 || $quantite <= 0) continue;

            $art = db_article_get_for_update($pdo, $articleId, $magasinId);
            if (!$art) {
                throw new RuntimeException('Article #' . $articleId . ' introuvable.');
            }

            // SEC-13 : Vérifier que l'article a du stock dans CE magasin
            $stmt_stock = $pdo->prepare("SELECT quantite FROM stock_magasins WHERE article_id = ? AND magasin_id = ?");
            $stmt_stock->execute([$articleId, $magasinId]);
            $stock_magasin = (int)($stmt_stock->fetchColumn() ?: 0);
            if ($stock_magasin < $quantite) {
                throw new RuntimeException('Stock insuffisant pour ' . $art['nom'] . ' dans ce magasin.');
            }

            $prixUnitaire = (float)$art['prix_vente'];
            $prixOriginal = (float)$art['prix_vente'];
            $remisePct = null;
            $promo = db_calculer_prix_article($pdo, $articleId, $magasinId);
            if ($promo) {
                $prixUnitaire = $promo['prix_remise'];
                $prixOriginal = $promo['prix_original'];
                $remisePct = $promo['remise_pct'];
            }
            $sousTotal = calc_line_subtotal($prixUnitaire, $quantite);
            $totalHt += $sousTotal;

            $lignesValides[] = compact('articleId', 'quantite', 'prixUnitaire', 'prixOriginal', 'remisePct');
        }

        if (empty($lignesValides)) {
            throw new RuntimeException('Aucune ligne valide dans la vente.');
        }

        $tauxTva = param_tva_taux();
        $totalTtcCalc = round($totalHt * (1 + $tauxTva / 100), 2);
        $monnaieRendue = max(0.0, $montantPaye - $totalTtcCalc);

        // NOU-5 : Vérifier que le total_ttc envoyé correspond au calculé (tolérance 0.02)
        if (abs($totalTtc - $totalTtcCalc) > 0.02) {
            throw new RuntimeException('Le total TTC ne correspond pas au calculé.');
        }

        if ($montantPaye < round($totalTtcCalc, 2)) {
            throw new RuntimeException('Le montant payé est insuffisant.');
        }

        $factureId = db_facture_insert($pdo, [
            'numero_facture' => $numero,
            'utilisateur_id' => user_courant()['id'] ?? null,
            'total_ht'       => round($totalHt, 2),
            'tva_taux'       => round($tauxTva, 2),
            'total_ttc'      => $totalTtcCalc,
            'montant_paye'   => round($montantPaye, 2),
            'monnaie_rendue' => round($monnaieRendue, 2),
            'magasin_id'     => $magasinId,
        ]);

        $pdo->prepare("UPDATE factures SET client_sale_id = ? WHERE id = ?")
            ->execute([$clientSaleId, $factureId]);

        foreach ($lignesValides as $lv) {
            db_ligne_facture_insert($pdo, $factureId, $lv['articleId'], $lv['quantite'], $lv['prixUnitaire'], $lv['prixOriginal'], $lv['remisePct']);
            $lots_decrementes = db_deduire_stock_lot($pdo, $lv['articleId'], $magasinId, $lv['quantite'], $numero);
            
            $lot_parts = [];
            foreach ($lots_decrementes as $ld) {
                $lot_parts[] = "Lot {$ld['numero_lot']}: -{$ld['quantite_prise']}";
            }
            $lots_info = $lot_parts ? ' [' . implode(', ', $lot_parts) . ']' : '';

            db_mouvement_insert(
                $pdo,
                $lv['articleId'],
                user_courant()['id'] ?? null,
                'Vente',
                $lv['quantite'],
                'Vente hors-ligne ' . $numero . $lots_info,
                $magasinId
            );
        }

        $pdo->commit();
        json_out([
            'success'        => true,
            'facture_id'     => $factureId,
            'numero'         => $numero,
            'client_sale_id' => $clientSaleId,
        ]);

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('API caisse sync error: ' . $e->getMessage());
        json_out(['success' => false, 'error' => 'Erreur lors de la synchronisation de la vente.'], 409);
    }
}

// ============================================================
//  ÉTAT DE LA CAISSE
// ============================================================

function handle_caisse_status(): void {
    if (!est_connecte()) {
        json_out(['error' => 'Non autorisé.'], 401);
    }
    if (!peut('caisse_gerer')) {
        json_out(['error' => 'Accès refusé. Droits insuffisants.'], 403);
    }

    global $pdo;
    $u = user_courant();
    $magasin_id = (int)($u['magasin_id'] ?? 0);
    $user_id = (int)($u['id'] ?? 0);

    $ferme = db_cloture_deja_ferme($pdo, $magasin_id, $user_id);
    $montant_attendu = $ferme ? 0.0 : db_calculer_ventes_du_jour($pdo, $magasin_id, $user_id);
    $nb_ventes = $ferme ? 0 : db_compter_ventes_du_jour($pdo, $magasin_id, $user_id);

    json_out([
        'ferme'            => $ferme,
        'montant_attendu'  => $montant_attendu,
        'nb_ventes'        => $nb_ventes,
    ]);
}

// ============================================================
//  GENERATEUR CODE-BARRES EAN-13
// ============================================================

function handle_generer_code_barre(): void {
    global $pdo;
    if (!est_connecte()) {
        json_out(['error' => 'Non autorisé.'], 401);
    }
    if (!peut('articles_modifier')) {
        json_out(['error' => 'Accès refusé. Droits insuffisants.'], 403);
    }
    try {
        $code = api_generer_code_barre($pdo);
        json_out(['code_barre' => $code]);
    } catch (\Throwable $e) {
        json_out(['error' => 'Erreur lors de la génération.'], 500);
    }
}

// ============================================================
//  CODE PROMO (VERIFICATION CAISSE)
// ============================================================

function handle_promo_check(): void {
    global $pdo;
    if (!est_connecte()) {
        json_out(['error' => 'Non autorisé.'], 401);
    }
    $code  = trim($_GET['code'] ?? '');
    $total = (float)($_GET['total'] ?? 0);

    if ($code === '') {
        json_out(['valide' => false, 'message' => 'Code promo requis.'], 400);
    }

    $promo = db_promotion_get_by_code($pdo, $code, $total);
    if (!$promo) {
        json_out(['valide' => false, 'message' => 'Code promo invalide, expiré ou montant minimum non atteint.']);
    }

    json_out([
        'valide'         => true,
        'promo_id'       => (int)$promo['id'],
        'nom'            => $promo['nom'],
        'type_reduction' => $promo['type_reduction'],
        'valeur'         => (float)$promo['valeur'],
        'article_id'     => $promo['article_id'] ? (int)$promo['article_id'] : null,
    ]);
}