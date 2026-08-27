<?php
/**
 * emails_worker.php - Worker CLI de la file d'attente e-mails (eStock).
 *
 * Traite les e-mails EN_ATTENTE de façon asynchrone (non bloquant pour les
 * requêtes web) avec :
 *   * re-vérification du consentement avant envoi (opposition / opt-in)
 *   * tentatives avec backoff exponentiel (1min, 5min, 15min, 1h, 6h, 24h)
 *   * purge des messages traités (durée paramétrée)
 *   * aucune donnée sensible en sortie : erreurs génériques + error_log
 *
 * Usage :
 *   php bin/emails_worker.php            # 1 cycle : 50 e-mails + purge
 *   php bin/emails_worker.php --limit 5  # limite de traitement
 *   php bin/emails_worker.php --once     # 1 e-mail puis purge
 *   php bin/emails_worker.php --purge    # purge uniquement
 *   php bin/emails_worker.php --stats    # état de la file
 *
 * Convention crontab toutes les minutes :
 *   * * * * * php /chemin/eSotck/bin/emails_worker.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Ce script doit être exécuté en ligne de commande (CLI).\n");
}

require_once __DIR__ . '/../config/connexion.php';
require_once __DIR__ . '/../includes/db_functions.php';
require_once __DIR__ . '/../includes/helpers.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    exit("Erreur : connexion PDO indisponible.\n");
}

const BACKOFF_MINUTES = [1, 5, 15, 60, 360, 1440];

$args = $_SERVER['argv'] ?? [];
$limit  = 50;
$once   = false;
$stats  = false;
$purge  = false;
foreach ($args as $i => $arg) {
    if ($arg === '--limit' && isset($args[$i + 1])) {
        $limit = max(1, (int)$args[$i + 1]);
    }
    if ($arg === '--once')   { $once = true; }
    if ($arg === '--stats')  { $stats = true; }
    if ($arg === '--purge')  { $purge = true; }
}
if ($once) $limit = 1;

function worker_log(string $message): void {
    echo date('H:i:s') . "  $message\n";
}

function emails_stats(PDO $pdo): array {
    $stats = ['EN_ATTENTE' => 0, 'ENVOYE' => 0, 'ECHEC' => 0, 'total' => 0, 'prochaine_batch' => 0];
    $stmt = $pdo->query("SELECT statut, COUNT(*) AS nb FROM emails_queue GROUP BY statut");
    foreach ($stmt->fetchAll() as $row) {
        $stats[$row['statut']] = (int)$row['nb'];
        $stats['total'] += (int)$row['nb'];
    }
    $stmt = $pdo->query("SELECT COUNT(*) FROM emails_queue WHERE statut='EN_ATTENTE' AND prochaine_tentative <= NOW()");
    $stats['prochaine_batch'] = (int)$stmt->fetchColumn();
    return $stats;
}

// ============================================================
//  MODE STATS / PURGE
// ============================================================
if ($stats) {
    $s = emails_stats($pdo);
    echo "=== File e-mails ===\n";
    foreach ($s as $k => $v) {
        echo "  $k : $v\n";
    }
    exit(0);
}

$conservation_jours = max(1, param_int('emails_conservation_jours', 30));
$purged = email_queue_purger($pdo, $conservation_jours);
if ($purged > 0) {
    worker_log("Purge conservation : $purged e-mail(s) traité(s) supprimé(s) (> {$conservation_jours}j).");
}

if ($purge) {
    exit(0);
}

// ============================================================
//  TRAITEMENT DU LOT (verrouillage FOR UPDATE anti double-envoi)
// ============================================================
$max_tentatives = max(1, param_int('emails_tentatives_max', 5));

try {
    $pdo->beginTransaction();

    $sql = "SELECT id, destinataire, type, sujet, corps_html, nb_tentatives
            FROM emails_queue
            WHERE statut = 'EN_ATTENTE' AND prochaine_tentative <= NOW()
            ORDER BY date_creation ASC
            LIMIT ? FOR UPDATE";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$limit]);
    $lots = $stmt->fetchAll();

    if (empty($lots)) {
        $pdo->rollBack();
        worker_log("Aucun e-mail en attente.");
        exit(0);
    }

    worker_log(count($lots) . " e-mail(s) à traiter.");

    foreach ($lots as $mail) {
        $id      = (int)$mail['id'];
        $dest    = $mail['destinataire'];
        $type    = $mail['type'];
        $sujet   = $mail['sujet'];
        $corps   = $mail['corps_html'];
        $essais  = (int)$mail['nb_tentatives'];

        // Re-vérification du consentement avant envoi : opposition intervenue
        // entre l'insertion et l'envoi → le message est refusé sans être envoyé.
        if (!email_verifier_consentement($pdo, $dest, $type)) {
            $pdo->prepare("UPDATE emails_queue SET statut='ECHEC', erreur='Consentement retiré', date_envoi=NOW() WHERE id=?")->execute([$id]);
            worker_log("refus-consentement $id → $dest ($type, consentement retiré).");
            suivre_activite('EMAIL_REFUSE', "E-mail $type bloqué au worker (consentement) pour $dest");
            continue;
        }

        $from = str_replace(["\r", "\n"], '', param('smtp_from', 'noreply@estock.local'));
        $shop = str_replace(["\r", "\n"], '', param_shop_name());
        $dest = str_replace(["\r", "\n"], '', $dest);
        $sujet = str_replace(["\r", "\n"], '', $sujet);
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: $shop <$from>\r\n";
        $headers .= "Reply-To: $from\r\n";
        $headers .= "X-Mailer: eStock POS Mailer\r\n";
        $body = "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;line-height:1.5;color:#333;'>"
              . "<h2 style='color:#0d6efd;'>" . h($shop) . "</h2>"
              . $corps
              . "<hr style='border:none;border-top:1px solid #eee;margin-top:20px;'>"
              . "<small style='color:#777;'>Notification automatique générée par eStock.</small>"
              . "</body></html>";

        $envoye = false;
        try {
            $envoye = @mail($dest, "[$shop] " . $sujet, $body, $headers);
        } catch (\Throwable $e) {
            error_log("Erreur envoi email worker ($id): " . $e->getMessage());
        }

        if ($envoye) {
            $pdo->prepare("UPDATE emails_queue SET statut='ENVOYE', date_envoi=NOW(), nb_tentatives=?, erreur=NULL WHERE id=?")
                ->execute([$essais + 1, $id]);
            worker_log("envoyé $id → $dest (« $sujet »)");
        } else {
            $essais++;
            if ($essais >= $max_tentatives) {
                $pdo->prepare("UPDATE emails_queue SET statut='ECHEC', nb_tentatives=?, erreur='Échec définitif après plusieurs tentatives', date_envoi=NOW() WHERE id=?")
                    ->execute([$essais, $id]);
                worker_log("échec-définitif $id → $dest (tentative $essais/$max_tentatives)");
                error_log("[eStock Email Simulated] Pour: $dest | Sujet: $sujet | Message: " . strip_tags($corps));
            } else {
                $delai = BACKOFF_MINUTES[min($essais - 1, count(BACKOFF_MINUTES) - 1)];
                $pdo->prepare(
                    "UPDATE emails_queue
                     SET nb_tentatives=?, prochaine_tentative = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                         erreur='Échec temporaire de l\'envoi'
                     WHERE id=?"
                )->execute([$essais, $delai, $id]);
                worker_log("échec-temporaire $id → $dest (tentative $essais/$max_tentatives, nouveau délai +{$delai}min)");
            }
        }
    }

    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Erreur worker e-mails: " . $e->getMessage());
    worker_log("ERREUR GLOBALE : traitement interrompu (voir error_log).");
    exit(1);
}

$s = emails_stats($pdo);
worker_log("Terminé. Restants : {$s['EN_ATTENTE']} en attente, {$s['prochaine_batch']} éligibles maintenant.");