<?php
/**
 * cloture.php - Clôture de Caisse Journalière (Z de Caisse).
 */
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_role(ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_VENDEUR);

$u = user_courant();
$magasin_id = (int)($u['magasin_id'] ?? 0);
$user_id = (int)($u['id'] ?? 0);

// Vérifier si une clôture existe déjà pour aujourd'hui
$deja_ferme = db_cloture_deja_ferme($pdo, $magasin_id, $user_id);

$montant_attendu = 0.0;
$nb_ventes = 0;
$derniere_cloture = null;

if ($deja_ferme) {
    $derniere_cloture = db_cloture_get_derniere($pdo, $magasin_id);
} else {
    $montant_attendu = (float)db_calculer_ventes_du_jour($pdo, $magasin_id, $user_id);
    $nb_ventes = (int)db_compter_ventes_du_jour($pdo, $magasin_id, $user_id);
}

// Traitement POST : validation de la clôture
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$deja_ferme) {
    csrf_guard('cloture.php');
    $montant_reel = (float)($_POST['montant_reel'] ?? 0);
    $ecart = round($montant_reel - $montant_attendu, 2);

    try {
        $pdo->beginTransaction();
        
        $cloture_id = db_cloture_insert($pdo, [
            'magasin_id'      => $magasin_id,
            'utilisateur_id'  => $user_id,
            'montant_attendu' => $montant_attendu,
            'montant_reel'    => $montant_reel,
            'ecart'           => $ecart,
        ]);

        db_cloture_verrouiller_ventes($pdo, $cloture_id, $magasin_id, $user_id);
        $pdo->commit();

        if (abs($ecart) > 0.001) {
            $signe = $ecart > 0 ? '+' : '';
            suivre_activite(
                'ECART_CAISSE',
                'Écart de caisse détecté pour ' . h($u['nom'])
                . '. Attendu : ' . number_format($montant_attendu, 2, ',', ' ')
                . ', Réel : ' . number_format($montant_reel, 2, ',', ' ')
                . ', Écart : ' . $signe . number_format($ecart, 2, ',', ' ')
            );
        }

        suivre_activite('CLOTURE_CAISSE', 'Clôture validée. Attendu: ' . $montant_attendu . ', Réel: ' . $montant_reel);
        flash_success('Clôture de caisse enregistrée avec succès.');
        redirect('cloture.php?recap=1');
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Erreur clôture caisse: ' . $e->getMessage());
        flash_error('Une erreur est survenue lors de la clôture.');
        redirect('cloture.php');
    }
}

$recap_mode = isset($_GET['recap']) && $deja_ferme;
if ($recap_mode && !$derniere_cloture) {
    $derniere_cloture = db_cloture_get_derniere($pdo, $magasin_id);
}

$titre_page = 'Clôture de Caisse';
$sous_titre = 'Z de Caisse · ' . date('d/m/Y');

echo $twig->render('cloture.html.twig', [
    'titre_page'       => $titre_page,
    'sous_titre'       => $sous_titre,
    'deja_ferme'       => $deja_ferme,
    'montant_attendu'  => $montant_attendu,
    'nb_ventes'        => $nb_ventes,
    'derniere_cloture' => $derniere_cloture,
    'recap_mode'       => $recap_mode,
    'devise_symbole'   => param('devise_symbole', 'FCFA'),
]);