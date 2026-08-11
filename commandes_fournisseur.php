<?php
/**
 * commandes_fournisseur.php - Gestion du cycle d'achat et des commandes fournisseurs.
 *
 * Permet la création, l'édition, l'envoi, la réception (partielle/totale) et l'annulation
 * des commandes d'achat auprès des fournisseurs.
 */
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';

exiger_permission('achats_consulter');

$u = user_courant();
$user_id = (int)($u['id'] ?? 0);
$user_magasin_id = (int)($u['magasin_id'] ?? 0);
$can_manage = peut('achats_gerer');

$action = $_POST['action'] ?? $_GET['action'] ?? 'liste';
$id_get = (int)($_GET['id'] ?? 0);

// ---- ACTION POST : PRÉ-REMPLIR UNE COMMANDE DEPUIS SUGGESTIONS D'ACHAT ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'preremplir') {
    exiger_permission('achats_gerer');
    csrf_guard('suggestions_achat.php');

    $fournisseur_id = (int)($_POST['fournisseur_id'] ?? 0);
    if ($fournisseur_id <= 0) {
        flash_error('Fournisseur invalide.');
        redirect('suggestions_achat.php');
    }

    $magasin_id = $user_magasin_id > 0 ? $user_magasin_id : 1;
    $articles = db_articles_low_stock_with_supplier($pdo, 200, $magasin_id);
    
    $lignes_a_creer = [];
    foreach ($articles as $a) {
        if ((int)($a['fournisseur_id'] ?? 0) === $fournisseur_id) {
            $qte_stock = (int)($a['quantite_stock'] ?? 0);
            $seuil = (int)($a['seuil_alerte'] ?? 0);
            $qte_cmd = max(1, $seuil - $qte_stock);
            $lignes_a_creer[] = [
                'article_id' => (int)$a['id'],
                'quantite_commandee' => $qte_cmd,
                'quantite_recue' => 0,
                'prix_achat_unitaire' => (float)($a['prix_achat'] ?? 0),
            ];
        }
    }

    if (empty($lignes_a_creer)) {
        flash_error('Aucun article en alerte trouvé pour ce fournisseur.');
        redirect('suggestions_achat.php');
    }

    try {
        $commande_id = db_transaction($pdo, function(PDO $pdo) use ($fournisseur_id, $magasin_id, $user_id, $lignes_a_creer) {
            $cmd_id = db_commande_fournisseur_insert($pdo, [
                'fournisseur_id' => $fournisseur_id,
                'magasin_id'     => $magasin_id,
                'utilisateur_id' => $user_id,
                'statut'         => 'Brouillon',
                'notes'          => 'Généré automatiquement depuis les alertes de stock.'
            ]);

            db_commande_fournisseur_lignes_save($pdo, $cmd_id, $lignes_a_creer);
            return $cmd_id;
        }, 'Brouillon de commande créé avec succès.', 'Erreur lors de la création de la commande.', 'commandes_fournisseur.php');

        suivre_activite('COMMANDE_CREEE', "Commande fournisseur #{$commande_id} pré-remplie depuis les suggestions");
        redirect(generate_signed_url('commandes_fournisseur.php', $commande_id, ['action' => 'voir']));
    } catch (Throwable $e) {
        flash_error('Erreur lors du pré-remplissage de la commande.');
        redirect('suggestions_achat.php');
    }
}

