<?php
/**
 * helpers.php - Fonctions de validation, logique métier et rendu HTML réutilisables.
 * Centralise les traitements éparpillés dans les pages du projet.
 *
 * Usage : require_once __DIR__ . '/includes/helpers.php';
 */

// ============================================================
//  1. VALIDATION
// ============================================================

/**
 * Garde CSRF standardisée. Valide le token, affiche erreur et redirige si invalide.
 */
function csrf_guard(string $redirect_url): void {
    if (!csrf_validate()) {
        flash_error('Token de sécurité invalide.');
        redirect($redirect_url);
    }
}

/**
 * Extraire et nettoyer les champs POST selon un schéma de définition.
 *
 * Schéma attendu :
 * [
 *     'champ' => [
 *         'type'      => 'string|int|float|bool',
 *         'trim'      => true,        // (string only) trim()
 *         'required'  => true,        // flash + redirect si vide
 *         'nullable'  => true,        // retourne null si vide (au lieu de '')
 *         'min'       => 0,           // (int|float) valeur minimale
 *         'max'       => 200,         // (string) longueur max, (int|float) valeur max
 *         'default'   => 'valeur',    // valeur par défaut
 *         'whitelist' => [...],       // (string) valeurs autorisées
 *         'redirect'  => 'page.php',  // URL de redirection en cas d'erreur
 *     ],
 * ]
 *
 * Retourne un tableau ['champ' => valeur_nettoyee, ...].
 */
function extract_post_data(array $schema, string $global_redirect = ''): array {
    $data = [];
    foreach ($schema as $cle => $def) {
        $type     = $def['type'] ?? 'string';
        $default  = $def['default'] ?? null;
        $raw      = $_POST[$cle] ?? null;
        // Les champs scalaires ne doivent jamais accepter une structure
        // arbitraire (ex. champ[]=x). Cela évite les TypeError dans trim()
        // et les conversions silencieuses en PHP 8.
        if ($raw !== null && !is_scalar($raw)) {
            $raw = null;
        }

        switch ($type) {
            case 'int':
                $val = (int)($raw ?? $default ?? 0);
                if (isset($def['min']) && $val < $def['min']) $val = $def['min'];
                if (isset($def['max']) && $val > $def['max']) $val = $def['max'];
                break;

            case 'float':
                $val = (float)($raw ?? $default ?? 0);
                if (isset($def['min']) && $val < $def['min']) $val = $def['min'];
                if (isset($def['max']) && $val > $def['max']) $val = $def['max'];
                break;

            case 'bool':
                $val = !empty($raw) || $raw === '1';
                break;

            case 'string':
            default:
                $val = trim((string)($raw ?? $default ?? ''));
                if (!empty($def['trim']) && $raw !== null) {
                    $val = trim($raw);
                }
                if (!empty($def['nullable']) && $val === '') {
                    $val = null;
                }
                if (isset($def['max']) && $val !== null && mb_strlen($val) > $def['max']) {
                    $redirect = $def['redirect'] ?? $global_redirect;
                    if ($redirect) {
                        flash_error("Le champ « {$cle} » est trop long (max {$def['max']} caractères).");
                        redirect($redirect);
                    }
                }
                if (isset($def['whitelist']) && !in_array($val, $def['whitelist'], true)) {
                    $val = $def['default'] ?? ($def['whitelist'][0] ?? '');
                }
                break;
        }

        $data[$cle] = $val;
    }

    // Validation required : tous les champs requis doivent être non vides
    foreach ($schema as $cle => $def) {
        if (!empty($def['required'])) {
            $val = $data[$cle] ?? null;
            $champ_vide = ($val === null || $val === '');
            // Pour les champs numériques requis : un champ absent ou non numérique
            // (qui donnerait 0 silencieusement) doit être considéré comme vide.
            if (!$champ_vide && in_array($def['type'] ?? 'string', ['int', 'float'], true)) {
                $raw = $_POST[$cle] ?? null;
                if ($raw === null || $raw === '' || !is_scalar($raw) || !is_numeric($raw)) {
                    $champ_vide = true;
                }
            }
            if ($champ_vide) {
                $redirect = $def['redirect'] ?? $global_redirect;
                if ($redirect) {
                    flash_error("Le champ « {$cle} » est obligatoire.");
                    redirect($redirect);
                }
            }
        }
    }

    return $data;
}

/**
 * Valider que des champs requis sont remplis.
 * Retourne true si tout est OK, sinon flash_error + redirect.
 *
 * $fields : ['champ' => 'label lisible']
 * $redirect_url : cible en cas d'erreur
 */
function validate_required_fields(array $data, array $fields, string $redirect_url): bool {
    foreach ($fields as $cle => $label) {
        $val = $data[$cle] ?? null;
        if ($val === null || $val === '') {
            flash_error("Le champ « {$label} » est obligatoire.");
            redirect($redirect_url);
        }
    }
    return true;
}

/**
 * Valider la longueur de champs texte via un schéma.
 *
 * $rules : ['champ' => ['label' => 'Nom', 'max' => 200]]
 */
