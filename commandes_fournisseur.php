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
$user_magasin_id = user_magasin_id();
$can_manage = peut('achats_gerer');
$can_valider = peut('achats_valider');

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

    // Magasin de suivi actif (choix du Directeur, magasin d'appartenance pour les autres)
    $magasin_id = $user_magasin_id;

    // Alertes de stock enrichies : quantité recommandée calculée sur la vitesse de vente (couverture 30 jours)
    $articles = db_articles_achat_recommandes($pdo, $magasin_id, 30, 30);

    $lignes_a_creer = [];
    foreach ($articles as $a) {
        if ((int)($a['fournisseur_id'] ?? 0) === $fournisseur_id) {
            $lignes_a_creer[] = [
                'article_id' => (int)$a['id'],
                'quantite_commandee' => max(1, (int)($a['quantite_recommandee'] ?? 1)),
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
        $commande_id = db_transaction(function(PDO $pdo) use ($fournisseur_id, $magasin_id, $user_id, $lignes_a_creer) {
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
        error_log('Erreur pré-remplissage commande: ' . $e->getMessage());
        flash_error('Erreur lors du pré-remplissage de la commande.');
        redirect('suggestions_achat.php');
    }
}

// ---- ACTION POST : CRÉER NOUVELLE COMMANDE MANUELLE ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'creer') {
    exiger_permission('achats_gerer');
    csrf_guard('commandes_fournisseur.php');

    $fournisseur_id = (int)($_POST['fournisseur_id'] ?? 0);
    $magasin_id     = $user_magasin_id;
    $date_rec       = $_POST['date_reception_prevue'] ?? null;
    $notes          = input_string($_POST['notes'] ?? '');
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
        $commande_id = db_transaction(function(PDO $pdo) use ($fournisseur_id, $magasin_id, $user_id, $date_rec, $notes, $lignes) {
            $cmd_id = db_commande_fournisseur_insert($pdo, [
                'fournisseur_id' => $fournisseur_id,
                'magasin_id'     => $magasin_id,
                'utilisateur_id' => $user_id,
                'statut'         => 'Brouillon',
                'date_reception_prevue' => $date_rec ?: null,
                'notes'          => $notes
            ]);

            db_commande_fournisseur_lignes_save($pdo, $cmd_id, $lignes);
            return $cmd_id;
        }, '', 'Erreur lors de la création de la commande.', 'commandes_fournisseur.php?action=nouveau');

        suivre_activite('COMMANDE_CREEE', "Création manuelle de la commande fournisseur #{$commande_id}");
        flash_success("Commande fournisseur #{$commande_id} enregistrée en brouillon.");
        redirect(generate_signed_url('commandes_fournisseur.php', $commande_id, ['action' => 'voir']));
    } catch (Throwable $e) {
        error_log('Erreur création commande: ' . $e->getMessage());
        flash_error('Erreur lors de la création de la commande. Veuillez vérifier les données saisies.');
        redirect('commandes_fournisseur.php?action=nouveau');
    }
}

// ---- ACTION POST : ÉDITER COMMANDE BROUILLON ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'editer') {
    exiger_permission('achats_gerer');
    $cmd_id = (int)($_POST['id'] ?? 0);
    csrf_guard('commandes_fournisseur.php');

    $cmd = db_commande_fournisseur_get_by_id($pdo, $cmd_id);
    if (!$cmd || $cmd['statut'] !== 'Brouillon' || (int)$cmd['magasin_id'] !== $user_magasin_id) {
        flash_error('Commande introuvable ou non modifiable (seuls les brouillons de votre magasin peuvent être édités).');
        redirect('commandes_fournisseur.php');
    }

    $fournisseur_id = (int)($_POST['fournisseur_id'] ?? $cmd['fournisseur_id']);
    $magasin_id     = $user_magasin_id;
    $date_rec       = $_POST['date_reception_prevue'] ?? null;
    $notes          = input_string($_POST['notes'] ?? '');
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

    // Correction : db_commande_fournisseur_lignes_save() supprime d'abord TOUTES les
    // lignes existantes avant de ré-insérer. Sans ce garde-fou, soumettre le formulaire
    // avec un tableau de lignes vide (ou sans lignes valides) effaçait silencieusement
    // le contenu de la commande.
    if (empty($lignes)) {
        flash_error('Veuillez ajouter au moins un article avec une quantité valide.');
        redirect(generate_signed_url('commandes_fournisseur.php', $cmd_id, ['action' => 'editer_form']));
    }

    try {
        db_transaction(function(PDO $pdo) use ($cmd_id, $fournisseur_id, $magasin_id, $date_rec, $notes, $lignes) {
            db_commande_fournisseur_update($pdo, $cmd_id, [
                'fournisseur_id' => $fournisseur_id,
                'magasin_id'     => $magasin_id,
                'date_reception_prevue' => $date_rec ?: null,
                'notes'          => $notes
            ]);
            db_commande_fournisseur_lignes_save($pdo, $cmd_id, $lignes);
        }, '', 'Erreur lors de la mise à jour.', 'commandes_fournisseur.php');

        suivre_activite('COMMANDE_MODIFIEE', "Modification de la commande fournisseur #{$cmd_id}");
        flash_success("Commande #{$cmd_id} mise à jour.");
        redirect(generate_signed_url('commandes_fournisseur.php', $cmd_id, ['action' => 'voir']));
    } catch (Throwable $e) {
        error_log('Erreur édition commande: ' . $e->getMessage());
        flash_error('Erreur lors de la mise à jour de la commande.');
        redirect(generate_signed_url('commandes_fournisseur.php', $cmd_id, ['action' => 'editer_form']));
    }
}

