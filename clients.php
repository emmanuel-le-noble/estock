<?php
/**
 * clients.php - Fichier clients : programme de fidélité + protection
 * des données personnelles.
 *
 *  - CRUD des fiches clients (B2C / B2B : raison sociale, NIF, RCCM, adresse)
 *  - Consentement explicite du programme de fidélité (traçabilité)
 *  - Consultation solde et historique de points
 *  - Anonymisation des données personnelles (ne casse pas la comptabilité)
 *  - Purge automatique manuelle (politique de conservation)
 */
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_permission('clients_consulter');

$peut_gerer = peut('clients_gerer');

// ---- Actions POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$peut_gerer) {
        flash_error('Droits insuffisants.');
        redirect('clients.php');
    }
    csrf_guard('clients.php');
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['client_id'] ?? 0);

    if ($action === 'ajouter' || $action === 'modifier') {
        $data = extract_post_data([
            'nom'              => ['type' => 'string', 'nullable' => true, 'max' => 150, 'redirect' => 'clients.php'],
            'raison_sociale'   => ['type' => 'string', 'nullable' => true, 'max' => 200, 'redirect' => 'clients.php'],
            'nif'              => ['type' => 'string', 'nullable' => true, 'max' => 30, 'redirect' => 'clients.php'],
            'rccm'             => ['type' => 'string', 'nullable' => true, 'max' => 30, 'redirect' => 'clients.php'],
            'adresse'          => ['type' => 'string', 'nullable' => true, 'max' => 255, 'redirect' => 'clients.php'],
            'telephone'        => ['type' => 'string', 'nullable' => true, 'max' => 30, 'redirect' => 'clients.php'],
            'email'            => ['type' => 'string', 'nullable' => true, 'max' => 150, 'redirect' => 'clients.php'],
            'date_naissance'   => ['type' => 'string', 'nullable' => true, 'max' => 10, 'redirect' => 'clients.php'],
            'code_fidelite'    => ['type' => 'string', 'nullable' => true, 'max' => 40, 'redirect' => 'clients.php'],
            'consentement_fidelite' => ['type' => 'bool'],
        ], 'clients.php');

        if ($data['nom'] === null && $data['raison_sociale'] === null) {
            flash_error('Renseignez au moins un nom (B2C) ou une raison sociale (B2B).');
            redirect('clients.php');
        }
        if (!empty($data['date_naissance']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date_naissance'])) {
            $data['date_naissance'] = null;
        }

        try {
            if ($action === 'ajouter') {
                $newId = db_client_insert($pdo, array_merge($data, [
                    'source_consentement' => 'interface',
                ]));
                if (!empty($data['consentement_fidelite'])) {
                    db_consentement_log($pdo, $newId, 'fidelite', true);
                }
                suivre_activite('CLIENT_CREE', 'Fiche client #' . $newId);
                flash_success('Client créé.');
            } else {
                if ($id <= 0 || !db_client_get_by_id($pdo, $id)) {
                    flash_error('Client introuvable.');
                    redirect('clients.php');
                }
                $avant = db_client_get_by_id($pdo, $id);
                db_client_update($pdo, $id, array_merge($data, [
                    'source_consentement' => 'interface',
                ]));
                if (array_key_exists('consentement_fidelite', $data)
                    && (bool)$avant['consentement_fidelite'] !== (bool)$data['consentement_fidelite']) {
                    db_consentement_log($pdo, $id, 'fidelite', (bool)$data['consentement_fidelite']);
                }
                suivre_activite('CLIENT_MODIFIE', 'Fiche client #' . $id);
                flash_success('Client mis à jour.');
            }
        } catch (Throwable $e) {
            error_log('Erreur fiche client: ' . $e->getMessage());
            flash_error('Enregistrement impossible : code fidélité peut-être déjà utilisé.');
        }
        redirect($action === 'ajouter' ? 'clients.php' : 'clients.php?action=voir&id=' . $id);
    }

    if ($action === 'anonymiser') {
        if ($id <= 0) { flash_error('Client invalide.'); redirect('clients.php'); }
        $motif = mb_substr(trim((string)($_POST['motif'] ?? 'Demande du client (anonymisation)')), 0, 200);
        if (db_client_anonymiser($pdo, $id, $motif)) {
            flash_success('Client anonymisé : les données personnelles sont remplacées, la comptabilité est conservée.');
        } else {
            flash_error('Client introuvable.');
        }
        suivre_activite('EFFACEMENT_CLIENT', 'Anonymisation demandée pour le client #' . $id);
        redirect('clients.php');
    }

    if ($action === 'ajuster_points') {
        if ($id <= 0) { flash_error('Client invalide.'); redirect('clients.php'); }
        $delta = (int)($_POST['delta_points'] ?? 0);
        $motif = trim((string)($_POST['motif_points'] ?? ''));
        if ($delta === 0) {
            flash_error('Saisissez une variation de points (positive ou négative).');
            redirect('clients.php?action=voir&id=' . $id);
        }
        if (db_client_ajuster_points($pdo, $id, $delta, $motif)) {
            flash_success('Solde de points ajusté (' . ($delta > 0 ? '+' : '') . $delta . ' pts).');
        } else {
            flash_error('Ajustement impossible : client introuvable ou erreur technique.');
        }
        redirect('clients.php?action=voir&id=' . $id);
    }

    if ($action === 'purger') {
        $result = db_clients_purger($pdo,
            (int)param('retention_clients_mois', '36'),
            12
        );
        suivre_activite('PURGE_CLIENTS', 'Purge conservation : ' . $result['anonymises'] . ' anonymisé(s), ' . $result['supprimes'] . ' supprimé(s)');
        flash_success('Purge : ' . $result['anonymises'] . ' client(s) anonymisé(s), ' . $result['supprimes'] . ' supprimé(s) physiquement.');
        redirect('clients.php');
    }

    redirect('clients.php');
}

