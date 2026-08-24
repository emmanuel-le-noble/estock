<?php
/**
 * ticket_print.php - Affiche le ticket thermique 80mm pour impression.
 * Usage : ticket_print.php?facture_id=<id>&token=<hmac>
 * Gère un HTML autonome (pas de base.html.twig), déclenche window.print().
 * Sécurisé : requiert authentification OU signature HMAC valide.
 */

if (!function_exists('est_connecte')) {
    require_once __DIR__ . '/config/connexion.php';
}
if (!function_exists('db_article_insert')) {
    require_once __DIR__ . '/includes/db_functions.php';
}
if (!function_exists('csrf_guard')) {
    require_once __DIR__ . '/includes/helpers.php';
}

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
$token = input_string($_GET['token'] ?? '');
$print_param = $_GET['print'] ?? '';
$verify_extra = $print_param !== '' ? ['print' => $print_param] : [];

if (!est_connecte()) {
    if (!verify_url_signature($factureId, $token, $verify_extra)) {
        http_response_code(403);
        exit('Accès non autorisé.');
    }
} else {
    // Autorise si la permission RBAC existe OU si l'utilisateur possède un rôle habilit
    $role_valide = in_array(user_role(), [ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_VENDEUR], true);
         
    if (!peut('facturation_consulter') && !peut('caisse_gerer') && !$role_valide) {
        http_response_code(403);
        exit('Droits insuffisants.');
    }
}

// CORRECTION : Appel des bonnes fonctions définies dans db_functions.php
$facture = db_facture_get_by_id($pdo, $factureId);
if (!$facture) {
    http_response_code(404);
    exit('Facture introuvable.');
}

// Un Vendeur connecté ne peut imprimer que ses propres factures (même règle que facture_view.php)
if (est_connecte() && user_role() === ROLE_VENDEUR
    && (int)($facture['utilisateur_id'] ?? 0) !== (int)(user_courant()['id'] ?? 0)) {
    http_response_code(403);
    exit('Accès non autorisé.');
}

// La facture doit appartenir au magasin actif de l'utilisateur connecté
if (est_connecte() && (int)($facture['magasin_id'] ?? 0) !== user_magasin_id()) {
    http_response_code(403);
    exit('Accès non autorisé.');
}

// CORRECTION : Appel de la fonction appropriée
$lignes = db_facture_get_lignes($pdo, $factureId);

// Paiements
$paiements = [];
if (function_exists('db_paiements_by_facture')) {
    $paiements = db_paiements_by_facture($pdo, $factureId);
}

// Magasin
$magasinId = (int)($facture['magasin_id'] ?? 0);
$magasin = null;
if ($magasinId > 0 && function_exists('db_magasin_get_by_id')) {
    $magasin = db_magasin_get_by_id($pdo, $magasinId);
}

// Vendeur (CORRECTION : Sécurisation et suppression de l'accès au champ absent 'prenom')
$vendeurId = $facture['utilisateur_id'] ?? null;
$vendeurNom = 'Caissier';
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

// Calcul TVA multi-taux à partir des lignes
$tvaResume = [];
foreach ($lignes as $l) {
    $htLigne   = (float)$l['prix_unitaire'] * (int)$l['quantite'];
    $tauxLigne = ($l['taux_tva'] !== null && $l['taux_tva'] !== '') ? (float)$l['taux_tva'] : $tauxTva;
    $tvaLigne  = $htLigne * ($tauxLigne / 100);
    $tauxKey   = number_format($tauxLigne, 2);
    if (!isset($tvaResume[$tauxKey])) {
        $tvaResume[$tauxKey] = ['taux' => $tauxLigne, 'base_ht' => 0.0, 'montant_tva' => 0.0];
    }
    $tvaResume[$tauxKey]['base_ht']     += $htLigne;
    $tvaResume[$tauxKey]['montant_tva'] += $tvaLigne;
}
$tvaResume = array_values($tvaResume);

// Fidélité : points gagnés (GAIN) + remise / points utilisés tracés sur la facture
$pointsGagnes = 0;
if ((int)($facture['client_id'] ?? 0) > 0) {
    $st_pts = $pdo->prepare("SELECT points FROM historique_points WHERE facture_id = ? AND type_operation = 'GAIN'");
    $st_pts->execute([$factureId]);
    $pointsGagnes = (int)($st_pts->fetchColumn() ?: 0);
}

// Rendu Twig
$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/templates');
$twig = new \Twig\Environment($loader, ['autoescape' => 'html']);
$twig->addFunction(new \Twig\TwigFunction('param', function (string $key, mixed $default = null) {
    return param($key, $default);
}));
$twig->addFunction(new \Twig\TwigFunction('money', function (mixed $val): string {
    return money($val);
}));
$twig->addFunction(new \Twig\TwigFunction('h', function (?string $s): string {
    return h($s);
}));

echo $twig->render('ticket_print.html.twig', [
    'facture'    => $facture,
    'lignes'     => $lignes,
    'paiements'  => $paiements,
    'magasin'    => $magasin,
    'tva_taux'   => $tauxTva,
    'tva_resume' => $tvaResume,
    'devise'     => $devise,
    'decimals'   => $decimals,
    'csp_nonce_val' => csp_nonce(),
    'points_gagnes' => $pointsGagnes,
    'fidelite_actif' => (int)$pointsGagnes > 0 || (float)($facture['remise_fidelite'] ?? 0) > 0 || (int)($facture['points_utilises'] ?? 0) > 0,
    'regime_tpu' => param_regime_tpu(),
    'nif_boutique' => param('nif_boutique', ''),
    'rccm_boutique' => param('rccm_boutique', ''),
]);