// ---- ACTION POST : FLUX DE VALIDATION (SOUMETTRE / VALIDER / REJETER) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['soumettre', 'valider', 'rejeter', 'envoyer', 'annuler'], true)) {
    $cmd_id = (int)($_POST['id'] ?? 0);
    csrf_guard('commandes_fournisseur.php');

    $cmd = db_commande_fournisseur_get_by_id($pdo, $cmd_id);
    if (!$cmd || (int)$cmd['magasin_id'] !== $user_magasin_id) {
        flash_error('Commande introuvable ou ne concernant pas votre magasin.');
        redirect('commandes_fournisseur.php');
    }

    // 1. Soumettre : le magasinier demande la validation du Directeur
    if ($action === 'soumettre') {
        exiger_permission('achats_gerer');
        if ($cmd['statut'] === 'Brouillon') {
            db_commande_fournisseur_update_statut($pdo, $cmd_id, 'En_Attente');
            suivre_activite('COMMANDE_SOUMISE', "Commande fournisseur #{$cmd_id} soumise pour validation (chef équipe)");
            flash_success("Commande #{$cmd_id} soumise pour validation du chef équipe.");
        } else {
            flash_error("Seuls les brouillons peuvent être soumis (statut actuel : « {$cmd['statut']} »).");
        }
    }

    // 2. Valider / Envoyer : uniquement le Directeur (permission achats_valider)
    if (in_array($action, ['valider', 'envoyer'], true)) {
        exiger_permission('achats_valider');
        $statuts_acceptes = $action === 'valider' ? ['En_Attente'] : ['En_Attente', 'Brouillon'];
        if (in_array($cmd['statut'], $statuts_acceptes, true)) {
            db_commande_fournisseur_update_statut($pdo, $cmd_id, 'Envoyee');
            suivre_activite('COMMANDE_VALIDEE', "Commande fournisseur #{$cmd_id} validée et envoyée au fournisseur");
            flash_success("Commande #{$cmd_id} validée : elle est désormais effective et envoyée au fournisseur.");
        } else {
            flash_error("Action impossible : le statut actuel de la commande (« {$cmd['statut']} ») ne permet pas l'envoi.");
        }
    }

    // 3. Rejeter : retour au brouillon pour révision (uniquement le Directeur)
    if ($action === 'rejeter') {
        exiger_permission('achats_valider');
        if ($cmd['statut'] === 'En_Attente') {
            db_commande_fournisseur_update_statut($pdo, $cmd_id, 'Brouillon');
            suivre_activite('COMMANDE_REJETEE', "Commande fournisseur #{$cmd_id} renvoyée en brouillon par le chef équipe");
            flash_success("Commande #{$cmd_id} renvoyée en brouillon. Le magasinier peut la corriger.");
        } else {
            flash_error("Seules les commandes en attente de validation peuvent être rejetées.");
        }
    }

    // 4. Annulation (Brouillon, En_Attente ou Envoyee)
    if ($action === 'annuler') {
        exiger_permission('achats_gerer');
        if (in_array($cmd['statut'], ['Brouillon', 'En_Attente', 'Envoyee'], true)) {
            db_commande_fournisseur_update_statut($pdo, $cmd_id, 'Annulee');
            suivre_activite('COMMANDE_ANNULEE', "Annulation de la commande fournisseur #{$cmd_id}");
            flash_success("Commande #{$cmd_id} annulée.");
        } else {
            flash_error("Action impossible : le statut actuel de la commande (« {$cmd['statut']} ») ne le permet pas.");
        }
    }

    redirect(generate_signed_url('commandes_fournisseur.php', $cmd_id, ['action' => 'voir']));
}

