<?php
/**
 * conformite.php - Traçabilité et intégrité des ventes.
 *
 * Bonnes pratiques de traçabilité comptable (non liées à une norme
 * étrangère) :
 *  1. Vérification de l'intégrité de la chaîne cryptographique des factures
 *     (incluant les clôtures de caisse) + rattrapage (backfill) des factures
 *     antérieures à l'activation.
 *  2. Archivage périodique des données de caisse : export signé (HMAC) sur
 *     une période, conservation longue configurable (>= 6 ans par défaut)
 *     au titre de la traçabilité fiscale OTR.
 */
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_permission('conformite_archives');

$archiveDir = __DIR__ . '/archives';
if (!is_dir($archiveDir)) { @mkdir($archiveDir, 0755, true); }

// ---- Téléchargement d'une archive signée ----
if ($_SERVER['REQUEST_METHOD'] === 'GET'
    && ($_GET['action'] ?? '') === 'download'
    && !empty($_GET['id'])) {
    if (!verify_url_signature((int)$_GET['id'], input_string($_GET['t'] ?? ''), ['action' => 'download'])) {
        flash_error('Lien invalide ou expiré.');
        redirect('conformite.php');
    }
    $stmt = $pdo->prepare("SELECT * FROM archives_caisse WHERE id = ?");
    $stmt->execute([(int)$_GET['id']]);
    $archive = $stmt->fetch();
    if ($archive && $archive['fichier_archive']) {
        $path = $archiveDir . '/' . basename((string)$archive['fichier_archive']);
        if (is_file($path)) {
            suivre_activite('ARCHIVE_DOWNLOAD', 'Téléchargement archive ' . $archive['periode_debut'] . ' → ' . $archive['periode_fin']);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            readfile($path);
            exit;
        }
        flash_error('Fichier d\'archive introuvable sur le serveur.');
    }
    redirect('conformite.php');
}

// ---- Actions POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard('conformite.php');
    $action = $_POST['action'] ?? '';

    // 1) Rattrapage du chaînage des factures antérieures
    if ($action === 'backfill') {
        $traitees = db_facture_chainage_backfill($pdo);
        suivre_activite('CHAINAGE_BACKFILL', 'Rattrapage chaînage : ' . $traitees . ' facture(s)');
        flash_success($traitees . ' facture(s) chaînée(s) cryptographiquement.');
        redirect('conformite.php');
    }

    // 2) Création d'une archive de période (export signé)
    if ($action === 'creer_archive') {
        $data = extract_post_data([
            'debut'      => ['type' => 'string', 'required' => true, 'max' => 10, 'redirect' => 'conformite.php'],
            'fin'        => ['type' => 'string', 'required' => true, 'max' => 10, 'redirect' => 'conformite.php'],
            'magasin_id' => ['type' => 'int', 'min' => 0],
        ], 'conformite.php');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['debut']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['fin'])) {
            flash_error('Format de période invalide.');
            redirect('conformite.php');
        }
        if ($data['fin'] < $data['debut']) {
            flash_error('La date de fin doit être postérieure à la date de début.');
            redirect('conformite.php');
        }

        try {
            if (db_archive_get_by_periode($pdo, $data['debut'], $data['fin'], $data['magasin_id'])) {
                throw new RuntimeException('Une archive existe déjà pour cette période (miroir).');
            }
            $periode = db_archives_periode_data($pdo, $data['debut'], $data['fin'], $data['magasin_id']);
            if ($periode['nb_factures'] === 0) {
                throw new RuntimeException('Aucune vente sur la période. Rien à archiver.');
            }
            // Signature HMAC du sommet de chaîne (clé applicative) + fichier JSON
            $signature = hash_hmac('sha256', $periode['hash_sommet'], SECRET_URL_KEY);
            $annees = max(1, param_int('archives_conservation_annees', 6));
            $fichier = 'archive_' . str_replace('-', '', $data['debut']) . '_' . str_replace('-', '', $data['fin']) . '.json';

            $contenu = json_encode([
                'outil'        => 'eStock — archives caisse',
                'periode'      => ['debut' => $data['debut'], 'fin' => $data['fin']],
                'magasin_id'   => $data['magasin_id'],
                'nb_factures'  => $periode['nb_factures'],
                'nb_paiements' => $periode['nb_paiements'],
                'total_ht'     => $periode['total_ht'],
                'total_tva'    => $periode['total_tva'],
                'total_ttc'    => $periode['total_ttc'],
                'hash_sommet'  => $periode['hash_sommet'],
                'signature'    => $signature,
                'factures'     => array_map(static function (array $f): array {
                    return [
                        'id'          => (int)$f['id'],
                        'numero'      => $f['numero_facture'],
                        'date'        => $f['date_facture'],
                        'statut'      => $f['statut'],
                        'total_ttc'   => (float)$f['total_ttc'],
                        'hash_chaine' => $f['hash_chaine'] ?? null,
                    ];
                }, $periode['factures']),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            if (file_put_contents($archiveDir . '/' . $fichier, $contenu) === false) {
                throw new RuntimeException('Écriture du fichier d\'archive impossible.');
            }

            db_archive_insert($pdo, [
                'magasin_id'      => $data['magasin_id'],
                'periode_debut'   => $data['debut'],
                'periode_fin'     => $data['fin'],
                'nb_factures'     => $periode['nb_factures'],
                'nb_paiements'    => $periode['nb_paiements'],
                'total_ht'        => $periode['total_ht'],
                'total_tva'       => $periode['total_tva'],
                'total_ttc'       => $periode['total_ttc'],
                'hash_sommet'     => $periode['hash_sommet'],
                'signature'       => $signature,
                'fichier_archive' => $fichier,
                'conserve_jusqua' => date('Y-m-d', strtotime('+' . (int)$annees . ' years', strtotime($data['fin']))),
            ]);
            suivre_activite('ARCHIVE_CREEE', 'Archive caisse ' . $data['debut'] . ' → ' . $data['fin']
                . ' (' . $periode['nb_factures'] . ' factures, ' . $periode['total_ttc'] . ' TTC)');
            flash_success('Archive créée et signée pour la période ' . $data['debut'] . ' → ' . $data['fin'] . '.');
        } catch (Throwable $e) {
            error_log('Erreur création archive: ' . $e->getMessage());
            flash_error('Création d\'archive impossible. Consultez le journal technique si le problème persiste.');
        }
        redirect('conformite.php');
    }
    redirect('conformite.php');
}

