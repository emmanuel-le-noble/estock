<?php
/**
 * profil.php - Page profil : changement de mot de passe personnel.
 *
 * Accessible par tout utilisateur connecté.
 * Un administrateur ne peut modifier que SON propre mot de passe.
 */
// Guard: compatible avec le routeur front controller (index.php)
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';

exiger_connexion();

$user = user_courant();
$user_id = (int)($user['id'] ?? 0);

// --- Traitement POST : changement de mot de passe ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'changer_mdp') {
    csrf_guard('profil.php');

    // Rate limiting : max 5 tentatives par 15 minutes
    $rateKey = 'mdp_change_' . $user_id;
    $rateCount = (int)(($_SESSION[$rateKey . '_count'] ?? 0));
    $rateTime = (int)($_SESSION[$rateKey . '_time'] ?? 0);
    if (time() - $rateTime > 900) {
        $_SESSION[$rateKey . '_count'] = 0;
        $rateCount = 0;
    }
    if ($rateCount >= 5) {
        flash_error('Trop de tentatives. Réessayez dans 15 minutes.');
        redirect('profil.php');
    }
    $_SESSION[$rateKey . '_count'] = $rateCount + 1;
    if ($rateTime === 0) $_SESSION[$rateKey . '_time'] = time();

    $mdp_actuel  = $_POST['mdp_actuel']  ?? '';
    $mdp_nouveau  = $_POST['mdp_nouveau']  ?? '';
    $mdp_confirme = $_POST['mdp_confirme'] ?? '';

    if ($mdp_actuel === '') {
        flash_error('Veuillez saisir votre mot de passe actuel.');
        redirect('profil.php');
    }

    // Récupérer le hash en base pour cet utilisateur uniquement
    $stmt = $pdo->prepare('SELECT mot_de_passe FROM utilisateurs WHERE id = :id');
    $stmt->execute([':id' => $user_id]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($mdp_actuel, $row['mot_de_passe'])) {
        flash_error('Le mot de passe actuel est incorrect.');
        redirect('profil.php');
    }

    if (mb_strlen($mdp_nouveau) < 8) {
        flash_error('Le nouveau mot de passe doit contenir au moins 8 caractères.');
        redirect('profil.php');
    }

    if ($mdp_nouveau !== $mdp_confirme) {
        flash_error('La confirmation du mot de passe ne correspond pas.');
        redirect('profil.php');
    }

    if ($mdp_nouveau === $mdp_actuel) {
        flash_error('Le nouveau mot de passe doit être différent de l\'actuel.');
        redirect('profil.php');
    }

    $hash = password_hash($mdp_nouveau, PASSWORD_DEFAULT);
    db_user_change_password($pdo, $user_id, $hash);
    suivre_activite('CHANGEMENT_MDP', 'Changement de mot de passe personnel');

    // Régénérer l'ID de session pour prévenir le session fixation
    session_regenerate_id(true);

    // Reset rate limiting
    unset($_SESSION['mdp_change_' . $user_id . '_count'], $_SESSION['mdp_change_' . $user_id . '_time']);

    flash_success('Mot de passe modifié avec succès.');
    redirect('profil.php');
}

// --- Affichage ---
echo $twig->render('profil.html.twig', [
    'titre_page' => 'Mon Profil',
]);
