<?php
/**
 * parametres.php — Gestion centralisée des paramètres de la boutique.
 *
 * Toutes les pages qui doivent afficher le nom de la boutique, la devise,
 * le taux de TVA, etc. utilisent les fonctions ci-dessous.
 *
 * PRINCIPE :
 *   - param(...)  : lit la valeur d'un paramètre (cache en session, 1 seule requête).
 *   - param_save() : enregistre un paramètre (vide le cache).
 *   - params_all() : retourne le tableau complet (par catégorie).
 */

// La connexion PDO ($pdo) doit être disponible (incluse avant ce fichier).
// Le cache vit dans $_SESSION['_params'] pour limiter les requêtes SQL.

/**
 * Vérifie que la table parametres existe. Si non, tente de la créer.
 */
function _params_ensure_table(): bool {
    global $pdo;
    static $checked = false;
    if ($checked) return true;
    $checked = true;
    $sql_file = __DIR__ . '/../database/add_parametres.sql';
    try {
        $pdo->query("SELECT 1 FROM parametres LIMIT 1");
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM parametres
            WHERE cle IN ('devise_position', 'paiement_rapide_1', 'ticket_format')
        ");
        if ((int)$stmt->fetchColumn() < 3 && file_exists($sql_file)) {
            $sql_content = file_get_contents($sql_file);
            if ($sql_content !== false && strlen($sql_content) < 100000) {
                $pdo->exec($sql_content);
            }
        }
        return true;
    } catch (PDOException $e) {
        // Table n'existe pas — on tente l'installation automatique
        if (file_exists($sql_file)) {
            try {
                $sql_content = file_get_contents($sql_file);
                if ($sql_content !== false && strlen($sql_content) < 100000) {
                    $pdo->exec($sql_content);
                }
                return true;
            } catch (Throwable $ignore) {
                error_log('[PARAMETRES] Erreur création table paramètres: ' . $ignore->getMessage());
            }
        }
        return false;
    }
}

/**
 * Charger tous les paramètres en cache (1 seule requête par session).
 */
function _params_load(): void {
    global $pdo;
    if (!_params_ensure_table()) return;
    try {
        $stmt = $pdo->query("SELECT cle, valeur FROM parametres ORDER BY ordre ASC");
        $_SESSION['_params'] = [];
        foreach ($stmt->fetchAll() as $row) {
            $_SESSION['_params'][$row['cle']] = $row['valeur'] ?? '';
        }
    } catch (Throwable $e) {
        // En cas d'erreur, on part sur un tableau vide
        $_SESSION['_params'] = [];
    }
}

/**
 * Récupérer un paramètre par sa clé.
 * Retourne $defaut si le paramètre n'existe pas.
 */
function param(string $cle, string $defaut = ''): string {
    if (empty($_SESSION['_params'])) {
        _params_load();
    }
    return $_SESSION['_params'][$cle] ?? $defaut;
}

/**
 * Récupérer un paramètre numérique (float).
 */
function param_float(string $cle, float $defaut = 0.0): float {
    return (float) param($cle, (string)$defaut);
}

/**
 * Récupérer un paramètre entier.
 */
function param_int(string $cle, int $defaut = 0): int {
    return (int) param($cle, (string)$defaut);
}

/**
 * Récupérer un paramètre booléen (1 = true, tout le reste = false).
 */
function param_bool(string $cle, bool $defaut = false): bool {
    return param($cle, $defaut ? '1' : '0') === '1';
}

/**
 * Nom court de l'application, utilisé dans la barre latérale et les titres.
 */
function param_app_name(): string {
    return param('app_nom', 'eStock');
}

/**
 * Nom public de la boutique / du magasin.
 */
function param_shop_name(): string {
    return param('nom_boutique', param_app_name());
}

/**
 * Locale de formatage côté navigateur.
 */
function param_locale(): string {
    $langue = param('langue', 'fr');
    $locales = [
        'fr' => 'fr-FR',
        'en' => 'en-US',
        'es' => 'es-ES',
        'ar' => 'ar-MA',
        'zh' => 'zh-CN',
    ];
    return $locales[$langue] ?? 'fr-FR';
}

/**
 * Régime fiscal de la boutique : TVA (classique) ou TPU (Taxe Professionnelle
 * Unique — factures hors taxes avec mention « TVA non applicable »).
 */
