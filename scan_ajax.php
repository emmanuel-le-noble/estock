<?php
/**
 * scan_ajax.php - Recherche d'un article par code-barres (pour la caisse).
 *
 * Méthode : GET ou POST
 * Paramètre : code=XXXXXXXX
 * Réponse   : JSON { found:bool, article:{...} | null, message?:string }
 *
 * Utilisé par caisse.js lors du scan de la douchette.
 */
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
exiger_role(ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_VENDEUR);

header('Content-Type: application/json; charset=utf-8');

$code = trim($_GET['code'] ?? $_POST['code'] ?? '');

if ($code === '') {
    json_out(['found' => false, 'message' => 'Code vide.']);
}

// Filtrer par magasin si l'utilisateur en a un
$u = user_courant();
$magasin_id = (int)($u['magasin_id'] ?? 0);
$article = db_article_get_by_barcode($pdo, $code, $magasin_id);

if (!$article) {
    json_out([
        'found'   => false,
        'code'    => $code,
        'message' => 'Aucun article trouvé pour ce code-barres.',
    ]);
}

// Alerte si stock épuisé
$alerte = '';
if ((int)$article['quantite_stock'] <= 0) {
    $alerte = 'Stock épuisé !';
} elseif ((int)$article['quantite_stock'] <= (int)$article['seuil_alerte']) {
    $alerte = 'Stock faible (' . (int)$article['quantite_stock'] . ' restant).';
}

json_out([
    'found'   => true,
    'article' => [
        'id'              => (int)$article['id'],
        'code_barre'      => $article['code_barre'],
        'nom'             => $article['nom'],
        'prix_unitaire'   => (float)$article['prix_vente'],
        'prix_display'    => money($article['prix_vente']),
        'stock_dispo'     => (int)$article['quantite_stock'],
        'alerte'          => $alerte,
    ],
]);
