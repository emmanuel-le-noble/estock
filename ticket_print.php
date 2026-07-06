<?php
/**
 * ticket_print.php — Affiche le ticket thermique 80mm pour impression.
 *
 * Usage : ticket_print.php?facture_id=<id>&token=<hmac>
 * Génère un HTML autonome (pas de base.html.twig), déclenche window.print().
 * Sécurisé : requiert authentification OU signature HMAC valide.
 */

require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';

// Récupérer la facture
$factureId = (int)($_GET['facture_id'] ?? 0);
if ($factureId <= 0) {
    http_response_code(400);
    exit('Paramètre facture_id invalide.');
}

// Sécurité : exiger l'authentification OU signature HMAC valide
$token = $_GET['token'] ?? '';
if (!est_connecte()) {
    if (!verify_url_signature($factureId, $token)) {
        http_response_code(403);
        exit('Accès non autorisé.');
    }
} else {
    // CORRECTION : Autorise si la permission existe OU si l'utilisateur possède un rôle habilité à voir les ventes
    $role_valide = in_array(user_role(), [ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_VENDEUR], true);
    
    if (!peut('ventes_consulter') && !$role_valide) {
        http_response_code(403);
        exit('Droits insuffisants.');
    }
}

$facture = db_facture_get_by_id($pdo, $factureId);
if (!$facture) {
    http_response_code(404);
    exit('Facture introuvable.');
}

// Lignes de la facture
$lignes = db_facture_get_lignes($pdo, $factureId);

// Paiements
$paiements = [];
if (function_exists('db_paiements_by_facture')) {
    $paiements = db_paiements_by_facture($pdo, $factureId);
}

// Magasin
$u = user_courant();
$magasinId = (int)($facture['magasin_id'] ?? 0);
$magasin = null;
if ($magasinId > 0 && function_exists('db_magasin_get_by_id')) {
    $magasin = db_magasin_get_by_id($pdo, $magasinId);
}

// Vendeur
$vendeurId = $facture['utilisateur_id'] ?? null;
$vendeurNom = '';
if ($vendeurId && function_exists('db_user_get_by_id')) {
    $vendeur = db_user_get_by_id($pdo, $vendeurId);
    if ($vendeur) {
        $vendeurNom = $vendeur['nom'] ?? 'Caissier';
    }
}
$facture['vendeur_nom'] = trim($vendeurNom);

// Config devise
$tauxTva = param_tva_taux();
$decimals = max(0, min(4, (int)param('devise_decimales', 2)));
$devise = param('devise_symbole', 'FCFA');

// Rendu Twig
$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/templates');
$twig = new \Twig\Environment($loader, ['autoescape' => 'html']);

echo $twig->render('ticket_print.html.twig', [
    'facture'  => $facture,
    'lignes'   => $lignes,
    'paiements'=> $paiements,
    'magasin'  => $magasin,
    'tva_taux' => $tauxTva,
    'devise'   => $devise,
    'decimals' => $decimals,
]);