// ---- ACTION POST : CRÉER NOUVELLE COMMANDE MANUELLE ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'creer') {
    exiger_permission('achats_gerer');
    csrf_guard('commandes_fournisseur.php');

    $fournisseur_id = (int)($_POST['fournisseur_id'] ?? 0);
    $magasin_id     = (int)($_POST['magasin_id'] ?? $user_magasin_id);
    $date_rec       = $_POST['date_reception_prevue'] ?? null;
    $notes          = trim($_POST['notes'] ?? '');
    $lignes_input   = $_POST['lignes'] ?? [];

    if ($fournisseur_id <= 0 || $magasin_id <= 0) {
        flash_error('Veuillez sélectionner un fournisseur et un magasin.');
        redirect('commandes_fournisseur.php?action=nouveau');
    }

    $lignes = [];
    if (is_array($lignes_input)) {
        foreach ($lignes_input as $l) {
            $art_id = (int)($l['article_id'] ?? 0);
            $qte    = (int)($l['quantite_commandee'] ?? 0);
            $pa     = (float)($l['prix_achat_unitaire'] ?? 0);
            if ($art_id > 0 && $qte > 0) {
                $lignes[] = [
                    'article_id' => $art_id,
                    'quantite_commandee' => $qte,
                    'quantite_recue' => 0,
                    'prix_achat_unitaire' => $pa
                ];
            }
        }
    }

    if (empty($lignes)) {
        flash_error('Veuillez ajouter au moins un article avec une quantité valide.');
        redirect('commandes_fournisseur.php?action=nouveau');
    }

    try {
        $commande_id = db_commande_fournisseur_insert($pdo, [
            'fournisseur_id' => $fournisseur_id,
            'magasin_id'     => $magasin_id,
            'utilisateur_id' => $user_id,
            'statut'         => 'Brouillon',
            'date_reception_prevue' => $date_rec,
            'notes'          => $notes
        ]);

        db_commande_fournisseur_lignes_save($pdo, $commande_id, $lignes);
        suivre_activite('COMMANDE_CREEE', "Création manuelle de la commande fournisseur #{$commande_id}");
        flash_success("Commande fournisseur #{$commande_id} enregistrée en brouillon.");
        redirect(generate_signed_url('commandes_fournisseur.php', $commande_id, ['action' => 'voir']));
    } catch (Throwable $e) {
        flash_error("Erreur lors de la création de la commande : " . $e->getMessage());
        redirect('commandes_fournisseur.php?action=nouveau');
    }
}

// ---- ACTION POST : ÉDITER COMMANDE BROUILLON ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'editer') {
    exiger_permission('achats_gerer');
    $cmd_id = (int)($_POST['id'] ?? 0);
    csrf_guard('commandes_fournisseur.php');

    $cmd = db_commande_fournisseur_get_by_id($pdo, $cmd_id);
    if (!$cmd || $cmd['statut'] !== 'Brouillon') {
        flash_error('Commande introuvable ou non modifiable (seuls les brouillons peuvent être édités).');
        redirect('commandes_fournisseur.php');
    }

    $fournisseur_id = (int)($_POST['fournisseur_id'] ?? $cmd['fournisseur_id']);
    $magasin_id     = (int)($_POST['magasin_id'] ?? $cmd['magasin_id']);
    $date_rec       = $_POST['date_reception_prevue'] ?? null;
    $notes          = trim($_POST['notes'] ?? '');
    $lignes_input   = $_POST['lignes'] ?? [];

    $lignes = [];
    if (is_array($lignes_input)) {
        foreach ($lignes_input as $l) {
            $art_id = (int)($l['article_id'] ?? 0);
            $qte    = (int)($l['quantite_commandee'] ?? 0);
            $pa     = (float)($l['prix_achat_unitaire'] ?? 0);
            if ($art_id > 0 && $qte > 0) {
                $lignes[] = [
                    'article_id' => $art_id,
                    'quantite_commandee' => $qte,
                    'quantite_recue' => 0,
                    'prix_achat_unitaire' => $pa
                ];
            }
        }
    }

    db_commande_fournisseur_update($pdo, $cmd_id, [
        'fournisseur_id' => $fournisseur_id,
        'magasin_id'     => $magasin_id,
        'date_reception_prevue' => $date_rec,
        'notes'          => $notes
    ]);
    db_commande_fournisseur_lignes_save($pdo, $cmd_id, $lignes);

    suivre_activite('COMMANDE_MODIFIEE', "Modification de la commande fournisseur #{$cmd_id}");
    flash_success("Commande #{$cmd_id} mise à jour.");
    redirect(generate_signed_url('commandes_fournisseur.php', $cmd_id, ['action' => 'voir']));
}