function validate_field_lengths(array $data, array $rules, string $redirect_url): void {
    foreach ($rules as $cle => $rule) {
        $val = $data[$cle] ?? null;
        if ($val === null) continue;
        $max   = $rule['max'] ?? 255;
        $label = $rule['label'] ?? $cle;
        if (mb_strlen($val) > $max) {
            flash_error("« {$label} » est trop long (max {$max} caractères).");
            redirect($redirect_url);
        }
    }
}

/**
 * Vérifier l'unicité d'un champ via un callable. Flash + redirect si doublon.
 */
function validate_uniqueness(callable $check_fn, string $message, string $redirect_url): void {
    if ($check_fn()) {
        flash_error($message);
        redirect($redirect_url);
    }
}

// ============================================================
//  2. LOGIQUE MÉTIER
// ============================================================

/**
 * Exécuter une opération transactionnelle avec gestion d'erreur standardisée.
 */
function db_transaction(callable $callback, string $success_msg, string $error_msg, string $redirect_url): mixed {
    global $pdo;
    try {
        $pdo->beginTransaction();
        $result = $callback($pdo);
        $pdo->commit();
        if ($success_msg !== '') {
            flash_success($success_msg);
        }
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Erreur transaction: ' . $e->getMessage());
        if ($error_msg !== '') {
            flash_error($error_msg);
        }
        if ($redirect_url !== '') {
            redirect($redirect_url);
        }
        return null;
    }
}

/**
 * Processus de mouvement de stock AVEC support des lots.
 * @param float|null $cout_unitaire Coût unitaire réel d'entrée (réceptions
 *        fournisseur). NULL → prix_achat de l'article (mouvements manuels,
 *        inventaires positifs, transferts, retours). Alimente le CUMP.
 */
function process_stock_movement(PDO $pdo, int $article_id, string $type, int $quantite, 
                                 ?string $motif, int $user_id, int $magasin_id,
                                 ?string $numero_lot = null, ?string $date_peremption = null,
                                 ?float $cout_unitaire = null): array {
    // 1. Récupération de l'article avant mouvement
    $art = db_article_get_for_mouvement($pdo, $article_id, $magasin_id);
    if (!$art) {
        throw new RuntimeException('Article introuvable.');
    }
    
    // 2. Vérification du stock (Déjà géré et validé en amont)
    if ($type !== 'Entree' && $quantite > (int)$art['quantite_stock']) {
        throw new RuntimeException('Stock insuffisant pour cette sortie (' . (int)$art['quantite_stock'] . ' dispo).');
    }
    
    // 3. ENTRÉE : On crée un lot UNIQUEMENT si l'utilisateur a fourni un numéro de lot (article périssable)
    if ($type === 'Entree' && !empty($numero_lot) && $magasin_id > 0) {
        db_lot_upsert($pdo, $article_id, $magasin_id, $numero_lot, $quantite, $date_peremption);
    }
    
    // 4. SORTIE ou VENTE : Gestion hybride (Périssable VS Non Périssable)
    if (($type === 'Sortie' || $type === 'Vente') && $magasin_id > 0) {
        // On récupère les lots existants pour cet article
        $lots_dispo = db_lots_by_article($pdo, $article_id, $magasin_id);
        $total_lots = !empty($lots_dispo) ? array_sum(array_column($lots_dispo, 'quantite')) : 0;
        
        // SI l'article possède des lots en base (Article Périssable) : on applique le FEFO
        if ($total_lots > 0) {
            if ($quantite > $total_lots) {
                throw new RuntimeException("Stock insuffisant par lots ($total_lots dispo en lots, $quantite demandé).");
            }
            db_lot_decrement_fefo($pdo, $article_id, $magasin_id, $quantite);
        }
        // SI l'article n'a aucun lot (Article NON Périssable) : on ne fait rien ici,
        // le système passera directement à l'étape 5 pour déduire le stock classique.
    }
    
    // 5. Valorisation CUMP : toute entrée en stock actualise le coût moyen
    // pondéré AVANT la mise à jour des quantités (la formule utilise le stock
    // d'avant-entrée). Les sorties/ventes consomment les couches FIFO
    // (valorisées au CUMP dans les statistiques).
    if ($type === 'Entree' && $quantite > 0) {
        $cout = ($cout_unitaire !== null && $cout_unitaire >= 0)
            ? $cout_unitaire
            : (float)($art['prix_achat'] ?? 0);
        if ($cout >= 0) {
            db_article_cump_entree($pdo, $article_id, $quantite, $cout, $motif, $magasin_id);
        }
    } elseif ($type === 'Sortie' || $type === 'Vente') {
        db_article_couts_consommer($pdo, $article_id, $quantite);
    }

    // 5bis. Mise à jour du stock par magasin (liaison classique du projet)
    $delta = ($type === 'Entree') ? $quantite : -$quantite;
    db_article_update_stock($pdo, $article_id, $delta, $magasin_id);

    // 6. Enregistrement systématique dans l'historique des mouvements
    db_mouvement_insert($pdo, $article_id, $user_id, $type, $quantite, $motif, $magasin_id);

    // Retourner l'état mis à jour
    return db_article_get_for_mouvement($pdo, $article_id, $magasin_id);
}

