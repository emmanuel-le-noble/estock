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

    // --- Clients fidélité (caisse) ---
    $resource === 'clients' && $method === 'GET'
        && isset($segments[1]) && $segments[1] === 'search'
        => handle_clients_search(),

    // --- 404 ---
    default
        => json_out(['error' => 'Route introuvable.'], 404),
};

// ============================================================
//  AUTH HANDLERS
// ============================================================

/**
 * Vérifier le token CSRF transmis via l'en-tête X-CSRF-Token.
 */
function api_csrf_ok(): bool {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return !empty($token) && verify_csrf_token($token);
}

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
    $search_sql = db_articles_search_sql($search, user_magasin_id());
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
    if (!api_csrf_ok()) {
        json_out(['error' => 'Token CSRF invalide.'], 403);
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
    if (!api_csrf_ok()) {
        json_out(['error' => 'Token CSRF invalide.'], 403);
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
    if (!api_csrf_ok()) {
        json_out(['error' => 'Token CSRF invalide.'], 403);
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
    $magasin_id  = user_magasin_id();
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

    // Blocage des lots périmés : si le lot FEFO vendable le plus proche est
    // périmé (== tous les lots en stock sont périmés), le scan est refusé
    // comme le fera la validation finale (db_deduire_stock_lot).
    if ($magasin_id > 0 && isset($article['id'])) {
        $lot_fefo = db_lot_get_fefo($pdo, (int)$article['id'], $magasin_id);
        if ($lot_fefo && !empty($lot_fefo['date_peremption'])) {
            $date_peremption = new DateTime((string)$lot_fefo['date_peremption']);
            $aujourd_hui = new DateTime('today');
            $jours = (int)$aujourd_hui->diff($date_peremption)->days;

            if ($date_peremption < $aujourd_hui) {
                json_out([
                    'found'   => false,
                    'code'    => $code,
                    'message' => 'Article périmé — vente bloquée (lot ' . $lot_fefo['numero_lot'] . ' périmé depuis ' . $jours . ' jour(s)).',
                ]);
            }
            if ($jours <= 7) {
                $response['alerte_peremption'] = [
                    'numero_lot'     => $lot_fefo['numero_lot'],
                    'date_peremption'=> $lot_fefo['date_peremption'],
                    'jours_restants' => $jours,
                    'statut'         => 'urgent',
                    'message'        => '⚠ Lot expire dans ' . $jours . ' jour(s) !',
                ];
            }
        } elseif (!$lot_fefo) {
            // db_lot_get_fefo() exclut les lots périmés : si aucun lot actif,
            // vérifier la présence de lots périmés restants (ancienne alerte
            // non bloquante remplacée par un blocage, cohérent avec la validation).
            $stmt_expire = $pdo->prepare(
                "SELECT numero_lot, date_peremption FROM article_lots
                 WHERE article_id = ? AND magasin_id = ? AND quantite > 0
                   AND date_peremption IS NOT NULL AND date_peremption < CURDATE()
                 ORDER BY date_peremption ASC LIMIT 1"
            );
            $stmt_expire->execute([(int)$article['id'], $magasin_id]);
            $lot_expire = $stmt_expire->fetch();
            if ($lot_expire) {
                $date_expire = new DateTime((string)$lot_expire['date_peremption']);
                $jours_expire = (int)(new DateTime('today'))->diff($date_expire)->days;
                json_out([
                    'found'   => false,
                    'code'    => $code,
                    'message' => 'Article périmé — vente bloquée (lot ' . $lot_expire['numero_lot'] . ' périmé depuis ' . $jours_expire . ' jour(s)).',
                ]);
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
        'taux_tva'      => ($article['taux_tva'] ?? null) !== null && $article['taux_tva'] !== '' ? (float)$article['taux_tva'] : null,
        'unite_mesure'  => $article['unite_mesure'] ?? 'UNITE',
        'vente_au_poids'=> (bool)($article['vente_au_poids'] ?? 0),
        'poids_precision' => (int)($article['poids_precision'] ?? 3),
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
    if (!api_csrf_ok()) {
        json_out(['error' => 'Token CSRF invalide.'], 403);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];

    $clientSaleId = trim($input['client_sale_id'] ?? '');
    $lignes       = $input['lignes'] ?? [];
    $totalTtc     = (float)($input['total_ttc'] ?? 0);
    $montantPaye  = (float)($input['montant_paye'] ?? 0);
    $magasinId    = (int)($input['magasin_id'] ?? 0);
    $userId       = (int)($input['user_id'] ?? 0);
    $clientId     = (int)($input['client_id'] ?? 0);
    $pointsUtilises = (int)($input['points_utilises'] ?? 0);

    // Valider que le magasin soumis correspond au magasin de l'utilisateur authentifié
    $userMagasinId = user_magasin_id();
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

    // Ne pas synchroniser une vente si la caisse est clôturée pour aujourd'hui
    if ($magasinId > 0 && db_cloture_deja_ferme($pdo, $magasinId, (int)(user_courant()['id'] ?? 0))) {
        json_out(['success' => false, 'error' => 'La caisse est déjà clôturée pour aujourd\'hui. Vente non synchronisée.'], 409);
    }

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

        // Fidélité : valider le client et verrouiller son solde (FOR UPDATE)
        $loyautes = null;
        if ($clientId > 0) {
            $client = db_client_get_by_id($pdo, $clientId);
            if (!$client) {
                throw new RuntimeException('Client fidélité introuvable.');
            }
            if ((int)$client['consentement_fidelite'] !== 1) {
                throw new RuntimeException('Le client n\'a pas consenti au programme de fidélité.');
            }
            $loyautes = db_loyalite_verrouiller($pdo, $clientId, $pointsUtilises, $totalTtc);
        }

        $numero = generate_invoice_number($pdo);
        $tauxTva = param_tva_taux();
        $totalHt = 0.0;
        $lignesValides = [];

        foreach ($lignes as $l) {
            $articleId    = (int)($l['article_id'] ?? 0);
            $quantite     = (int)($l['quantite'] ?? 0);
            $quantitePoids = isset($l['quantite_poids']) && $l['quantite_poids'] !== '' ? (float)$l['quantite_poids'] : null;

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

            // Articles sans lot (non périssables / vieux stocks) : initialiser un lot permanent
            $stmt_check_lot = $pdo->prepare("SELECT COUNT(*) FROM article_lots WHERE article_id = ? AND magasin_id = ?");
            $stmt_check_lot->execute([$articleId, $magasinId]);
            if ((int)$stmt_check_lot->fetchColumn() === 0) {
                $pdo->prepare(
                    "INSERT IGNORE INTO article_lots (article_id, magasin_id, numero_lot, quantite, date_peremption)
                     VALUES (?, ?, 'LOT-GENERAL', ?, NULL)"
                )->execute([$articleId, $magasinId, $stock_magasin]);
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
            // Taux TVA effectif : spécifique à l'article si défini, sinon taux global
            // (même logique que valider_facture.php pour une cohérence en ligne/hors-ligne)
            $tauxTvaLigne = ($art['taux_tva'] !== null && $art['taux_tva'] !== '')
                ? (float)$art['taux_tva']
                : $tauxTva;
            // Sous-total : quantité facturable = pesée pour la vente au poids
            $quantiteFacturable = $quantitePoids !== null ? $quantitePoids : $quantite;
            $sousTotal = calc_line_subtotal($prixUnitaire, $quantiteFacturable);
            $totalHt += $sousTotal;

            $lignesValides[] = compact('articleId', 'quantite', 'prixUnitaire', 'prixOriginal', 'remisePct', 'tauxTvaLigne', 'sousTotal', 'quantitePoids');
        }

        if (empty($lignesValides)) {
            throw new RuntimeException('Aucune ligne valide dans la vente.');
        }

        // Calcul TTC multi-taux : somme des TTC par ligne (identique à valider_facture.php)
        $totalTtcCalc = 0.0;
        foreach ($lignesValides as $lv) {
            $totalTtcCalc += $lv['sousTotal'] * (1 + $lv['tauxTvaLigne'] / 100);
        }
        $totalTtcCalc = round($totalTtcCalc, 2);
        $tauxTvaDominant = $tauxTva;
        if (count($lignesValides) === 1) {
            $tauxTvaDominant = $lignesValides[0]['tauxTvaLigne'];
        }

        // Remise fidélité (points) : à déduire du total TTC
        if ($loyautes !== null) {
            $totalTtcCalc = round($totalTtcCalc - $loyautes['remise'], 2);
        }

        $monnaieRendue = max(0.0, $montantPaye - $totalTtcCalc);

        // NOU-5 : Vérifier que le total_ttc envoyé correspond au calculé (tolérance 0.02)
        if (abs($totalTtc - $totalTtcCalc) > 0.02) {
            throw new RuntimeException('Le total TTC ne correspond pas au calculé (remise fidélité incluse).');
        }

        if ($montantPaye < round($totalTtcCalc, 2)) {
            throw new RuntimeException('Le montant payé est insuffisant.');
        }

        $factureId = db_facture_insert($pdo, [
            'numero_facture' => $numero,
            'utilisateur_id' => user_courant()['id'] ?? null,
            'total_ht'       => round($totalHt, 2),
            'tva_taux'       => round($tauxTvaDominant, 2),
            'total_ttc'      => $totalTtcCalc,
            'montant_paye'   => round($montantPaye, 2),
            'monnaie_rendue' => round($monnaieRendue, 2),
            'magasin_id'     => $magasinId,
            'remise_fidelite'=> $loyautes !== null ? $loyautes['remise'] : 0.0,
            'points_utilises'=> $loyautes !== null ? $loyautes['points'] : 0,
        ]);

        if ($loyautes !== null) {
            $pdo->prepare("UPDATE factures SET client_id = ? WHERE id = ?")
                ->execute([$clientId, $factureId]);
        }

        $pdo->prepare("UPDATE factures SET client_sale_id = ? WHERE id = ?")
            ->execute([$clientSaleId, $factureId]);

        foreach ($lignesValides as $lv) {
            db_ligne_facture_insert($pdo, $factureId, $lv['articleId'], $lv['quantite'], $lv['prixUnitaire'], $lv['prixOriginal'], $lv['remisePct'], $lv['tauxTvaLigne'] ?? null, $lv['quantitePoids'] ?? null);
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

        // Enregistrer les paiements multi-modes (même logique que valider_facture.php)
        if (!empty($input['paiements_json'])) {
            $paiementsList = json_decode((string)$input['paiements_json'], true);
            if (is_array($paiementsList) && !empty($paiementsList)) {
                db_paiements_insert($pdo, $factureId, $paiementsList);
            }
        }

        // Chaînage cryptographique des factures (après lignes + paiements)
        db_facture_chainer($pdo, $factureId);

        // Consommation des points fidélité (solde verrouillé FOR UPDATE)
        if ($loyautes !== null && $loyautes['points'] > 0) {
            $pdo->prepare("UPDATE clients SET points_fidelite = points_fidelite - ? WHERE id = ?")
                ->execute([$loyautes['points'], $clientId]);
            // facture_id NULL : 1 ligne GAIN par facture (clé unique), l'utilisation
            // est tracée sur la facture via factures.points_utilises.
            $pdo->prepare(
                "INSERT INTO historique_points (client_id, facture_id, points, type_operation, commentaire, utilisateur_id)
                 VALUES (?, NULL, ?, 'UTILISATION', 'Utilisation points en caisse (synchro)', ?)"
            )->execute([$clientId, -$loyautes['points'], user_id()]);
        }

        $pdo->commit();

        // Attribution des points gagnés (transaction dédiée, idempotente par facture)
        $pointsGagnes = 0;
        if ($loyautes !== null) {
            $pointsGagnes = db_client_attribuer_points($pdo, $clientId, $factureId, $totalTtcCalc);
        }

        json_out([
            'success'        => true,
            'facture_id'     => $factureId,
            'numero'         => $numero,
            'client_sale_id' => $clientSaleId,
            'client_id'      => $clientId > 0 ? $clientId : null,
            'remise_fidelite'=> $loyautes !== null ? $loyautes['remise'] : 0.0,
            'points_utilises'=> $loyautes !== null ? $loyautes['points'] : 0,
            'points_gagnes'  => $pointsGagnes,
        ]);

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('API caisse sync error: ' . $e->getMessage());
        if ($e instanceof RuntimeException) {
            json_out(['success' => false, 'error' => $e->getMessage()], 409);
        }
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
    $magasin_id = user_magasin_id();
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

// ============================================================
//  CLIENTS FIDÉLITÉ — RECHERCHE POUR LA CAISSE
// ============================================================

function handle_clients_search(): void {
    global $pdo;
    if (!est_connecte()) {
        json_out(['error' => 'Non autorisé.'], 401);
    }
    if (!peut('clients_consulter')) {
        json_out(['error' => 'Accès refusé. Droits insuffisants.'], 403);
    }

    $q = trim($_GET['q'] ?? '');
    $trie = trim($_GET['tri'] ?? ''); // 'code' = scan d'une carte de fidélité

    $clients = [];
    if ($trie === 'code' && $q !== '') {
        $client = db_client_get_by_code($pdo, $q);
        if ($client) $clients = [$client];
    } elseif ($q !== '') {
        $sql = db_clients_search_sql(['search' => $q]);
        $sql['sql'] .= ' LIMIT 10';
        $st = $pdo->prepare($sql['sql']);
        $st->execute($sql['params']);
        $clients = $st->fetchAll();
    }

    $resultat = [];
    foreach ($clients as $c) {
        $resultat[] = [
            'id'                  => (int)$c['id'],
            'nom'                 => $c['nom'] ?: $c['raison_sociale'],
            'raison_sociale'      => $c['raison_sociale'],
            'nif'                 => $c['nif'] ?? '',
            'rccm'                => $c['rccm'] ?? '',
            'telephone'           => $c['telephone'],
            'email'               => $c['email'],
            'code_fidelite'       => $c['code_fidelite'],
            'points_fidelite'     => (int)$c['points_fidelite'],
            'consentement_fidelite' => (int)$c['consentement_fidelite'] === 1,
        ];
    }

    json_out(['clients' => $resultat]);
}