function param_regime_fiscal(): string {
    return param('regime_fiscal', 'TPU') === 'TVA' ? 'TVA' : 'TPU';
}

/**
 * Vrai si la boutique est au régime TPU (Taxe Professionnelle Unique).
 */
function param_regime_tpu(): bool {
    return param_regime_fiscal() === 'TPU';
}

/**
 * Taux de TVA réellement appliqué.
 * Régime TPU : toujours 0 (facturation hors taxes).
 */
function param_tva_taux(): float {
    if (param_regime_tpu()) {
        return 0.0;
    }
    if (!param_bool('tva_active', true)) {
        return 0.0;
    }
    return max(0.0, param_float('tva_taux_defaut', 18.0));
}

/**
 * Mention légale affichée sur les factures selon le régime fiscal.
 * TPU → « TVA non applicable » ; TVA → libellé taux appliqué.
 */
function param_mention_tva(float $taux = 0.0): string {
    if (param_regime_tpu()) {
        return 'TVA non applicable (TPU)';
    }
    $t = $taux > 0 ? $taux : param_tva_taux();
    return $t > 0 ? 'TVA ' . number_format($t, 2, ',', ' ') . ' %' : '';
}

/**
 * Formater un montant avec la devise configurée.
 * Ex : 12.50 → "12,50 FCFA"
 */
function param_money(float $montant): string {
    $symbole = trim(param('devise_symbole', 'FCFA'));
    $position = param('devise_position', 'apres');
    $decimales = max(0, min(4, param_int('devise_decimales', 2)));
    $sep_dec = param('separateur_decimal', ',') ?: ',';
    $sep_milliers = param('separateur_milliers', ' ');

    $nombre = number_format($montant, $decimales, $sep_dec, $sep_milliers);
    if ($symbole === '') {
        return $nombre;
    }
    return $position === 'avant'
        ? trim($symbole . ' ' . $nombre)
        : trim($nombre . ' ' . $symbole);
}

/**
 * Configuration transmise à la caisse JavaScript.
 */
function param_pos_config(): array {
    return [
        'taux_tva' => param_tva_taux(),
        'regime_fiscal' => param_regime_fiscal(),
        'devise_code' => param('devise_code', 'XOF'),
        'devise_symbole' => param('devise_symbole', 'FCFA'),
        'devise_position' => param('devise_position', 'apres'),
        'devise_decimales' => max(0, min(4, param_int('devise_decimales', 2))),
        'separateur_decimal' => param('separateur_decimal', ',') ?: ',',
        'separateur_milliers' => param('separateur_milliers', ' '),
        'locale' => param_locale(),
    ];
}

/**
 * Sauvegarder un paramètre en BDD et vider le cache.
 */
function param_save(string $cle, string $valeur): bool {
    global $pdo;
    if (!_params_ensure_table()) return false;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO parametres (cle, valeur)
            VALUES (:cle, :val)
            ON DUPLICATE KEY UPDATE valeur = :val2
        ");
        $stmt->execute([':cle' => $cle, ':val' => $valeur, ':val2' => $valeur]);
        // Mettre à jour le cache immédiatement
        $_SESSION['_params'][$cle] = $valeur;
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Retourne tous les paramètres groupés par catégorie.
 * Chaque élément : ['cle','valeur','categorie','ordre','description']
 */
function params_all(): array {
    global $pdo;
    if (!_params_ensure_table()) return [];
    $stmt = $pdo->query("SELECT * FROM parametres ORDER BY categorie ASC, ordre ASC");
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $cat = $row['categorie'];
        if (!isset($result[$cat])) {
            // Traduction des catégories en français
            $labels = [
                'boutique'  => '🏪 Boutique',
                'financier' => '💰 Finances & Devise',
                'apparence' => '🎨 Apparence',
                'ticket'    => '🧾 Ticket de caisse',
                'general'   => '⚙️ Général',
            ];
            $result[$cat] = ['label' => $labels[$cat] ?? $cat, 'items' => []];
        }
        $result[$cat]['items'][] = $row;
    }
    return $result;
}

/**
 * Vider le cache des paramètres (à appeler après une màj multiple).
 */
function params_flush_cache(): void {
    unset($_SESSION['_params']);
    _params_load();
}