/**
 * Ajuste la quantité globale en stock d'un article spécifique.
 * Evite que le stock devienne négatif en cas de sortie supérieure au stock disponible.
 *
 * @param PDO $pdo Instance de connexion
 * @param int $articleId ID de l'article
 * @param string $type 'Entree' ou 'Sortie'
 * @param int $quantite Quantité à ajouter ou soustraire
 * @return bool
 */
function db_article_update_stock_global(PDO $pdo, int $articleId, string $type, int $quantite): bool 
{
    if ($type === 'Entree') {
        $sql = "UPDATE articles SET quantite_stock = quantite_stock + ? WHERE id = ?";
    } elseif ($type === 'Sortie') {
        $sql = "UPDATE articles SET quantite_stock = GREATEST(0, quantite_stock - ?) WHERE id = ?";
    } else {
        return false;
    }
    
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([$quantite, $articleId]);
}

/**
 * Calculer le sous-total d'une ligne de facture.
 */
function calc_line_subtotal(float $prix_unitaire, int $quantite): float {
    return $prix_unitaire * $quantite;
}

/**
 * Fidélité : valider une demande d'utilisation de points et verrouiller le
 * solde du client (FOR UPDATE, à appeler DANS une transaction).
 *
 * Retourne ['points' => points réellement consommés, 'remise' => remise TTC].
 * Lève RuntimeException si la demande est inférieure au seuil minimal.
 */
function db_loyalite_verrouiller(PDO $pdo, int $client_id, int $points_demandes, float $total_ttc): array {
    $points_demandes = max(0, (int)$points_demandes);
    $min  = max(1, (int)param('fidelite_min_points_usage', '10'));
    $val  = (float)param('fidelite_valeur_point', '1');

    $st = $pdo->prepare("SELECT points_fidelite FROM clients WHERE id = ? FOR UPDATE");
    $st->execute([$client_id]);
    $solde = (int)$st->fetchColumn();

    $usage = min($solde, $points_demandes);
    if ($usage > 0) {
        // Ne jamais laisser la remise dépasser le montant total de la vente
        $max_usage = $val > 0 ? (int)floor($total_ttc * 100 / $val) : 0;
        $usage = min($usage, max(0, $max_usage));
    }
    if ($points_demandes > 0 && $usage < $min) {
        throw new RuntimeException('Points fidélité insuffisants (minimum ' . $min . ' points, solde : ' . $solde . ').');
    }

    return [
        'points' => $usage,
        'remise' => round($usage * $val / 100, 2),
    ];
}

/**
 * Détecter si un article est en alerte de stock (quantité <= seuil).
 */
function is_stock_low(int $quantite_stock, int $seuil_alerte): bool {
    return $quantite_stock <= $seuil_alerte;
}

/**
 * Retourner la couleur Bootstrap pour un type de mouvement.
 */
function movement_type_color(string $type): string {
    return ['Entree' => 'success', 'Sortie' => 'warning', 'Vente' => 'info', 'Transfert' => 'info', 'Ajustement' => 'warning', 'Retour_stock' => 'success'][$type] ?? 'secondary';
}

/**
 * Retourner le signe (+ ou -) pour un type de mouvement.
 */
function movement_type_sign(string $type): string {
    return ['Entree' => '+', 'Sortie' => '-', 'Vente' => '-', 'Transfert' => '-', 'Ajustement' => '±', 'Retour_stock' => '+'][$type] ?? '';
}

/**
 * Retourner le badge HTML pour le statut d'un article (alerte stock).
 */
function stock_badge(int $quantite_stock, int $seuil_alerte): string {
    $low = is_stock_low($quantite_stock, $seuil_alerte);
    $color = $low ? 'danger' : 'secondary';
    return '<span class="badge text-bg-' . $color . '">' . (int)$quantite_stock . '</span>';
}

// ============================================================
//  3. RENDU HTML
// ============================================================

/**
 * Afficher une ligne "vide" pour un tableau Bootstrap.
 */
function table_empty_row(string $message, int $colspan): void {
    echo '<tr><td colspan="' . $colspan . '" class="text-center text-muted py-4">' . h($message) . '</td></tr>';
}

/**
 * Afficher un select dropdown Bootstrap avec sélection automatique.
 */
function render_dropdown(string $name, array $items, $selected_id, string $default_label = '— Sélectionner —', string $extra_attr = '', bool $show_code = false): void {
    echo '<select name="' . h($name) . '" class="form-select" ' . $extra_attr . '>';
    echo '<option value="">' . h($default_label) . '</option>';
    foreach ($items as $item) {
        $id   = (int)$item['id'];
        $sel  = ($selected_id !== null && (int)$selected_id === $id) ? ' selected' : '';
        $label = h($item['nom']);
        if ($show_code && !empty($item['code_barre'])) {
            $label .= ' (' . h($item['code_barre']) . ')';
        }
        echo '<option value="' . $id . '"' . $sel . '>' . $label . '</option>';
    }
    echo '</select>';
}

// ============================================================
//  4. GÉNÉRATION D'URLS CLASSIQUES
// ============================================================

