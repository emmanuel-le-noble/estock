<?php
/**
 * Test de concurrence : la numérotation des factures doit rester
 * continue et sans doublon quand plusieurs caisses vendent simultanément.
 *
 * Stratégie : N processus PHP enfants appellent generate_invoice_number()
 * en parallèle (base de test estock_db_test). On vérifie :
 *   - l'absence de doublons ;
 *   - la contiguïté des rangs (aucun trou) ;
 *   - le respect du préfixe journalier FAC-YYYYMMDD-NNNN.
 */
final class ConcurrenceNumerotationTest extends PHPUnit\Framework\TestCase
{
    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        test_db_schema();
        self::$pdo = test_db();
    }

    public function testNumerosUniquesSansDoublonNiTrouSousConcurrence(): void
    {
        $nbProcessus = 8;
        $nbParProcessus = 5;
        $root = dirname(TESTS_ROOT . '/fixtures');
        $fixture = realpath(TESTS_ROOT . '/fixtures/numero_concurrent.php');
        self::assertNotFalse($fixture, 'Fixture de concurrence introuvable.');

        // Réinitialisation des compteurs du jour (base de test uniquement).
        // NB : les DELETE physiques sur factures/lignes sont interdits par les
        // triggers d'immuabilité — ici on ne fait que repartir de zéro
        // sur les séquences, ce qui valide précisément l'atomicité.
        $aujourdhui = date('Ymd');
        self::$pdo->exec("DELETE FROM sequences WHERE cle LIKE 'numero_facture:%'");

        // Lancement simultané des processus enfants
        $procs = [];
        $pipes = [];
        foreach (range(1, $nbProcessus) as $i) {
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . $nbParProcessus;
            $proc = proc_open(
                $cmd,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i]
            );
            self::assertIsResource($proc, "Impossible de lancer le processus enfant #$i");
            $procs[$i] = $proc;
        }

        // Collecte des sorties (en parallèle : lecture après lancement de tous)
        $sorties = [];
        foreach ($procs as $i => $proc) {
            $sorties[$i] = stream_get_contents($pipes[$i][1]);
            $err = stream_get_contents($pipes[$i][2]);
            proc_close($proc);
            self::assertSame('', $err, "Processus #$i a écrit sur stderr : $err");
        }

        $numeros = [];
        foreach ($sorties as $i => $s) {
            foreach (preg_split('/\R/', trim($s)) as $ligne) {
                $ligne = trim($ligne);
                if ($ligne !== '') {
                    $numeros[] = $ligne;
                }
            }
        }

        $total = $nbProcessus * $nbParProcessus;
        self::assertCount($total, $numeros, 'Nombre de numéros produits incorrect.');

        // 1) Aucun doublon
        $doublons = array_diff_assoc($numeros, array_unique($numeros));
        self::assertSame([], $doublons, 'Doublons détectés dans la numérotation : ' . json_encode($doublons));

        // 2) Préfixe journalier + rangs contigus sans trou
        $rangs = [];
        foreach ($numeros as $n) {
            self::assertMatchesRegularExpression(
                "/^FAC-{$aujourdhui}-(\d{4})$/",
                $n,
                "Numéro au format inattendu : $n"
            );
            preg_match('/-(\d{4})$/', $n, $m);
            $rangs[] = (int)$m[1];
        }
        sort($rangs);
        $attendus = range(1, $total);
        self::assertSame($attendus, $rangs, 'Rupture de séquence détectée : les rangs doivent être 1..' . $total . ' sans trou.');

        // 3) Cohérence en base : la séquence doit valoir exactement $total
        $st = self::$pdo->prepare("SELECT valeur FROM sequences WHERE cle = ?");
        $st->execute(['numero_facture:' . date('Ymd')]);
        self::assertSame($total, (int)$st->fetchColumn(), 'Compteur séquence désynchronisé de la réalité.');
    }
}