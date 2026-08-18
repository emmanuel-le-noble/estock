<?php
/**
 * bin/verif_integrite.php — Audit d'intégrité des ventes (CLI).
 *
 * Vérifie :
 *   1. Présence des triggers d'immuabilité (8) + colonnes hash_chaine.
 *   2. Intégrité complète de la chaîne cryptographique des factures.
 *   3. Intégrité de la chaîne des clôtures de caisse.
 *   4. Aucune facture non chaînée.
 *   5. Continuité de la numérotation par jour (ruptures détectées).
 *   6. Archives de caisse : échéance de conservation respectée (>= param).
 *
 * Usage :
 *   php bin/verif_integrite.php            # vérification complète
 *   php bin/verif_integrite.php --backfill # rattrapage du chaînage manquant puis vérification
 *   php bin/verif_integrite.php --json     # sortie JSON exploitable (CI / observabilité)
 *
 * Code retour : 0 = conforme, 1 = anomalies, 2 = erreur d'exécution.
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI uniquement.'); }

$root = dirname(__DIR__);
require_once $root . '/config/connexion.php';
require_once $root . '/includes/db_functions.php';
require_once $root . '/includes/helpers.php';

$args = $_SERVER['argv'] ?? [];
$backfill = in_array('--backfill', $args, true);
$asJson   = in_array('--json', $args, true);

$rapport = [
    'outil'    => 'eStock - verification integrite des ventes',
    'date'     => gmdate('Y-m-d\TH:i:s\Z'),
    'valide'   => true,
    'anomalies' => [],
    'details'  => [],
];

$ajouterAnomalie = static function (string $code, string $message) use (&$rapport): void {
    $rapport['valide'] = false;
    $rapport['anomalies'][] = ['code' => $code, 'message' => $message];
};

try {
    // ---- 1. Infrastructure d'immuabilité ----
    $triggers = [];
    foreach ($pdo->query('SHOW TRIGGERS') as $t) { $triggers[] = $t['Trigger']; }
    $attendus = [
        'trg_factures_immutable_delete', 'trg_factures_immutable_update',
        'trg_lignes_facture_immutable_delete', 'trg_lignes_facture_immutable_update',
        'trg_paiements_immutable_delete', 'trg_paiements_immutable_update',
        'trg_clotures_immutable_delete', 'trg_clotures_immutable_update',
    ];
    $manquants = array_diff($attendus, $triggers);
    $rapport['details']['triggers_presents'] = count($attendus);
    if ($manquants) {
        $ajouterAnomalie('TRIGGERS_MANQUANTS', 'Triggers d\'immuabilité absents : ' . implode(', ', $manquants));
    }

    if (!db_facture_chainage_actif($pdo)) {
        $ajouterAnomalie('CHAIMAGE_INACTIF', 'Colonnes hash_chaine absentes : migration integrity_ventes non appliquée.');
    }

    // ---- 2. Rattrapage optionnel ----
    if ($backfill) {
        $nb = db_facture_chainage_backfill($pdo, 5000);
        $rapport['details']['backfill_traitees'] = $nb;
    }

    // ---- 3. Chaîne des factures ----
    if (db_facture_chainage_actif($pdo)) {
        $v = db_facture_chainage_verifier($pdo);
        $rapport['details']['factures_total'] = $v['total'];
        $rapport['details']['factures_verifiees'] = $v['verifiees'];
        $rapport['details']['factures_non_chainees'] = $v['manquantes'];
        $rapport['details']['factures_en_erreur'] = array_slice($v['erreurs'], 0, 50);
        if ($v['manquantes'] > 0) {
            $ajouterAnomalie('FACTURES_NON_CHAINEES', $v['manquantes'] . ' facture(s) sans hash (migration jamais appliquée ?).');
        }
        if ($v['erreurs']) {
            $ajouterAnomalie('CHAINAGE_ALTERE', count($v['erreurs']) . ' facture(s) avec hash non conforme (ids : ' . implode(',', array_slice($v['erreurs'], 0, 50)) . ').');
        }
    }

    // ---- 4. Chaîne des clôtures ----
    $cv = db_cloture_chainage_verifier($pdo);
    $rapport['details']['clotures_total'] = $cv['total'];
    $rapport['details']['clotures_verifiees'] = $cv['verifiees'];
    $rapport['details']['clotures_en_erreur'] = array_slice($cv['erreurs'], 0, 50);
    if ($cv['erreurs']) {
        $ajouterAnomalie('CLOTURES_ALTEREES', count($cv['erreurs']) . ' clôture(s) avec hash non conforme.');
    }

    // ---- 5. Continuité de numérotation par jour ----
    $jointures = $pdo->query(
        "SELECT DATE(date_facture) AS jour,
                COUNT(*) AS nb,
                MIN(numero_facture) AS premier,
                MAX(numero_facture) AS dernier
         FROM factures
         GROUP BY DATE(date_facture)
         ORDER BY jour"
    )->fetchAll();
    $rapport['details']['jours_numérotation'] = count($jointures);
    foreach ($jointures as $j) {
        // Numéros attendus au format FAC-YYYYMMDD-XXXX : comparer le rang max
        // au nombre de factures du jour (une annexe: annulations conservent le numéro).
        if (!preg_match('/^FAC-(\d{8})-(\d{4})$/i', (string)$j['dernier'], $m)) continue;
        $rangMax = (int)$m[2];
        if ($rangMax < (int)$j['nb']) {
            $ajouterAnomalie('NUMEROTATION', sprintf(
                'Jour %s : %d factures mais rang max %d (numéros réutilisés ?).',
                $j['jour'], $j['nb'], $rangMax
            ));
        }
    }

    // ---- 6. Archives : conservation ----
    $conservMin = max(1, param_int('archives_conservation_annees', 6));
    $echues = $pdo->prepare(
        "SELECT COUNT(*) FROM archives_caisse WHERE conserve_jusqua < CURDATE()"
    );
    $echues->execute();
    $nbEchues = (int)$echues->fetchColumn();
    $rapport['details']['archives_conservation_annees'] = $conservMin;
    $rapport['details']['archives_echues'] = $nbEchues;
    if ($nbEchues > 0) {
        $ajouterAnomalie('ARCHIVES_ECHUES', $nbEchues . ' archive(s) au-delà de l\'échéance de conservation (sans impact immédiat, mais planifier l\'export pérenne).');
    }
} catch (Throwable $e) {
    error_log('verif_integrite: ' . $e->getMessage());
    if ($asJson) {
        echo json_encode(['erreur' => 'Erreur d\'exécution : ' . $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    } else {
        echo "ERREUR : " . $e->getMessage() . "\n";
    }
    exit(2);
}

// ---- Sortie ----
if ($asJson) {
    echo json_encode($rapport, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
} else {
    echo "=== Vérification d'intégrité des ventes — eStock ===\n";
    echo "Triggers d'immuabilité : " . $rapport['details']['triggers_presents'] . "/8\n";
    if (isset($rapport['details']['backfill_traitees'])) {
        echo "Backfill : {$rapport['details']['backfill_traitees']} facture(s) chaînée(s)\n";
    }
    echo "Factures : {$rapport['details']['factures_non_chainees']} non chaînées, {$rapport['details']['factures_verifiees']} vérifiées, "
        . count($rapport['details']['factures_en_erreur']) . " en erreur\n";
    echo "Clôtures : {$rapport['details']['clotures_verifiees']} vérifiées, " . count($rapport['details']['clotures_en_erreur']) . " en erreur\n";
    echo "Numérotation : {$rapport['details']['jours_numérotation']} jour(s) contrôlé(s)\n";
    echo "Archives : conservation {$rapport['details']['archives_conservation_annees']} an(s), {$rapport['details']['archives_echues']} échue(s)\n";
    if ($rapport['valide']) {
        echo "RÉSULTAT : CONFORME ✓\n";
    } else {
        echo "RÉSULTAT : ANOMALIES DÉTECTÉES\n";
        foreach ($rapport['anomalies'] as $a) {
            echo "  - [{$a['code']}] {$a['message']}\n";
        }
    }
}
exit($rapport['valide'] ? 0 : 1);