function page_url(string $page, array $params = []): string {
    $url = $page;
    if (!str_ends_with($page, '.php')) {
        $url .= '.php';
    }
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    return $url;
}

// ============================================================
//  5. URL SIGNÉES HMAC-SHA256
// ============================================================

function generate_signed_url(string $base_url, int $id, array $extra = []): string {
    $payload = (string)$id;
    ksort($extra);
    foreach ($extra as $k => $v) { $payload .= '|' . $k . '=' . $v; }
    // Inclure le timestamp dans le payload pour expiration (24h)
    $ts = time();
    $payload .= '|ts=' . $ts;
    $token = hash_hmac('sha256', $payload, SECRET_URL_KEY);
    $sep = str_contains($base_url, '?') ? '&' : '?';
    $url = $base_url . $sep . 'id=' . $id;
    foreach ($extra as $k => $v) { $url .= '&' . $k . '=' . urlencode((string)$v); }
    return $url . '&ts=' . $ts . '&token=' . $token;
}

function url_sign(string $base_url, int $id, array $extra = []): string {
    return generate_signed_url($base_url, $id, $extra);
}

function verify_url_signature(int $id, string $token, array $extra = []): bool {
    if ($token === '' || $id <= 0) return false;
    $ts = (int)($_GET['ts'] ?? 0);
    if ($ts <= 0 || (time() - $ts) > 86400) return false; // Expiration 24h
    $payload = (string)$id;
    ksort($extra);
    foreach ($extra as $k => $v) { $payload .= '|' . $k . '=' . $v; }
    $payload .= '|ts=' . $ts;
    $expected = hash_hmac('sha256', $payload, SECRET_URL_KEY);
    return hash_equals($expected, $token);
}

// ============================================================
//  6. LOGIQUE MÉTIER CENTRALISÉE
// ============================================================

function generate_invoice_number(PDO $pdo): string {
    // Préfixe paramétrable (Paramètres → Conformité) : format <PREFIXE>-AAAAMMJJ-NNNN
    $prefixe = param('facture_prefixe', 'FAC');
    $prefixe = trim(preg_replace('/[^A-Z0-9_-]/i', '', $prefixe)) ?: 'FAC';
    $prefixe .= '-' . date('Ymd') . '-';
    $cle     = 'numero_facture:' . date('Ymd');

    // Numérotation atomique (multi-caisses) : la table sequences garantit
    // qu'aucun numéro n'est délivré deux fois, même en concurrence.
    if (db_sequences_exists($pdo)) {
        // Aligner sur les factures créées avant l'installation de la table
        $rang = db_sequence_next($pdo, $cle, db_facture_count_by_prefix($pdo, $prefixe) + 1);
        $numero = $prefixe . str_pad((string)$rang, 4, '0', STR_PAD_LEFT);
        while (db_facture_number_exists($pdo, $numero)) {
            $rang = db_sequence_next($pdo, $cle);
            $numero = $prefixe . str_pad((string)$rang, 4, '0', STR_PAD_LEFT);
        }
        return $numero;
    }

    // Fallback (table sequences absente) : déterministe mais sensible aux
    // courses dans le cas de ventes simultanées.
    $rang = db_facture_count_by_prefix($pdo, $prefixe) + 1;
    $numero = $prefixe . str_pad((string)$rang, 4, '0', STR_PAD_LEFT);
    while (db_facture_number_exists($pdo, $numero)) {
        $rang++;
        $numero = $prefixe . str_pad((string)$rang, 4, '0', STR_PAD_LEFT);
    }
    return $numero;
}

function generate_return_number(PDO $pdo): string {
    $prefixe = 'RET-' . date('Y') . '-';
    $cle     = 'numero_retour:' . date('Y');

    if (db_sequences_exists($pdo)) {
        $rang    = db_sequence_next($pdo, $cle);
        $numero  = $prefixe . str_pad((string)$rang, 4, '0', STR_PAD_LEFT);
        while (db_return_number_exists($pdo, $numero)) {
            $rang   = db_sequence_next($pdo, $cle);
            $numero = $prefixe . str_pad((string)$rang, 4, '0', STR_PAD_LEFT);
        }
        return $numero;
    }

    $year  = date('Y');
    $count = (int)$pdo->query("SELECT COUNT(*)+1 FROM retours_factures WHERE YEAR(created_at) = $year")->fetchColumn();
    return 'RET-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
}

function db_return_number_exists(PDO $pdo, string $numero): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM retours_factures WHERE numero_retour = ?");
    $stmt->execute([$numero]);
    return (int)$stmt->fetchColumn() > 0;
}

function generate_inventory_ref(PDO $pdo): string {
    $prefixe = 'INV-' . date('Y') . '-';
    $cle     = 'numero_inventaire:' . date('Y');

    if (db_sequences_exists($pdo)) {
        $rang    = db_sequence_next($pdo, $cle);
        $ref     = $prefixe . str_pad((string)$rang, 4, '0', STR_PAD_LEFT);
        while (db_inventory_ref_exists($pdo, $ref)) {
            $rang = db_sequence_next($pdo, $cle);
            $ref  = $prefixe . str_pad((string)$rang, 4, '0', STR_PAD_LEFT);
        }
        return $ref;
    }

    $year  = date('Y');
    $count = (int)$pdo->query("SELECT COUNT(*)+1 FROM inventaires WHERE YEAR(created_at) = $year")->fetchColumn();
    return 'INV-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
}

