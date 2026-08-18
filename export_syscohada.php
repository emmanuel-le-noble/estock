<?php
/**
 * export_syscohada.php - Export comptable SYSCOHADA (OHADA) des écritures de
 * la période, destiné à votre comptable ou à un logiciel de comptabilité.
 *
 * Format généré (CSV, séparateur ';', UTF-8 avec BOM) —
 * 12 colonnes, nomenclature du plan comptable OHADA (SYSCOHADA révisé) :
 *   JournalCode; JournalLib; EcritureNum; EcritureDate; CompteNum;
 *   CompteLib; PieceRef; PieceDate; EcritureLib; Debit; Credit; Devise
 *
 * Journaux (générateurs comptables communs, cf. db_journal_* dans
 * db_functions.php) :
 *   - VT : ventes (factures Payee, annulations contre-passées)
 *   - AC : achats (commandes fournisseur reçues)
 *   - DG : dépenses d'exploitation
 *   - INV : variation de stocks (évaluée au CUMP, compte 31 / 6037)
 *
 * Couverture DSF (SYSCOHADA) : achats (607/401), ventes (707/4457),
 * trésorerie (53 caisse / 58 mobile money / 512 banque), stocks (31/6037).
 * Écritures en FCFA (devise de tenue de la boutique). En régime TPU
 * (TVA non applicable), aucun compte 4457 n'est alimenté.
 */
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_permission('conformite_export_syscohada');

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
        db_journal_ventes($pdo, $debut, $fin, $magasin_id),
        db_journal_achats($pdo, $debut, $fin, $magasin_id),
        db_journal_depenses($pdo, $debut, $fin, $magasin_id),
        db_journal_stocks($pdo, $debut, $fin, $magasin_id)
    );

    $deviseCode = strtoupper(trim(param('devise_code', 'XOF')));
    $entete = [
        'JournalCode', 'JournalLib', 'EcritureNum', 'EcritureDate',
        'CompteNum', 'CompteLib', 'PieceRef', 'PieceDate',
        'EcritureLib', 'Debit', 'Credit', 'Devise',
    ];

    // Tri global : par date d'écriture puis numéro
    usort($ecritures, static function (array $a, array $b): int {
        return strcmp($a['EcritureDate'] ?? '', $b['EcritureDate'] ?? '')
            ?: strcmp((string)($a['EcritureNum'] ?? ''), (string)($b['EcritureNum'] ?? ''));
    });

    $lignes = [];
    foreach ($ecritures as $e) {
        $datePiece = substr((string)($e['PieceDate'] ?? ''), 0, 10);
        $lignes[] = [
            $e['JournalCode'] ?? '',
            $e['JournalLib'] ?? '',
            $e['EcritureNum'] ?? '',
            substr((string)($e['EcritureDate'] ?? ''), 0, 10),
            $e['CompteNum'] ?? '',
            $e['CompteLib'] ?? '',
            $e['PieceRef'] ?? '',
            $datePiece,
            $e['EcritureLib'] ?? '',
            $e['MontantDebit'] ?? '',
            $e['MontantCredit'] ?? '',
            $deviseCode,
        ];
    }

    // Nommage : SYSO_<base>_<JJMMAAAA>_<JJMMAAAA>.csv
    $base = preg_replace('/[^A-Za-z0-9_-]/', '', DB_NAME);
    $fichier = sprintf(
        'SYSO_%s_%s_%s.csv',
        $base ?: 'stock',
        date('dmY', strtotime($debut)),
        date('dmY', strtotime($fin))
    );

    $esc = static function (string $v): string {
        return str_replace(["\r", "\n", ';'], ['', ' ', ','], $v);
    };

    $contenu = '';
    // Préambule : identité de l'entreprise + exercice (bloc commentaire)
    $preambule = [
        '# Export SYSCOHADA (OHADA) - eStock',
        '# Entreprise : ' . param_shop_name(),
        '# NIF : ' . ((string)param('nif_boutique', '') ?: 'non renseigne'),
        '# RCCM : ' . ((string)param('rccm_boutique', '') ?: 'non renseigne'),
        '# Exercice : ' . $debut . ' -> ' . $fin,
        '# Regime fiscal : ' . (param_regime_tpu() ? 'TPU (TVA non applicable)' : 'TVA ' . number_format(param_tva_taux(), 2, ',', ' ') . '%'),
        '# Monnaie : ' . $deviseCode,
        '',
    ];
    foreach ($preambule as $ligne) { $contenu .= $ligne . "\r\n"; }
    $contenu .= implode(';', $entete) . "\r\n";
    foreach ($lignes as $l) {
        $contenu .= implode(';', array_map($esc, $l)) . "\r\n";
    }

    suivre_activite('SYSCOHADA_EXPORT', 'Export SYSCOHADA ' . $debut . ' -> ' . $fin
        . ' (' . count($ecritures) . ' écritures)');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fichier . '"');
    echo "\xEF\xBB\xBF" . $contenu;
    exit;
}

// ---- Aperçu ----
$apercu = [
    'ventes'   => count(db_journal_ventes($pdo, $debut, $fin, $magasin_id)),
    'achats'   => count(db_journal_achats($pdo, $debut, $fin, $magasin_id)),
    'depenses' => count(db_journal_depenses($pdo, $debut, $fin, $magasin_id)),
    'stocks'   => count(db_journal_stocks($pdo, $debut, $fin, $magasin_id)),
];

echo $twig->render('export_syscohada.html.twig', [
    'titre_page' => 'Export SYSCOHADA (écritures comptables)',
    'debut'      => $debut,
    'fin'        => $fin,
    'magasin_id' => $magasin_id,
    'magasins'   => $magasins,
    'apercu'     => $apercu,
    'email_utilisateur' => (string)(user_courant()['email'] ?? ''),
]);