// ---- ACTION POST : RÉCEPTION DE COMMANDE (PARTIELLE OU TOTALE) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'receptionner') {
    exiger_permission('achats_gerer');
    $cmd_id = (int)($_POST['id'] ?? 0);
    csrf_guard('commandes_fournisseur.php');

    $cmd = db_commande_fournisseur_get_by_id($pdo, $cmd_id);
    if (!$cmd || (int)$cmd['magasin_id'] !== $user_magasin_id || !in_array($cmd['statut'], ['Envoyee', 'Recue_Partielle'], true)) {
        flash_error('Commande introuvable, non réceptionnable à ce stade ou ne concernant pas votre magasin (attendez la validation).');
        redirect('commandes_fournisseur.php');
    }

    $receptions = $_POST['reception'] ?? [];
    if (!is_array($receptions)) {
        $receptions = [];
    }

    // Correction : renommage de $nb_reçus -> $nb_recus (identifiant non-ASCII fragile
    // selon l'encodage du fichier / des outils utilisés).
    $nb_recus = 0;
    try {
        db_transaction(function(PDO $pdo) use ($cmd_id, $receptions, $user_id, $cmd, &$nb_recus) {
            $lignes = db_commande_fournisseur_lignes_get($pdo, $cmd_id);
            foreach ($lignes as $l) {
                $lid = (int)$l['id'];
                $qte_rec_ajout = (int)($receptions[$lid] ?? 0);
                if ($qte_rec_ajout <= 0) {
                    continue;
                }

                $art_id = (int)$l['article_id'];
                $qte_restante = (int)$l['quantite_commandee'] - (int)$l['quantite_recue'];
                if ($qte_rec_ajout > $qte_restante) {
                    throw new RuntimeException("La quantité reçue pour « {$l['article_nom']} » excède le restant à recevoir ({$qte_restante}).");
                }

                // Incrémenter la quantité reçue sur la ligne de commande
                db_commande_fournisseur_lignes_update_reception($pdo, $lid, $qte_rec_ajout);

                // Entrée en stock via process_stock_movement
                // Coût réel : le prix d'achat unitaire saisi sur la ligne de commande
                // alimente le CUMP (voir valorisation_stock.sql).
                $motif = "Réception commande fournisseur #{$cmd_id}";
                $cout_unitaire = (float)($l['prix_achat_unitaire'] ?? 0);
                process_stock_movement($pdo, $art_id, 'Entree', $qte_rec_ajout, $motif, $user_id, (int)$cmd['magasin_id'], null, null, $cout_unitaire);

                $nb_recus += $qte_rec_ajout;
            }

            if ($nb_recus <= 0) {
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
        }, '', 'Erreur lors de la réception. Veuillez vérifier les quantités saisies.', generate_signed_url('commandes_fournisseur.php', $cmd_id, ['action' => 'voir']));

        suivre_activite('COMMANDE_RECUE', "Réception de {$nb_recus} produit(s) sur la commande #{$cmd_id}");
        flash_success("Réception enregistrée avec succès ({$nb_recus} produit(s) entré(s) en stock).");

    } catch (Throwable $e) {
        error_log('Erreur réception commande: ' . $e->getMessage());
        flash_error('Erreur lors de la réception. Veuillez vérifier les quantités saisies.');
    }

    redirect(generate_signed_url('commandes_fournisseur.php', $cmd_id, ['action' => 'voir']));
}