function db_inventory_ref_exists(PDO $pdo, string $ref): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventaires WHERE reference = ?");
    $stmt->execute([$ref]);
    return (int)$stmt->fetchColumn() > 0;
}

function role_badge_color(string $role): string {
    return [ROLE_DIRECTEUR=>'dark', ROLE_ADMIN=>'primary', ROLE_MAGASINIER=>'warning', ROLE_VENDEUR=>'info'][$role] ?? 'secondary';
}

function user_can_edit_user(PDO $pdo, int $target_id): bool {
    $current = user_courant();
    if ((int)$current['id'] === $target_id) return true;
    $hierarchy = [ROLE_VENDEUR=>1, ROLE_MAGASINIER=>2, ROLE_ADMIN=>3, ROLE_DIRECTEUR=>4];
    $target = db_user_get_by_id($pdo, $target_id);
    if (!$target) return false;
    $current_level = $hierarchy[$current['role']] ?? 0;
    $target_level = $hierarchy[$target['role']] ?? 0;
    return $current_level > $target_level;
}

function build_filter_params(array $filters): array {
    $params = [];
    foreach ($filters as $key => $value) {
        if ($value !== '' && $value !== null) {
            $params[$key] = $value;
        }
    }
    return $params;
}

function generate_restock_list_by_supplier(array $articles): array {
    if (empty($articles)) return [];
    $grouped = [];
    foreach ($articles as $a) {
        $sid = $a['fournisseur_id'] ?? null;
        $key = $sid ? (int)$sid : 'sans_fournisseur';
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'fournisseur_id'   => $sid,
                'fournisseur_nom'  => $a['fournisseur_nom'] ?? 'Sans fournisseur',
                'contact'          => $a['fournisseur_contact'] ?? '',
                'telephone'        => $a['fournisseur_telephone'] ?? '',
                'articles'         => [],
            ];
        }
        $grouped[$key]['articles'][] = $a;
    }
    return $grouped;
}

// ============================================================
//  7. AUDIT LOGGING (suivi d'activite)
// ============================================================

