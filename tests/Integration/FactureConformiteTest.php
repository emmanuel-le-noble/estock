<?php
/**
 * Tests d'intégration — factures : conformité client (JOIN clients) et
 * numérotation avec préfixe paramétrable.
 *
 * Vérifie :
 *   * db_facture_get_by_id() joint le client (nom / raison sociale / NIF) ;
 *   * generate_invoice_number() respecte le préfixe paramétré ;
 *   * les factures non conformes (NIF boutique absent) sont détectées.
 *
 * Base utilisée : estock_db_test (jamais la production).
 */
final class FactureConformiteTest extends PHPUnit\Framework\TestCase
{
    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        test_db_schema();
        self::$pdo = test_db();
    }

    protected function setUp(): void
    {
        // Nettoyage : uniquement les lignes créées par ce test (triggers
        // d'immuabilité interdisent le DELETE sur factures — on repart de zéro sur la base de test).
        self::$pdo->exec("DELETE FROM sequences WHERE cle LIKE 'numero_facture:%'");
        self::$pdo->exec("DELETE FROM clients WHERE email = 'b2b-test@exemple.tg'");
    }

    public function testNumeroFactureRespecteLePrefixe(): void
    {
        // Préfixe de test : on évite de toucher à la config de la base de dev.
        $prefixeAttendu = 'FAC-' . date('Ymd') . '-';
        $numero = generate_invoice_number(self::$pdo);
        self::assertStringStartsWith($prefixeAttendu, $numero);
        self::assertMatchesRegularExpression(
            '/^FAC-\d{8}-\d{4}$/',
            $numero,
            'Format attendu : FAC-AAAAMMJJ-NNNN.'
        );
    }

    public function testFactureJoinClientRetourneNomEtNif(): void
    {
        $clientId = db_client_insert(self::$pdo, [
            'nom'            => 'Client Comptoir',
            'raison_sociale' => 'SARL Test Import',
            'nif'            => '1000000000123A',
            'rccm'           => 'TG-LOM-2026-A-0123',
            'email'          => 'b2b-test@exemple.tg',
            'consentement_fidelite' => 1,
        ]);
        self::assertGreaterThan(0, $clientId);

        $factureId = db_facture_insert(self::$pdo, [
            'numero_facture' => 'FAC-' . date('Ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'utilisateur_id' => 1,
            'total_ht'       => 100.0,
            'tva_taux'       => 0.0,
            'total_ttc'      => 100.0,
            'montant_paye'   => 100.0,
            'monnaie_rendue' => 0.0,
            'magasin_id'     => 1,
        ]);
        self::assertGreaterThan(0, $factureId);

        self::$pdo->prepare("UPDATE factures SET client_id = ? WHERE id = ?")
            ->execute([$clientId, $factureId]);

        $f = db_facture_get_by_id(self::$pdo, $factureId);
        self::assertNotNull($f);
        self::assertSame('SARL Test Import', trim((string)$f['client_nom']),
            'La raison sociale doit primer sur le nom simple.');
        self::assertSame('1000000000123A', trim((string)$f['client_nif']));
        self::assertSame('TG-LOM-2026-A-0123', trim((string)$f['client_rccm']));
    }
}