// ---- AFFICHAGE : VUE DÉTAILLÉE D'UNE COMMANDE ----
if ($action === 'voir' && $id_get > 0) {
    if (!verify_url_signature($id_get, input_string($_GET['token'] ?? ''), ['action' => 'voir'])) {
        flash_error('Lien expiré ou invalide.');
        redirect('commandes_fournisseur.php');
    }

    $cmd = db_commande_fournisseur_get_by_id($pdo, $id_get);
    if (!$cmd || (int)$cmd['magasin_id'] !== $user_magasin_id) {
        flash_error('Commande introuvable.');
        redirect('commandes_fournisseur.php');
    }

    $lignes = db_commande_fournisseur_lignes_get($pdo, $id_get);

    echo $twig->render('commandes_fournisseur.html.twig', [
        'titre_page'    => "Commande Fournisseur #{$id_get}",
        'page_courante' => 'commandes_fournisseur',
        'commande'      => $cmd,
        'lignes'        => $lignes,
        'vue'           => 'detail',
        'can_manage'    => $can_manage,
        'can_valider'   => $can_valider,
        'print_url'     => generate_signed_url('commande_fournisseur_print.php', $id_get, ['action' => 'imprimer'])
    ]);
    exit;
}

// ---- AFFICHAGE : FORMULAIRE DE CRÉATION / ÉDITION MANUELLE ----
if (in_array($action, ['nouveau', 'editer_form'], true) && $can_manage) {
    $cmd = null;
    $lignes = [];
    if ($action === 'editer_form' && $id_get > 0) {
        if (!verify_url_signature($id_get, input_string($_GET['token'] ?? ''), ['action' => 'editer_form'])) {
            flash_error('Lien expiré ou invalide.');
            redirect('commandes_fournisseur.php');
        }
        $cmd = db_commande_fournisseur_get_by_id($pdo, $id_get);
        if ($cmd && $cmd['statut'] === 'Brouillon' && (int)$cmd['magasin_id'] === $user_magasin_id) {
            $lignes = db_commande_fournisseur_lignes_get($pdo, $id_get);
        } else {
            flash_error('Commande introuvable ou non modifiable.');
            redirect('commandes_fournisseur.php');
        }
    }

    $fournisseurs = db_fournisseurs_list($pdo);
    $magasins     = db_magasins_list_all($pdo);

    // Récupération des articles actifs
    $stmt_art = $pdo->query("SELECT id, nom, code_barre, prix_achat, fournisseur_id FROM articles WHERE actif = 1 ORDER BY nom ASC");
    $articles = $stmt_art ? $stmt_art->fetchAll() : [];

    echo $twig->render('commandes_fournisseur.html.twig', [
        'titre_page'    => $cmd ? "Éditer Commande #{$cmd['id']}" : "Nouvelle Commande Fournisseur",
        'page_courante' => 'commandes_fournisseur',
        'commande'      => $cmd,
        'lignes'        => $lignes,
        'fournisseurs'  => $fournisseurs,
        'magasins'      => $magasins,
        'articles'      => $articles,
        'vue'           => 'formulaire',
        'can_manage'    => $can_manage,
        'can_valider'   => $can_valider
    ]);
    exit;
}

// ---- AFFICHAGE : LISTE DES COMMANDES FOURNISSEURS ----
$filters = [
    'statut'         => $_GET['statut'] ?? '',
    'fournisseur_id' => $_GET['fournisseur_id'] ?? '',
    'magasin_id'     => $user_magasin_id,
    'date_debut'     => $_GET['date_debut'] ?? '',
    'date_fin'       => $_GET['date_fin'] ?? '',
];

$search_data = db_commandes_fournisseur_search_sql($filters);
$stmt = $pdo->prepare($search_data['sql']);
$stmt->execute($search_data['params']);
$commandes = $stmt->fetchAll();

$fournisseurs = db_fournisseurs_list($pdo);
$magasins     = db_magasins_list_all($pdo);

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