// ---- Vue GET : intégrité + archives ----
$magasins = peut_administrer() ? db_magasins_list_all($pdo) : [];

$verification = db_facture_chainage_actif($pdo)
    ? db_facture_chainage_verifier($pdo)
    : ['ok' => false, 'total' => 0, 'verifiees' => 0, 'manquantes' => 0, 'erreurs' => []];

$f_arch_debut = $_GET['arch_debut'] ?? '';
$f_arch_fin   = $_GET['arch_fin'] ?? '';
$f_arch_statut = $_GET['arch_statut'] ?? '';
$search_sql = db_archives_search_sql([
    'debut'  => $f_arch_debut,
    'fin'    => $f_arch_fin,
    'statut' => $f_arch_statut,
], peut_administrer() ? 0 : user_magasin_id());
$result = paginate($search_sql['sql'], $search_sql['params'], 20);
$archives = $result['items'];
foreach ($archives as &$a) {
    $a['lien_download'] = generate_signed_url('conformite.php', (int)$a['id'], ['action' => 'download']);
}
unset($a);

echo $twig->render('conformite.html.twig', [
    'titre_page'      => 'Traçabilité des ventes',
    'verification'    => $verification,
    'chainage_actif'  => db_facture_chainage_actif($pdo),
    'nb_non_chainees' => (int)$pdo->query("SELECT COUNT(*) FROM factures WHERE hash_chaine IS NULL")->fetchColumn(),
    'archives'        => $archives,
    'page'            => $result['page'],
    'total_pages'     => $result['total_pages'],
    'total'           => $result['total'],
    'arch_debut'      => $f_arch_debut,
    'arch_fin'        => $f_arch_fin,
    'arch_statut'     => $f_arch_statut,
    'annees_conservation' => param_int('archives_conservation_annees', 6),
    'magasin_id_defaut'   => user_magasin_id(),
    'magasins_dispo'      => $magasins,
    'can_export'          => peut('conformite_export_syscohada'),
]);