// ---- ACTION POST : CHANGER STATUT (ENVOYER / ANNULER) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['envoyer', 'annuler'])) {
    exiger_permission('achats_gerer');
    $cmd_id = (int)($_POST['id'] ?? 0);
    csrf_guard('commandes_fournisseur.php');

    $cmd = db_commande_fournisseur_get_by_id($pdo, $cmd_id);
    if (!$cmd) {
        flash_error('Commande introuvable.');
        redirect('commandes_fournisseur.php');
    }

    if ($action === 'envoyer' && $cmd['statut'] === 'Brouillon') {
        db_commande_fournisseur_update_statut($pdo, $cmd_id, 'Envoyee');
        suivre_activite('COMMANDE_ENVOYEE', "Commande fournisseur #{$cmd_id} marquée comme envoyée");
        flash_success("Commande #{$cmd_id} marquée comme envoyée au fournisseur.");
    } elseif ($action === 'annuler' && in_array($cmd['statut'], ['Brouillon', 'Envoyee'])) {
        db_commande_fournisseur_update_statut($pdo, $cmd_id, 'Annulee');
        suivre_activite('COMMANDE_ANNULEE', "Annulation de la commande fournisseur #{$cmd_id}");
        flash_success("Commande #{$cmd_id} annulée.");
    }

    redirect(generate_signed_url('commandes_fournisseur.php', $cmd_id, ['action' => 'voir']));
}

// ---- ACTION POST : RÉCEPTION DE COMMANDE (PARTIELLE OU TOTALE) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'receptionner') {
    exiger_permission('achats_gerer');
    $cmd_id = (int)($_POST['id'] ?? 0);
    csrf_guard('commandes_fournisseur.php');

    $cmd = db_commande_fournisseur_get_by_id($pdo, $cmd_id);
    if (!$cmd || in_array($cmd['statut'], ['Recue', 'Annulee'])) {
        flash_error('Commande introuvable ou déjà clôturée.');
        redirect('commandes_fournisseur.php');
    }

    $receptions = $_POST['reception'] ?? [];
    if (!is_array($receptions)) $receptions = [];

    $nb_reçus = 0;
    try {
        $pdo->beginTransaction();

        $lignes = db_commande_fournisseur_lignes_get($pdo, $cmd_id);
        foreach ($lignes as $l) {
            $lid = (int)$l['id'];
            $qte_rec_ajout = (int)($receptions[$lid] ?? 0);
            if ($qte_rec_ajout <= 0) continue;

            $art_id = (int)$l['article_id'];
            $qte_restante = (int)$l['quantite_commandee'] - (int)$l['quantite_recue'];
            if ($qte_rec_ajout > $qte_restante) {
                throw new RuntimeException("La quantité reçue pour « {$l['article_nom']} » excède le restant à recevoir ({$qte_restante}).");
            }

            // Incrémenter la quantité reçue sur la ligne de commande
            db_commande_fournisseur_lignes_update_reception($pdo, $lid, $qte_rec_ajout);

            // Entrée en stock via process_stock_movement
            $motif = "Réception commande fournisseur #{$cmd_id}";
            process_stock_movement($pdo, $art_id, 'Entree', $qte_rec_ajout, $motif, $user_id, (int)$cmd['magasin_id']);

            $nb_reçus += $qte_rec_ajout;
        }

        if ($nb_reçus <= 0) {
            throw new RuntimeException('Aucune quantité reçue valide saisie.');
        }

        // Vérifier l'état global de réception de la commande
        $lignes_apres = db_commande_fournisseur_lignes_get($pdo, $cmd_id);
        $tout_recu = true;
        $au_moins_un_recu = false;
        foreach ($lignes_apres as $l) {
            if ((int)$l['quantite_recue'] < (int)$l['quantite_commandee']) {
                $tout_recu = false;
            }
            if ((int)$l['quantite_recue'] > 0) {
                $au_moins_un_recu = true;
            }
        }

        $nouveau_statut = $tout_recu ? 'Recue' : ($au_moins_un_recu ? 'Recue_Partielle' : $cmd['statut']);
        db_commande_fournisseur_update_statut($pdo, $cmd_id, $nouveau_statut);

        $pdo->commit();
        suivre_activite('COMMANDE_RECUE', "Réception de {$nb_reçus} produit(s) sur la commande #{$cmd_id} (Statut: {$nouveau_statut})");
        flash_success("Réception enregistrée avec succès ({$nb_reçus} produit(s) entré(s) en stock).");

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_error("Erreur lors de la réception : " . $e->getMessage());
    }

    redirect(generate_signed_url('commandes_fournisseur.php', $cmd_id, ['action' => 'voir']));
}