function suivre_activite(string $action, ?string $details = null): void {
    global $pdo;
    try {
        $user = $_SESSION['user'] ?? null;
        $stmt = $pdo->prepare(
            'INSERT INTO logs_activite (utilisateur_id, action, details, ip_address)
             VALUES (:uid, :action, :details, :ip)'
        );
        $stmt->execute([
            ':uid'     => $user['id'] ?? null,
            ':action'  => $action,
            ':details' => $details,
            ':ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\Throwable $e) {
        error_log('Audit log error: ' . $e->getMessage());
        log_json('erreur', 'Échec d\'écriture du journal d\'audit en base', ['exception' => $e->getMessage()]);
    }
}

/**
 * Journalisation technique structurée JSON (dossier logs/app.log).
 *
 * Chaque entrée est une ligne JSON autonome : date ISO, niveau, message,
 * contexte (jamais de secret), requête et utilisateur. Conçue pour être
 * agrégée (cron/fail2ban/outils d'ingestion) sans script de parsing.
 *
 * Niveaux gérés : debug | info | warning | erreur.
 */
function log_json(string $niveau, string $message, array $contexte = []): void {
    $niveau = in_array($niveau, ['debug', 'info', 'warning', 'erreur'], true) ? $niveau : 'info';
    $contexte['utilisateur'] = (int)($_SESSION['user']['id'] ?? 0);
    $contexte['ip']          = obtenir_adresse_ip_client();

    $entree = array_filter([
        'timestamp' => date('c'),
        'niveau'    => $niveau,
        'message'   => mb_substr($message, 0, 500),
        'contexte'  => $contexte ?: null,
        'requete'   => ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? ''),
    ], static fn($v) => $v !== null);

    $dossier = dirname(__DIR__) . '/logs';
    if (!is_dir($dossier)) {
        @mkdir($dossier, 0755, true);
    }
    $logfile = $dossier . '/app.log';
    // Rotation si le fichier dépasse 10 Mo
    if (is_file($logfile) && filesize($logfile) > 10 * 1024 * 1024) {
        @rename($logfile, $logfile . '.' . date('Y-m-d_His'));
    }
    @file_put_contents(
        $logfile,
        json_encode($entree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

// ============================================================
//  CODES-BARRES EAN-13
// ============================================================

function generer_ean13(): string {
    $prefixe = '20' . str_pad(random_int(0, 99), 2, '0', STR_PAD_LEFT);
    $corps = $prefixe;
    for ($i = 0; $i < 8; $i++) {
        $corps .= (string)random_int(0, 9);
    }
    $check = calculer_check_digit_ean13($corps);
    return $corps . $check;
}

/**
 * Calcule la clé de contrôle EAN-13
 */
function calculer_check_digit_ean13(string $douze_chiffres): int {
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $digit = (int)($douze_chiffres[$i] ?? 0);
        $sum += ($i % 2 === 0) ? $digit : $digit * 3;
    }
    return (10 - ($sum % 10)) % 10;
}

function valider_ean13(string $code): bool {
    $code = trim($code);
    if (!preg_match('/^\d{13}$/', $code)) {
        return false;
    }
    $check = calculer_check_digit_ean13(substr($code, 0, 12));
    return (int)$code[12] === $check;
}

function api_generer_code_barre(PDO $pdo): string {
    $max = 50;
    for ($i = 0; $i < $max; $i++) {
        $code = generer_ean13();
        $stmt = $pdo->prepare("SELECT 1 FROM articles WHERE code_barre = ? LIMIT 1");
        $stmt->execute([$code]);
        if (!$stmt->fetch()) {
            return $code;
        }
    }
    throw new \RuntimeException('Impossible de générer un code-barre unique après ' . $max . ' tentatives.');
}

// ============================================================
//  MODULE 9 — NOTIFICATIONS (EMAIL BASIQUE)
// ============================================================

// ============================================================
//  MODULE E-MAIL — FILE D'ATTENTE ASYNCHRONE
// ============================================================
// Bonnes pratiques de prospection par e-mail :
//   * La prospection directe par e-mail exige un opt-in préalable prouvé.
//   * Lien de désinscription fonctionnel dans chaque e-mail de
//     prospection (droit d'opposition simple et gratuit).
//   * Conservation limitée — purge des messages traités.
//   * Suppression des données d'un destinataire sur demande (file +
//     consentements).
//   * Token de désinscription aléatoire (64 hex), aucune donnée sensible
//     en base (le champ erreur ne contient JAMAIS le corps).

const EMAILS_TYPE_TRANSACTIONNEL = 'transactionnel';
const EMAILS_TYPE_ALERTE         = 'alerte';
const EMAILS_TYPE_MARKETING      = 'marketing';

/**
 * URL publique du site pour les liens intégrés dans les e-mails (CLI worker).
 * Priorité : paramètre base_url_ext → BASE_URL (web) → fallback localhost.
 */
function email_base_url(): string {
    $ext = trim(param('base_url_ext', ''));
    if ($ext !== '') {
        return rtrim($ext, '/') . '/';
    }
    if (defined('BASE_URL') && str_contains(BASE_URL ?? '', '://')) {
        return BASE_URL;
    }
    return 'http://localhost/';
}

/**
 * Obtenir ou créer le token de désinscription (64 hex) pour un destinataire.
 * Token aléatoire non devinable, avec traçabilité du consentement.
 */
function email_obtenir_token(PDO $pdo, string $email): string {
    $stmt = $pdo->prepare("SELECT token_desinscription FROM emails_consentements WHERE email = ?");
    $stmt->execute([$email]);
    $token = $stmt->fetchColumn();
    if (is_string($token) && $token !== '') {
        return $token;
    }
    $token = bin2hex(random_bytes(32));
    $pdo->prepare(
        "INSERT INTO emails_consentements (email, token_desinscription)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE token_desinscription = VALUES(token_desinscription)"
    )->execute([$email, $token]);
    return $token;
}

/**
 * Enregistrer un consentement opt-in POUR LA PROSPECTION.
 * Retourne false si une opposition globale existe (l'opposition prime).
 */
function email_consentement_optin_enregistrer(PDO $pdo, string $email, string $source): bool {
    $token = email_obtenir_token($pdo, $email);
    $stmt = $pdo->prepare(
        "INSERT INTO emails_consentements (email, opt_in_marketing, date_consentement, source_consentement, token_desinscription)
         VALUES (?, 1, NOW(), ?, ?)
         ON DUPLICATE KEY UPDATE
            opt_in_marketing    = IF(opposition_globale = 1, opt_in_marketing, 1),
            date_consentement   = IF(opposition_globale = 1, date_consentement, NOW()),
            source_consentement = IF(opposition_globale = 1, source_consentement, VALUES(source_consentement))"
    );
    $stmt->execute([$email, $source, $token]);
    $row = $pdo->prepare("SELECT opposition_globale FROM emails_consentements WHERE email = ?");
    $row->execute([$email]);
    return (int)$row->fetchColumn() === 0;
}

/**
 * Vérifier si un e-mail peut être envoyé pour un type donné.
 *   * opposition_globale bloque TOUS les types ;
 *   * le marketing bloque sans opt-in préalable.
 */
function email_verifier_consentement(PDO $pdo, string $email, string $type): bool {
    $stmt = $pdo->prepare(
        "SELECT opt_in_marketing, opposition_globale FROM emails_consentements WHERE email = ?"
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row) {
        // Jamais vu : seul le marketing requiert un opt-in.
        return $type !== EMAILS_TYPE_MARKETING;
    }
    if ((int)$row['opposition_globale'] === 1) {
        return false;
    }
    if ($type === EMAILS_TYPE_MARKETING && (int)$row['opt_in_marketing'] !== 1) {
        return false;
    }
    return true;
}

/**
 * Enregistrer l'opposition — désinscription depuis le lien.
 * Met à jour le token pour invalider les anciens liens.
 */
function email_opposition_enregistrer(PDO $pdo, string $email): bool {
    $token = bin2hex(random_bytes(32));
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO emails_consentements (email, opposition_globale, date_opposition, token_desinscription)
             VALUES (?, 1, NOW(), ?)
             ON DUPLICATE KEY UPDATE
                opposition_globale = 1,
                date_opposition    = NOW(),
                opt_in_marketing   = 0,
                token_desinscription = VALUES(token_desinscription)"
        );
        $stmt->execute([$email, $token]);
        return true;
    } catch (Throwable $e) {
        error_log('Erreur enregistrement opposition: ' . $e->getMessage());
        return false;
    }
}

/**
 * Traiter un clic de désinscription via token (page publique desinscription.php).
 * Réponse générique : n'indique JAMAIS si l'adresse existait (anti-énumération).
 */
function email_desinscrire_par_token(PDO $pdo, string $token): bool {
    $token = trim($token);
    if ($token === '' || !ctype_xdigit($token) || strlen($token) !== 64) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT email FROM emails_consentements WHERE token_desinscription = ?");
    $stmt->execute([$token]);
    $email = $stmt->fetchColumn();
    if (!is_string($email) || $email === '') {
        return false;
    }
    return email_opposition_enregistrer($pdo, $email);
}

/**
 * Effacement des données d'un destinataire : purge de la file +
 * du consentement. Aucun e-mail résiduel ne lui sera envoyé.
 */
function email_supprimer_donnees(PDO $pdo, string $email): bool {
    try {
        $pdo->prepare("DELETE FROM emails_queue WHERE destinataire = ?")->execute([$email]);
        $pdo->prepare("DELETE FROM emails_consentements WHERE email = ?")->execute([$email]);
        return true;
    } catch (Throwable $e) {
        error_log('Erreur effacement données emails: ' . $e->getMessage());
        return false;
    }
}

/**
 * Ajouter un e-mail à la file d'attente (asynchrone, non bloquant).
 * Vérifie d'abord le consentement (opt-in marketing / opposition globale).
 * Retourne l'id (int), ou false si refusé / échec d'insertion.
 */
function email_queue_ajouter(PDO $pdo, string $destinataire, string $sujet, string $corps_html, string $type = EMAILS_TYPE_ALERTE): int|false {
    $destinataire = trim($destinataire);
    $sujet        = trim($sujet);
    if ($destinataire === '' || !filter_var($destinataire, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (!in_array($type, [EMAILS_TYPE_TRANSACTIONNEL, EMAILS_TYPE_ALERTE, EMAILS_TYPE_MARKETING], true)) {
        $type = EMAILS_TYPE_ALERTE;
    }

    // Pas d'envoi sans consentement valide.
    if (!email_verifier_consentement($pdo, $destinataire, $type)) {
        suivre_activite('EMAIL_REFUSE', 'E-mail ' . $type . ' bloqué (consentement) pour ' . $destinataire);
        return false;
    }

    // Mention de désinscription sur la prospection.
    if (param_bool('emails_desinscription_obligatoire', true) && $type === EMAILS_TYPE_MARKETING) {
        $token = email_obtenir_token($pdo, $destinataire);
        $base  = email_base_url();
        $mention = '<p style="margin-top:24px;font-size:11px;color:#777;border-top:1px solid #eee;padding-top:10px;">'
                 . 'Vous recevez cet e-mail car vous êtes inscrit au programme de ' . h(param_shop_name()) . '.<br>'
                 . 'Pour ne plus recevoir nos offres, cliquez ici : '
                 . '<a href="' . h($base . 'desinscription.php?token=' . $token) . '" style="color:#0d6efd;">me désinscrire</a>.'
                 . '</p>';
        $corps_html .= $mention;
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO emails_queue (destinataire, type, sujet, corps_html, statut, prochaine_tentative)
             VALUES (?, ?, ?, ?, 'EN_ATTENTE', NOW())"
        );
        $stmt->execute([$destinataire, $type, $sujet, $corps_html]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('Erreur insertion file e-mails: ' . $e->getMessage());
        return false;
    }
}

/**
 * Purge des e-mails traités (ENVOYE / ECHEC) plus anciens que $jours
 * (limitation de la conservation). Retourne le nombre supprimé.
 */
function email_queue_purger(PDO $pdo, int $jours = 30): int {
    $jours = max(1, $jours);
    try {
        $stmt = $pdo->prepare(
            "DELETE FROM emails_queue
             WHERE statut IN ('ENVOYE','ECHEC')
               AND COALESCE(date_envoi, date_creation) < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->execute([$jours]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('Erreur purge file e-mails: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Envoyer un email de notification HTML avec fallback journal d'erreurs.
 * Peut être bloquant en mode 'direct' (legacy) — en mode 'file' (défaut),
 * l'e-mail est mis en file d'attente et renvoyé par bin/emails_worker.php.
 */
function envoyer_email_notification(string $to, string $sujet, string $message_html): bool {
    global $pdo;

    // Mode asynchrone (défaut) : file d'attente.
    if (param('emails_mode', 'file') === 'file' && isset($GLOBALS['pdo'])) {
        return email_queue_ajouter($GLOBALS['pdo'], $to, $sujet, $message_html, EMAILS_TYPE_ALERTE) !== false;
    }
    $from = param('smtp_from', 'noreply@estock.local');
    $shop = param_shop_name();

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $shop <$from>\r\n";
    $headers .= "Reply-To: $from\r\n";
    $headers .= "X-Mailer: eStock POS Mailer\r\n";

    $corps = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;line-height:1.5;color:#333;'>";
    $corps .= "<h2 style='color:#0d6efd;'>" . h($shop) . "</h2>";
    $corps .= $message_html;
    $corps .= "<hr style='border:none;border-top:1px solid #eee;margin-top:20px;'>";
    $corps .= "<small style='color:#777;'>Ceci est une notification automatique générée par eStock.</small>";
    $corps .= "</body></html>";

    $envoye = false;
    try {
        // Envoi via fonction mail() native de PHP
        $envoye = @mail($to, "[$shop] " . $sujet, $corps, $headers);
    } catch (\Throwable $e) {
        error_log("Erreur envoi email: " . $e->getMessage());
    }

    if (!$envoye) {
        // Fallback journal en cas d'environnement local (WAMP sans serveur SMTP actif)
        error_log("[eStock Email Simulated] Pour: $to | Sujet: $sujet | Message: " . strip_tags($message_html));
    }
    return $envoye;
}

/**
 * Notifier par email les alertes de stock faible.
 */
function notifier_stock_faible_email(PDO $pdo): bool {
    $actif = (int)param('email_notifications_actif', 0);
    $dest  = trim(param('email_notifications_destinataire', ''));
    if (!$actif || $dest === '') return false;

    if (!function_exists('db_articles_low_stock')) return false;
    $articles = db_articles_low_stock($pdo, 50);
    if (empty($articles)) return false;

    $html = "<h3>Alertes de stock faible (" . count($articles) . " article(s))</h3>";
    $html .= "<table border='1' cellpadding='6' cellspacing='0' style='border-collapse:collapse;width:100%;'>";
    $html .= "<tr style='background:#f2f2f2;'><th>Article</th><th>Code-barres</th><th>Stock Actuel</th><th>Seuil Alerte</th></tr>";
    foreach ($articles as $a) {
        $html .= "<tr><td>" . h($a['nom']) . "</td><td><code>" . h($a['code_barre']) . "</code></td>";
        $html .= "<td style='color:red;font-weight:bold;'>" . (int)$a['quantite_stock'] . "</td>";
        $html .= "<td>" . (int)$a['seuil_alerte'] . "</td></tr>";
    }
    $html .= "</table>";

    return envoyer_email_notification($dest, "Alerte Stock Faible (" . count($articles) . " articles)", $html);
}

// ============================================================
//  MODULE 10 — SÉCURITÉ COMPLÉMENTAIRE (IP PROXY & 2FA TOTP)
// ============================================================

/**
 * Récupérer l'adresse IP réelle du client avec gestion des reverse-proxies / CDN.
 */
function obtenir_adresse_ip_client(): string {
    $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($ipKeys as $key) {
        if (!empty($_SERVER[$key])) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Décodage Base32 (RFC 4648) pour TOTP.
 */
function base32_decode_custom(string $secret): string {
    $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $base32charsFlipped = array_flip(str_split($base32chars));
    $secret = strtoupper($secret);
    $buffer = 0;
    $bitsLeft = 0;
    $binary = '';

    for ($i = 0; $i < strlen($secret); $i++) {
        $ch = $secret[$i];
        if (!isset($base32charsFlipped[$ch])) continue;
        $buffer = ($buffer << 5) | $base32charsFlipped[$ch];
        $bitsLeft += 5;
        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $binary .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }
    return $binary;
}

/**
 * Générer une clé secrète TOTP Base32 (16 caractères = 80 bits).
 */
function totp_generer_secret(int $length = 16): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < $length; $i++) {
        $secret .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $secret;
}

/**
 * Calculer le code HMAC-SHA1 TOTP (RFC 6238) pour un pas de temps donné.
 */
function totp_calculer_code(string $secret, ?int $timestamp = null, int $timeStep = 30): string {
    $timestamp = $timestamp ?? time();
    $timeSlice = (int)floor($timestamp / $timeStep);
    $packTime  = pack('N*', 0) . pack('N*', $timeSlice);

    $binarySecret = base32_decode_custom($secret);
    $hash         = hash_hmac('sha1', $packTime, $binarySecret, true);
    $offset       = ord(substr($hash, -1)) & 0x0F;

    $part = unpack('Nlog', substr($hash, $offset, 4));
    $val  = $part['log'] & 0x7FFFFFFF;
    $code = $val % 1000000;

    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

/**
 * Vérifier la validité d'un code TOTP à 6 chiffres avec une fenêtre de tolérance de ±1 pas (30s).
 */
function totp_verifier_code(string $secret, string $code, int $discrepancy = 1): bool {
    $code = trim($code);
    if (strlen($code) !== 6 || !ctype_digit($code)) return false;

    $now = time();
    for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
        $calc = totp_calculer_code($secret, $now + ($i * 30));
        if (hash_equals($calc, $code)) {
            return true;
        }
    }
    return false;
}
