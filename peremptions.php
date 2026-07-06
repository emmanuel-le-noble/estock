<?php
/**
 * peremptions.php - Gestion des dates de péremption (DLC/DDM)
 * Affiche les lots expirés et expirant bientôt, triés par urgence.
 * Permet l'ajout de nouveaux lots avec numéro et DLC/DDM.
 */
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_connexion();

if (!peut('stock_consulter')) {
    include __DIR__ . '/includes/acces_refuse.php';
    exit;
}

$u = user_courant();
$magasin_id = (int)($u['magasin_id'] ?? 0);

// ---- Traitement formulaire : ajout de lot ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajouter_lot') {
    csrf_guard('peremptions.php');

    $data = extract_post_data([
        'article_id'       => ['type' => 'int', 'required' => true, 'redirect' => 'peremptions.php'],
        'numero_lot'       => ['type' => 'string', 'trim' => true, 'required' => true, 'max' => 100, 'redirect' => 'peremptions.php'],
        'quantite'         => ['type' => 'int', 'required' => true, 'min' => 1, 'redirect' => 'peremptions.php'],
        'date_peremption'  => ['type' => 'string', 'trim' => true, 'redirect' => 'peremptions.php'],
    ], 'peremptions.php');

    // Validation date si fournie
    $dlc = $data['date_peremption'] !== '' ? $data['date_peremption'] : null;
    if ($dlc !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dlc)) {
        flash_error('Format de date de péremption invalide.');
        redirect('peremptions.php');
    }

    try {
        // Vérifier que l'article existe et est actif
        $art_check = $pdo->prepare("SELECT id FROM articles WHERE id = ? AND actif = 1");
        $art_check->execute([$data['article_id']]);
        if (!$art_check->fetch()) {
            flash_error('Article introuvable ou inactif.');
            redirect('peremptions.php');
        }

        $lot_id = db_lot_upsert($pdo, $data['article_id'], $magasin_id, $data['numero_lot'], $data['quantite'], $dlc);
        db_stock_magasin_increment($pdo, $magasin_id, $data['article_id'], $data['quantite']);
        suivre_activite('LOT_AJOUTE', 'Lot ' . $data['numero_lot'] . ' — Qté ' . $data['quantite'] . ($dlc ? ' — DLC ' . $dlc : ''));
        flash_success('Lot "' . h($data['numero_lot']) . '" enregistré avec succès (+' . $data['quantite'] . ' unités).');
    } catch (Throwable $e) {
        error_log('Erreur ajout lot: ' . $e->getMessage());
        flash_error('Erreur lors de l\'enregistrement du lot.');
    }
    redirect('peremptions.php');
}

// ---- Données pour le template ----
$alertes = db_get_expiring_lots($pdo, 30, $magasin_id);
$lots_conformes = db_lots_count($pdo, $magasin_id) - $alertes['count'];
$articles = db_articles_list_active($pdo);

echo $twig->render('peremptions.html.twig', [
    'titre_page'     => 'Péremptions',
    'sous_titre'     => 'Suivi DLC/DDM',
    'alertes'        => $alertes,
    'lots_conformes' => max(0, $lots_conformes),
    'magasin_id'     => $magasin_id,
    'articles'       => $articles,
]);
