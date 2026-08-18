<?php
/**
 * bin/inventaire_tables.php — Inventaire réel des tables de la base.
 *
 * Compare les tables créées par schema.sql + toutes les migrations
 * (database/*.sql) avec les tables réellement présentes en base, et
 * produit une liste Markdown prête à coller dans le README.
 *
 * Usage :
 *   php bin/inventaire_tables.php          # Inventaire + comparaison base
 *   php bin/inventaire_tables.php --nodb   # Inventaire des scripts SQL uniquement
 *   php bin/inventaire_tables.php --md     # Liste Markdown (pour le README)
 */

$skipMatches = []; // par défaut : rien

// ============================================================
//  1. PARSE DES SCRIPTS SQL
// ============================================================

/**
 * Extraire les tables créées par un script SQL (CREATE TABLE,
 * CREATE TABLE IF NOT EXISTS). Ignore CREATE TEMPORARY TABLE.
 */
function sql_tables_created(string $sql): array {
    $tables = [];
    preg_match_all(
        '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s*\(/i',
        $sql,
        $m,
        PREG_SET_ORDER
    );
    foreach ($m as $match) {
        $tables[] = $match[1];
    }
    return $tables;
}

/**
 * Tables complétées par ALTER TABLE ... ADD COLUMN ne créant pas de table.
 */
function sql_tables_altered(string $sql): array {
    $tables = [];
    preg_match_all('/ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?/i', $sql, $m, PREG_SET_ORDER);
    foreach ($m as $match) {
        $tables[] = $match[1];
    }
    return $tables;
}

$dirs    = [__DIR__ . '/../database'];
$created = [];
foreach ($dirs as $dir) {
    $files = glob(rtrim($dir, '/\\') . '/*.sql');
    sort($files);
    foreach ($files as $file) {
        $sql = (string)file_get_contents($file);
        foreach (sql_tables_created($sql) as $t) {
            $created[$t] = ($created[$t] ?? 0) + 1;
        }
    }
}
ksort($created);

$theoriques = array_keys($created);

// ============================================================
//  2. CONNEXION BASE (optionnel)
// ============================================================

$nodb = in_array('--nodb', $argv, true);
$reels = [];
$missing = [];
$extra = [];

if (!$nodb) {
    try {
        require_once __DIR__ . '/../config/connexion.php';
        $reels = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        sort($reels);
        $missing = array_values(array_diff($theoriques, $reels));
        $extra   = array_values(array_diff($reels, $theoriques));
    } catch (Throwable $e) {
        fwrite(STDERR, 'Connexion BDD impossible : ' . $e->getMessage() . "\n");
        fwrite(STDERR, "Utilisez --nodb pour un inventaire sans base.\n");
        exit(1);
    }
}

// ============================================================
//  3. RAPPORT
// ============================================================

$md = in_array('--md', $argv, true);

if ($md) {
    echo "### Tables (" . count($reels ?: $theoriques) . ")\n\n";
    echo '| Table | Rôle |' . "\n";
    echo '|---|---|' . "\n";
    $roles = [
        'categories'        => 'Référentiel produits',
        'articles'          => 'Articles (CUMP, valeur stock, unités)',
        'fournisseurs'      => 'Fournisseurs (devise de facturation)',
        'magasins'          => 'Points de vente (multi-magasins)',
        'parametres'        => 'Paramètres boutique (devise, TVA, conformité)',
        'stock_magasins'    => 'Stock par magasin (+ valeur comptable)',
        'article_lots'      => 'Lots DLC/DDM (FEFO)',
        'article_couts'     => 'Couches de coût (FIFO/CUMP)',
        'mouvements_stock'  => 'Historique des mouvements de stock',
        'factures'          => 'Ventes (chaînées cryptographiquement, B2B)',
        'lignes_facture'    => 'Lignes de facture (immuables)',
        'paiements_facture' => 'Paiements multi-modes (immuables)',
        'clotures_caisse'   => 'Z de caisse journaliers (chaînés)',
        'retours_factures'  => 'Retours / avoirs SAV',
        'lignes_retour'     => 'Lignes de retour',
        'commandes_fournisseur' => "Commandes d'achat (devise + taux)",
        'lignes_commande_fournisseur' => 'Lignes de commande fournisseur',
        'inventaires'       => "Sessions d'inventaire physique",
        'inventaire_lignes' => 'Lignes de comptage inventaire',
        'transferts_stock'  => 'Transferts inter-magasins',
        'promotions'        => 'Codes promo & remises',
        'regles_promotions' => 'Ventes Flash : règles de remise auto (péremption proche / surstock)',
        'depenses'          => 'Dépenses d\'exploitation',
        'utilisateurs'      => 'Comptes utilisateurs (roles)',
        'permissions'       => 'Catalogue des permissions',
        'role_permissions'  => 'Matrice RBAC rôle → permission',
        'logs_activite'     => 'Journal d\'audit',
        'login_attempts'    => 'Rate limiting connexion',
        'sequences'         => 'Compteurs atomiques (numérotation continue)',
        'archives_caisse'   => 'Archives périodiques de caisse (traçabilité fiscale)',
        'clients'           => 'Clients (fidélité + protection des données)',
        'consentements_log' => 'Registre des consentements (traçabilité)',
        'historique_points' => 'Historique des points fidélité',
        'emails_queue'      => 'File d\'attente des e-mails',
        '__test_group'      => 'Table technique de test (__test_group)',
    ];
    foreach ($reels ?: $theoriques as $t) {
        echo '| `' . $t . '` | ' . ($roles[$t] ?? '—') . ' |' . "\n";
    }
    exit(0);
}

echo "=== INVENTAIRE DES TABLES ===\n";
echo 'Base réelle     : ' . count($reels) . ' tables' . ($nodb ? ' (non vérifiées)' : '') . "\n";
echo 'Scripts SQL     : ' . count($theoriques) . ' tables déclarées' . "\n\n";

if ($nodb) {
    echo "Tables déclarées par database/*.sql :\n";
    foreach ($theoriques as $t) {
        echo "  - $t\n";
    }
    exit(0);
}

echo '--- Présentes en base ET déclarées : ' . count(array_intersect($theoriques, $reels)) . " ---\n";
echo "\n--- MANQUANTES (déclarées, absentes en base) : " . count($missing) . " ---\n";
foreach ($missing as $t) {
    echo "  - $t\n";
}
echo "\n--- SUPPLÉMENTAIRES (en base, non déclarées) : " . count($extra) . " ---\n";
foreach ($extra as $t) {
    echo "  - $t\n";
}

echo "\nListe Markdown pour le README :\n  php bin/inventaire_tables.php --md\n";
if (count($missing) > 1) {
    echo "\nAVERTISSEMENT : appliquez les migrations manquantes avec database/apply_migrations.php\n";
}