// ---- Vue : détail client ----
$action = $_GET['action'] ?? 'liste';
$idView = (int)($_GET['id'] ?? 0);

if ($action === 'voir' && $idView > 0) {
    $client = db_client_get_by_id($pdo, $idView);
    if (!$client) {
        flash_error('Client introuvable.');
        redirect('clients.php');
    }
    $historique = db_client_points_historique($pdo, $idView, 100);
    $consentements = $pdo->prepare("SELECT * FROM consentements_log WHERE client_id = ? ORDER BY date_action DESC LIMIT 20");
    $consentements->execute([$idView]);

    $achats = $pdo->prepare(
        "SELECT f.id, f.numero_facture, f.date_facture, f.total_ttc, f.magasin_id, f.remise_fidelite, f.points_utilises, m.nom AS magasin_nom
         FROM factures f LEFT JOIN magasins m ON m.id = f.magasin_id
         WHERE f.client_id = ? ORDER BY f.date_facture DESC LIMIT 50"
    );
    $achats->execute([$idView]);
    $achats_liste = $achats->fetchAll();

    // Points gagnés (GAIN) à l'achat, par facture — pour l'historique des achats
    $pointsParFacture = [];
    if ($achats_liste) {
        $in = implode(',', array_map('intval', array_column($achats_liste, 'id')));
        $st_pts = $pdo->query(
            "SELECT facture_id, points FROM historique_points
             WHERE facture_id IN ($in) AND type_operation = 'GAIN'"
        );
        foreach ($st_pts->fetchAll() as $p) {
            $pointsParFacture[(int)$p['facture_id']] = (int)$p['points'];
        }
    }

    echo $twig->render('clients_detail.html.twig', [
        'titre_page'   => 'Client — ' . ($client['nom'] ?: $client['raison_sociale']),
        'client'       => $client,
        'historique'   => $historique,
        'consentements'=> $consentements->fetchAll(),
        'achats'       => $achats_liste,
        'points_par_facture' => $pointsParFacture,
        'peut_gerer'   => $peut_gerer,
        'fidelite_actif' => param_bool('fidelite_actif', false),
        'carte_lien'   => $peut_gerer && param_bool('fidelite_actif', false)
            ? generate_signed_url('carte_fidelite.php', $idView)
            : '',
    ]);
    exit;
}

// ---- Vue : liste + recherche ----
$search = input_string($_GET['search'] ?? '');
$result = paginate(db_clients_search_sql(['search' => $search])['sql'], db_clients_search_sql(['search' => $search])['params'], 25);
$clients = $result['items'];

echo $twig->render('clients.html.twig', [
    'titre_page' => 'Clients & fidélité',
    'clients'    => $clients,
    'page'       => $result['page'],
    'total_pages'=> $result['total_pages'],
    'total'      => $result['total'],
    'search'     => $search,
    'peut_gerer' => $peut_gerer,
    'fidelite_actif' => param_bool('fidelite_actif', false),
    'retention_mois' => param_int('retention_clients_mois', 36),
]);
