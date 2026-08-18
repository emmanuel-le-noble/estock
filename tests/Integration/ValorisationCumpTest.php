<?php
/**
 * Tests d'intégration — valorisation CUMP (P3-13).
 *
 * Vérifie que le câblage valorisation_stock.sql fonctionne de bout en bout :
 *   * toute entrée via process_stock_movement() actualise articles.cump ;
 *   * la formule est bien une moyenne pondérée (stock d'avant-entrée) ;
 *   * une vente consomme les couches FIFO sans modifier le CUMP ;
 *   * le CAMV des statistiques valorise au CUMP (fallback prix_achat si 0).
 *
 * Base utilisée : estock_db_test (jamais la production).
 */
final class ValorisationCumpTest extends PHPUnit\Framework\TestCase
{
    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        test_db_schema();
        self::$pdo = test_db();
    }

    protected function setUp(): void
    {
        self::$pdo->exec("DELETE FROM article_couts");
    }

    private function creerArticle(float $prixAchat): int {
        $id = db_article_insert(self::$pdo, [
            'nom'           => 'Article CUMP Test ' . random_int(1000, 9999),
            'code_barre'    => (string)random_int(1000000000000, 9999999999999),
            'prix_achat'    => $prixAchat,
            'prix_vente'    => $prixAchat * 2,
            'quantite_stock'=> 0,
            'seuil_alerte'  => 5,
        ]);
        self::assertGreaterThan(0, $id);
        return $id;
    }

    public function testCumpMoyennePondereeApresDeuxEntrees(): void
    {
        $id = $this->creerArticle(100.0);

        process_stock_movement(self::$pdo, $id, 'Entree', 10, 'Réception test 1', 1, 1, null, null, 100.0);
        $cump1 = (float)self::$pdo->query("SELECT cump FROM articles WHERE id = $id")->fetchColumn();
        self::assertSame(100.0, $cump1, 'CUMP après 1re entrée au coût 100.');

        process_stock_movement(self::$pdo, $id, 'Entree', 10, 'Réception test 2', 1, 1, null, null, 150.0);
        $cump2 = (float)self::$pdo->query("SELECT cump FROM articles WHERE id = $id")->fetchColumn();
        self::assertSame(125.0, $cump2, 'CUMP attendu : (10×100 + 10×150) / 20 = 125.');
    }

    public function testEntreeSansCoutExpliciteUtilisePrixAchat(): void
    {
        $id = $this->creerArticle(80.0);

        process_stock_movement(self::$pdo, $id, 'Entree', 5, 'Mouvement manuel', 1, 1);
        $cump = (float)self::$pdo->query("SELECT cump FROM articles WHERE id = $id")->fetchColumn();
        self::assertSame(80.0, $cump, 'Fallback : prix_achat de la fiche article.');
    }

    public function testVenteNeModifiePasLeCump(): void
    {
        $id = $this->creerArticle(60.0);

        process_stock_movement(self::$pdo, $id, 'Entree', 20, 'Achat', 1, 1, null, null, 60.0);
        process_stock_movement(self::$pdo, $id, 'Vente', 5, 'Vente test', 1, 1);

        $cump = (float)self::$pdo->query("SELECT cump FROM articles WHERE id = $id")->fetchColumn();
        self::assertSame(60.0, $cump, 'Une vente ne doit pas modifier le coût moyen pondéré.');

        // La couche FIFO doit avoir été consommée (20 - 5 = 15 restants)
        $qte = (int)self::$pdo->query("SELECT quantite FROM article_couts WHERE article_id = $id")->fetchColumn();
        self::assertSame(15, $qte, 'La couche de coût doit être consommée à la vente.');
    }
}