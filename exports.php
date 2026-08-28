<?php
/**
 * exports.php — Contrôleur d'export CSV / Excel UTF-8.
 * Types supportés : articles | factures | mouvements | inventaire
 */
if (!function_exists('est_connecte'))    { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard'))      { require_once __DIR__ . '/includes/helpers.php'; }

exiger_role(ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_MAGASINIER);

$type = $_GET['type'] ?? 'articles';
$filename = 'export_' . $type . '_' . date('Y-m-d_H-i') . '.csv';

// En-têtes HTTP pour téléchargement CSV UTF-8
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Écriture du BOM UTF-8 pour ouverture directe dans Excel
$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF");

$magasin_id = user_magasin_id();

switch ($type) {

    // ---- EXPORT STOCK / ARTICLES ----
    case 'articles':
        fputcsv($output, ['ID', 'Code-barres', 'Nom Article', 'SKU', 'Catégorie', 'Prix Achat', 'Prix Vente', 'Stock', 'Seuil Alerte', 'Valeur Stock HT', 'Emplacement'], ';', '"', "\\");
        $search = $_GET['search'] ?? '';
        $cat_id = (int)($_GET['categorie_id'] ?? 0);
        $rows   = db_articles_search_stock($pdo, $search, 5000, $magasin_id, $cat_id);

        foreach ($rows as $r) {
            $valeur = (float)($r['prix_achat'] ?? 0) * (int)($r['quantite_stock'] ?? 0);
            fputcsv($output, [
                $r['id'],
                $r['code_barre'],
                $r['nom'],
                $r['sku'] ?? '',
                $r['categorie_nom'] ?? '',
                number_format((float)($r['prix_achat'] ?? 0), 2, ',', ''),
                number_format((float)$r['prix_vente'], 2, ',', ''),
                (int)$r['quantite_stock'],
                (int)$r['seuil_alerte'],
                number_format($valeur, 2, ',', ''),
                $r['emplacement'] ?? '',
            ], ';', '"', "\\");
        }
        suivre_activite('EXPORT_CSV', 'Export du stock / articles');
        break;

    // ---- EXPORT FACTURES / VENTES ----
    case 'factures':
        fputcsv($output, ['ID', 'N° Facture', 'Date Vente', 'Vendeur', 'Total HT', 'TVA %', 'Total TTC', 'Montant Payé', 'Rendu', 'Statut'], ';', '"', "\\");
        $built = db_factures_search_sql(['debut' => $_GET['debut'] ?? '', 'fin' => $_GET['fin'] ?? ''], $magasin_id);
        $stmt  = $pdo->prepare($built['sql']);
        $stmt->execute($built['params']);
        $rows  = $stmt->fetchAll();

        foreach ($rows as $r) {
            fputcsv($output, [
                $r['id'],
                $r['numero_facture'],
                $r['date_facture'],
                $r['vendeur_nom'] ?? '',
                number_format((float)$r['total_ht'], 2, ',', ''),
                number_format((float)$r['tva_taux'], 2, ',', ''),
                number_format((float)$r['total_ttc'], 2, ',', ''),
                number_format((float)$r['montant_paye'], 2, ',', ''),
                number_format((float)$r['monnaie_rendue'], 2, ',', ''),
                $r['statut'],
            ], ';', '"', "\\");
        }
        suivre_activite('EXPORT_CSV', 'Export de la liste des factures');
        break;

    // ---- EXPORT MOUVEMENTS DE STOCK ----
    case 'mouvements':
        fputcsv($output, ['ID', 'Date', 'Article', 'Code-barres', 'Type', 'Quantité', 'Motif', 'Utilisateur', 'Magasin'], ';', '"', "\\");
        $stmt = $pdo->prepare("
            SELECT m.*, a.nom AS article_nom, a.code_barre, u.nom AS user_nom, mag.nom AS nom_magasin
            FROM mouvements_stock m
            JOIN articles a ON a.id = m.article_id
            LEFT JOIN utilisateurs u ON u.id = m.utilisateur_id
            LEFT JOIN magasins mag ON mag.id = m.magasin_id
            WHERE m.magasin_id = ?
            ORDER BY m.date_mouvement DESC LIMIT 5000
        ");
        $stmt->execute([$magasin_id]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $r) {
            fputcsv($output, [
                $r['id'],
                $r['date_mouvement'],
                $r['article_nom'],
                $r['code_barre'],
                $r['type'],
                (int)$r['quantite'],
                $r['motif'] ?? '',
                $r['user_nom'] ?? '',
                $r['nom_magasin'] ?? '',
            ], ';', '"', "\\");
        }
        suivre_activite('EXPORT_CSV', 'Export des mouvements de stock');
        break;

    // ---- EXPORT SESSION INVENTAIRE ----
    case 'inventaire':
        $inv_id = (int)($_GET['id'] ?? 0);
        $token  = input_string($_GET['token'] ?? '');
        if ($inv_id <= 0 || !verify_url_signature($inv_id, $token, ['type' => 'inventaire'])) {
            fputcsv($output, ['Lien invalide ou expiré.'], ';', '"', "\\");
            break;
        }
        $inv    = db_inventaire_get_by_id($pdo, $inv_id);
        if ($inv) {
            fputcsv($output, ['Réf Inventaire', $inv['reference'], 'Statut', $inv['statut'], 'Date', $inv['date_debut']], ';', '"', "\\");
            fputcsv($output, [], ';', '"', "\\");
            fputcsv($output, ['Article', 'Code-barres', 'SKU', 'Stock Théorique', 'Stock Compté', 'Écart', 'Notes'], ';', '"', "\\");
            $lignes = db_inventaire_lignes_get($pdo, $inv_id);
            foreach ($lignes as $l) {
                fputcsv($output, [
                    $l['article_nom'],
                    $l['code_barre'],
                    $l['sku'] ?? '',
                    (int)$l['quantite_theorique'],
                    (int)$l['quantite_comptee'],
                    (int)$l['ecart'],
                    $l['notes'] ?? '',
                ], ';', '"', "\\");
            }
            suivre_activite('EXPORT_CSV', 'Export inventaire #' . $inv['reference']);
        }
        break;

    // ---- EXPORT COMMANDES FOURNISSEURS ----
    case 'commandes_fournisseur':
        fputcsv($output, ['N° Commande', 'Fournisseur', 'Magasin', 'Statut', 'Nb Articles', 'Total HT', 'Date Commande', 'Créé par'], ';', '"', "\\");
        $built = db_commandes_fournisseur_search_sql([
            'statut'         => $_GET['statut'] ?? '',
            'fournisseur_id' => $_GET['fournisseur_id'] ?? '',
            'magasin_id'     => $magasin_id,
            'date_debut'     => $_GET['date_debut'] ?? '',
            'date_fin'       => $_GET['date_fin'] ?? '',
        ]);
        $stmt  = $pdo->prepare($built['sql']);
        $stmt->execute($built['params']);
        $rows  = $stmt->fetchAll();

        foreach ($rows as $r) {
            fputcsv($output, [
                $r['id'],
                $r['fournisseur_nom'] ?? '',
                $r['magasin_nom'] ?? '',
                str_replace('_', ' ', $r['statut']),
                (int)$r['nb_lignes'],
                number_format((float)$r['total_ht'], 2, ',', ''),
                $r['date_commande'],
                $r['utilisateur_nom'] ?? '',
            ], ';', '"', "\\");
        }
        suivre_activite('EXPORT_CSV', 'Export de la liste des commandes fournisseurs');
        break;

    default:
        fputcsv($output, ['Type d\'export invalide.'], ';', '"', "\\");
        break;
}

fclose($output);
exit;
