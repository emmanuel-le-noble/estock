<?php
/**
 * depenses.php — Gestion des dépenses d'exploitation (charges).
 *
 * Directeur / Admin : peut ajouter et consulter toutes les dépenses.
 * Magasinier : peut ajouter des dépenses pour son magasin et les consulter.
 */
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_role(ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_MAGASINIER);

$u = user_courant();
$magasin_id = user_magasin_id();
$is_directeur = in_array($u['role'] ?? '', [ROLE_DIRECTEUR, ROLE_ADMIN], true);

// Tout le monde travaille sur le magasin actif (de suivi pour le Directeur)
$filtre_magasin = $magasin_id;

// ---- Filtres de date (défaut : mois en cours) ----
$debut = $_GET['date_debut'] ?? '';
$fin   = $_GET['date_fin'] ?? '';
if ($debut === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut)) {
    $debut = date('Y-m-01');
}
if ($fin === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin)) {
    $fin = date('Y-m-t');
}
if ($debut > $fin) {
    [$debut, $fin] = [$fin, $debut];
}

// ---- Catégories de dépenses ----
$categories = ['Loyer', 'Électricité', 'Eau', 'Transport', 'Fournitures', 'Salaires', 'Entretien', 'Téléphone/Internet', 'Assurance', 'Autre'];

// ---- Traitement formulaire : ajout dépense ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajouter') {
    csrf_guard('depenses.php');

    $data = extract_post_data([
        'titre'        => ['type' => 'string', 'trim' => true, 'required' => true, 'max' => 200, 'redirect' => 'depenses.php'],
        'categorie'    => ['type' => 'string', 'trim' => true, 'required' => true, 'redirect' => 'depenses.php', 'whitelist' => $categories],
        'montant'      => ['type' => 'float', 'min' => 0.01, 'redirect' => 'depenses.php'],
        'date_depense' => ['type' => 'string', 'trim' => true, 'required' => true, 'redirect' => 'depenses.php'],
        'description'  => ['type' => 'string', 'trim' => true],
        'magasin_id'   => ['type' => 'int', 'min' => 0],
    ], 'depenses.php');

    // Validation date
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date_depense'])) {
        flash_error('Format de date invalide.');
        redirect('depenses.php');
    }

    // La dépense est toujours rattachée au magasin actif de l'utilisateur
    $mid = $magasin_id > 0 ? $magasin_id : null;

    try {
        db_depense_insert(
            $pdo,
            $mid,
            $u['id'] ?? 0,
            $data['titre'],
            $data['categorie'],
            $data['montant'],
            $data['date_depense'],
            $data['description']
        );
        suivre_activite('DEPENSE_ENREGISTREE',
            $data['categorie'] . ' — ' . $data['titre'] . ' : ' . number_format($data['montant'], 2, ',', ' ') . ' FCFA'
        );
        flash_success('Dépense enregistrée avec succès.');
    } catch (Throwable $e) {
        error_log('Erreur dépense: ' . $e->getMessage());
        flash_error('Erreur lors de l\'enregistrement de la dépense.');
    }
    redirect('depenses.php');
}

// ---- Traitement formulaire : suppression dépense ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'supprimer') {
    csrf_guard('depenses.php');

    $supp_id = (int)($_POST['depense_id'] ?? 0);
    if ($supp_id > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM depenses WHERE id = ? AND magasin_id = ?");
            $stmt->execute([$supp_id, $magasin_id]);
            suivre_activite('DEPENSE_SUPPRIMEE', 'Dépense #' . $supp_id . ' supprimée');
            flash_success('Dépense supprimée.');
        } catch (Throwable $e) {
            error_log('Erreur suppression dépense: ' . $e->getMessage());
            flash_error('Erreur lors de la suppression.');
        }
    }
    redirect('depenses.php');
}

// ---- Données pour le template ----
$depenses = db_depenses_list($pdo, $debut, $fin, $filtre_magasin);
$total_depenses = db_depenses_total($pdo, $debut, $fin, $filtre_magasin);
$by_categorie   = db_depenses_by_categorie($pdo, $debut, $fin, $filtre_magasin);
$magasins       = db_magasins_list($pdo);

$titre_page = 'Dépenses';
echo $twig->render('depenses.html.twig', [
    'titre_page'       => $titre_page,
    'depenses'       => $depenses,
    'total_depenses' => $total_depenses,
    'by_categorie'   => $by_categorie,
    'categories'     => $categories,
    'magasins'       => $magasins,
    'magasin_id'     => $magasin_id,
    'debut'          => $debut,
    'fin'            => $fin,
    'is_directeur'   => $is_directeur,
]);
