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
            if ($val === null || $val === '') {
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
function db_transaction(callable $callback, string $success_msg, string $error_msg, string $redirect_url): void {
    global $pdo;
    try {
        $pdo->beginTransaction();
        $callback($pdo);
        $pdo->commit();
        flash_success($success_msg);
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Erreur transaction: ' . $e->getMessage());
        flash_error($error_msg);
    }
    redirect($redirect_url);
}

/**
 * Processus de mouvement de stock AVEC support des lots.
 */
function process_stock_movement(PDO $pdo, int $article_id, string $type, int $quantite, 
                                 ?string $motif, int $user_id, int $magasin_id = 0,
                                 ?string $numero_lot = null, ?string $date_peremption = null): array {
    $art = db_article_get_for_mouvement($pdo, $article_id, $magasin_id);
    if (!$art) {
        throw new RuntimeException('Article introuvable.');
    }
    
    // CORRECTION CRITIQUE : Vérification globale du stock pour les sorties AVANT traitement FEFO
    if ($type !== 'Entree' && $quantite > (int)$art['quantite_stock']) {
        throw new RuntimeException('Stock insuffisant pour cette sortie (' . (int)$art['quantite_stock'] . ' dispo).');
    }
    
    // Entrée de stock : créer/incrémenter le lot si fourni
    if ($type === 'Entree' && !empty($numero_lot) && $magasin_id > 0) {
        db_lot_upsert($pdo, $article_id, $magasin_id, $numero_lot, $quantite, $date_peremption);
    }
    
    // Sortie ou Vente avec FEFO (si multi-magasin actif)
    if (($type === 'Sortie' || $type === 'Vente') && $magasin_id > 0) {
        // Vérifier qu'il y a assez de stock via les lots
        $lots_dispo = db_lots_by_article($pdo, $article_id, $magasin_id);
        $total_lots = array_sum(array_column($lots_dispo, 'quantite'));
        
        // Si des lots existent, vérifier la cohérence par les lots
        if ($total_lots > 0 && $quantite > $total_lots) {
            throw new RuntimeException("Stock insuffisant par lots ($total_lots dispo en lots, $quantite demandé).");
        }
        
        // Déduire par FEFO si des lots avec DLC existent
        if ($total_lots > 0) {
            db_lot_decrement_fefo($pdo, $article_id, $magasin_id, $quantite);
        }
    }
    
    // Mettre à jour le stock global et par magasin
    $delta = ($type === 'Entree') ? $quantite : -$quantite;
    db_article_update_stock($pdo, $article_id, $delta, $magasin_id);

    // Retourner l'état mis à jour
    return db_article_get_for_mouvement($pdo, $article_id, $magasin_id);
}

/**
 * Calculer le sous-total d'une ligne de facture.
 */
function calc_line_subtotal(float $prix_unitaire, int $quantite): float {
    return $prix_unitaire * $quantite;
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
    return ['Entree' => 'success', 'Sortie' => 'warning', 'Vente' => 'info'][$type] ?? 'secondary';
}

/**
 * Retourner le signe (+ ou -) pour un type de mouvement.
 */
function movement_type_sign(string $type): string {
    return ['Entree' => '+', 'Sortie' => '-', 'Vente' => '-'][$type] ?? '';
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
    $prefixe = 'FAC-' . date('Ymd') . '-';
    $rang = db_facture_count_by_prefix($pdo, $prefixe) + 1;
    $numero = $prefixe . str_pad((string)$rang, 4, '0', STR_PAD_LEFT);
    while (db_facture_number_exists($pdo, $numero)) {
        $rang++;
        $numero = $prefixe . str_pad((string)$rang, 4, '0', STR_PAD_LEFT);
    }
    return $numero;
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
    }
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