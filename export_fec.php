<?php
/**
 * export_fec.php - Export FEC (Fichier des Écritures Comptables) conforme
 * à la norme DGFiP (arrêté du 26/12/2013) : code général des impôts,
 * article 47 A.
 *
 * Génère un fichier CSV (séparateur ';', point décimal) à 17 colonnes
 * obligatoires : JournalCode, JournalLib, EcritureNum, EcritureDate,
 * CompteNum, CompteLib, CompAuxLib, PieceRef, PieceDate, EcritureLib,
 * Debit, Credit, EcritureLet, DateLet, ValidDate, Montantdevise, Idevise.
 *
 * Écritures prises en compte sur la période :
 *   - VT : ventes (factures Payee + annulations comptables)
 *   - AC : achats (commandes fournisseur reçues)
 *   - DG : dépenses d'exploitation
 */
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_permission('conformite_export_fec');

$debut = $_GET['debut'] ?? '';
$fin   = $_GET['fin'] ?? '';
$magasin_id = (int)($_GET['magasin_id'] ?? user_magasin_id());

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut)) $debut = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin))   $fin   = date('Y-m-d');
if ($fin < $debut) $fin = $debut;

$magasins = peut_administrer() ? db_magasins_list_all($pdo) : [];

// ---- Export fichier ----
if (isset($_GET['export']) && $_GET['export'] === '1') {
    $ecritures = array_merge(
        db_fec_factures($pdo, $debut, $fin, $magasin_id),
        db_fec_achats($pdo, $debut, $fin, $magasin_id),
        db_fec_depenses($pdo, $debut, $fin, $magasin_id)
    );

    // Colonnes FEC obligatoires (17). Monnaie : devise boutique (parametres).
    $deviseCode = strtoupper(trim(param('devise_code', 'XOF')));
    $entete = [
        'JournalCode', 'JournalLib', 'EcritureNum', 'EcritureDate',
        'CompteNum', 'CompteLib', 'CompAuxLib', 'PieceRef', 'PieceDate',
        'EcritureLib', 'Debit', 'Credit', 'EcritureLet', 'DateLet',
        'ValidDate', 'Montantdevise', 'Idevise',
    ];

    $lignes = [];
    // Tri global : par date d'écriture puis numéro
    usort($ecritures, static function (array $a, array $b): int {
        return strcmp($a['EcritureDate'] ?? '', $b['EcritureDate'] ?? '')
            ?: strcmp((string)($a['EcritureNum'] ?? ''), (string)($b['EcritureNum'] ?? ''));
    });

    foreach ($ecritures as $e) {
        $dateEcriture = substr((string)($e['EcritureDate'] ?? ''), 0, 10);
        $lignes[] = [
            $e['JournalCode'] ?? '',
            $e['JournalLib'] ?? '',
            $e['EcritureNum'] ?? '',
            $dateEcriture,
            $e['CompteNum'] ?? '',
            $e['CompteLib'] ?? '',
            '', // CompAuxLib (pas de plan tiers détaillé)
            $e['PieceRef'] ?? '',
            substr((string)($e['PieceDate'] ?? $dateEcriture), 0, 10),
            $e['EcritureLib'] ?? '',
            $e['MontantDebit'] ?? '',
            $e['MontantCredit'] ?? '',
            '',                      // EcritureLet
            '',                      // DateLet
            $dateEcriture,           // ValidDate
            '',                      // Montantdevise : vide, tenue des comptes en devise boutique
            $deviseCode,             // Idevise : code ISO 4217 de la devise de tenue
        ];
    }

    // Nommage FEC exigé par la DGFiP : AMAI_<SIREN>_<JJMMAAAA>_<JJMMAAAA>_FEC.csv
    $siren = preg_replace('/[^0-9]/', '', (string)param('siret_boutique', ''));
    $siren = substr($siren, 0, 9) !== '' ? substr($siren, 0, 9) : 'N/A';
    $fichier = sprintf(
        'AMAI_%s_%s_%s_FEC.csv',
        $siren,
        date('dmY', strtotime($debut)),
        date('dmY', strtotime($fin))
    );

    $esc = static function (string $v): string {
        return str_replace(["\r", "\n", ';'], ['', ' ', ','], $v);
    };

    $contenu = '';
    $contenu .= implode(';', $entete) . "\r\n";
    foreach ($lignes as $l) {
        $contenu .= implode(';', array_map($esc, $l)) . "\r\n";
    }

    suivre_activite('FEC_EXPORT', 'Export FEC ' . $debut . ' → ' . $fin
        . ' (' . count($ecritures) . ' écritures)');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fichier . '"');
    // BOM UTF-8 pour compatibilité tableur
    echo "\xEF\xBB\xBF" . $contenu;
    exit;
}

// ---- Compteurs pour l'aperçu ----
$apercu = [
    'ventes'    => count(db_fec_factures($pdo, $debut, $fin, $magasin_id)),
    'achats'    => count(db_fec_achats($pdo, $debut, $fin, $magasin_id)),
    'depenses'  => count(db_fec_depenses($pdo, $debut, $fin, $magasin_id)),
];

echo $twig->render('export_fec.html.twig', [
    'titre_page' => 'Export FEC (écritures comptables)',
    'debut'      => $debut,
    'fin'        => $fin,
    'magasin_id' => $magasin_id,
    'magasins'   => $magasins,
    'apercu'     => $apercu,
    'email_utilisateur' => (string)(user_courant()['email'] ?? ''),
]);