// ---- AFFICHAGE : VUE DÉTAILLÉE D'UNE COMMANDE ----
if ($action === 'voir' && $id_get > 0) {
    if (!verify_url_signature('commandes_fournisseur.php', $id_get)) {
        flash_error('Lien expiré ou invalide.');
        redirect('commandes_fournisseur.php');
    }

    $cmd = db_commande_fournisseur_get_by_id($pdo, $id_get);
    if (!$cmd) {
        flash_error('Commande introuvable.');
        redirect('commandes_fournisseur.php');
    }

    $lignes = db_commande_fournisseur_lignes_get($pdo, $id_get);

    echo $twig->render('commandes_fournisseur.html.twig', [
        'titre_page'     => "Commande Fournisseur #{$id_get}",
        'page_courante'  => 'commandes_fournisseur',
        'commande'       => $cmd,
        'lignes'         => $lignes,
        'vue'            => 'detail',
        'can_manage'     => $can_manage
    ]);
    exit;
}

// ---- AFFICHAGE : FORMULAIRE DE CRÉATION / ÉDITION MANUELLE ----
if (in_array($action, ['nouveau', 'editer_form']) && $can_manage) {
    $cmd = null;
    $lignes = [];
    if ($action === 'editer_form' && $id_get > 0) {
        if (!verify_url_signature('commandes_fournisseur.php', $id_get)) {
            flash_error('Lien expiré ou invalide.');
            redirect('commandes_fournisseur.php');
        }
        $cmd = db_commande_fournisseur_get_by_id($pdo, $id_get);
        if ($cmd && $cmd['statut'] === 'Brouillon') {
            $lignes = db_commande_fournisseur_lignes_get($pdo, $id_get);
        } else {
            flash_error('Commande introuvable ou non modifiable.');
            redirect('commandes_fournisseur.php');
        }
    }

    $fournisseurs = db_fournisseurs_all($pdo);
    $magasins     = db_magasins_all($pdo);
    $articles     = db_articles_search_sql([]) ? $pdo->query("SELECT id, nom, code_barre, prix_achat, fournisseur_id FROM articles WHERE actif = 1 ORDER BY nom ASC")->fetchAll() : [];

    echo $twig->render('commandes_fournisseur.html.twig', [
        'titre_page'    => $cmd ? "Éditer Commande #{$cmd['id']}" : "Nouvelle Commande Fournisseur",
        'page_courante' => 'commandes_fournisseur',
        'commande'      => $cmd,
        'lignes'        => $lignes,
        'fournisseurs'  => $fournisseurs,
        'magasins'      => $magasins,
        'articles'      => $articles,
        'vue'           => 'formulaire',
        'can_manage'    => $can_manage
    ]);
    exit;
}

// ---- AFFICHAGE : LISTE DES COMMANDES FOURNISSEURS ----
$filters = [
    'statut'         => $_GET['statut'] ?? '',
    'fournisseur_id' => $_GET['fournisseur_id'] ?? '',
    'magasin_id'     => $_GET['magasin_id'] ?? '',
    'date_debut'     => $_GET['date_debut'] ?? '',
    'date_fin'       => $_GET['date_fin'] ?? '',
];

$search_data = db_commandes_fournisseur_search_sql($filters);
$stmt = $pdo->prepare($search_data['sql']);
$stmt->execute($search_data['params']);
$commandes = $stmt->fetchAll();

$fournisseurs = db_fournisseurs_all($pdo);
$magasins     = db_magasins_all($pdo);

echo $twig->render('commandes_fournisseur.html.twig', [
    'titre_page'    => "Commandes Fournisseurs",
    'page_courante' => 'commandes_fournisseur',
    'commandes'     => $commandes,
    'filters'       => $filters,
    'fournisseurs'  => $fournisseurs,
    'magasins'      => $magasins,
    'vue'           => 'liste',
    'can_manage'    => $can_manage
]);
