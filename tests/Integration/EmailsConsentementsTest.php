<?php
/**
 * Tests d'intégration — file d'attente e-mails : consentements des clients.
 *
 * Vérifie :
 *   * insertion en file (non bloquant) et rejet des adresses invalides ;
 *   * opt-in obligatoire pour la prospection (consentement MARKETING) ;
 *   * opposition globale bloque tous les types ;
 *   * désinscription par token (page publique, anti-énumération) ;
 *   * token aléatoire non devinable (format 64 hex) ;
 *   * mention de désinscription insérée dans les e-mails marketing ;
 *   * droit à l'effacement : purge file + consentement ;
 *   * purge de conservation — base de test uniquement.
 *
 * Base utilisée : estock_db_test (jamais la production).
 */
final class EmailsConsentementsTest extends PHPUnit\Framework\TestCase
{
    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        test_db_schema();
        self::$pdo = test_db();
    }

    protected function setUp(): void
    {
        self::$pdo->exec("DELETE FROM emails_queue");
        self::$pdo->exec("DELETE FROM emails_consentements");
    }

    private function nbEnAttente(): int
    {
        $stmt = self::$pdo->query("SELECT COUNT(*) FROM emails_queue WHERE statut = 'EN_ATTENTE'");
        return (int)$stmt->fetchColumn();
    }

    public function testInsertionFileNonBloquante(): void
    {
        $id = email_queue_ajouter(self::$pdo, 'test@exemple.fr', 'Sujet', '<p>Corps</p>', EMAILS_TYPE_ALERTE);
        self::assertNotFalse($id, 'L\'insertion en file doit réussir.');
        self::assertGreaterThan(0, $id);
        self::assertSame(1, $this->nbEnAttente());

        $row = self::$pdo->prepare("SELECT * FROM emails_queue WHERE id = ?");
        $row->execute([$id]);
        $mail = $row->fetch();
        self::assertSame('EN_ATTENTE', $mail['statut']);
        self::assertSame(EMAILS_TYPE_ALERTE, $mail['type']);
    }

    public function testAdresseInvalideRejetee(): void
    {
        self::assertFalse(email_queue_ajouter(self::$pdo, 'pas-un-email', 'S', '<p>C</p>'));
        self::assertFalse(email_queue_ajouter(self::$pdo, '', 'S', '<p>C</p>'));
        self::assertSame(0, $this->nbEnAttente());
    }

    public function testMarketingSansOptinEstBloque(): void
    {
        self::assertFalse(
            email_queue_ajouter(self::$pdo, 'client@exemple.fr', 'Offre', '<p>Promo</p>', EMAILS_TYPE_MARKETING),
            'La prospection sans opt-in préalable doit être refusée.'
        );
        self::assertSame(0, $this->nbEnAttente());
    }

    public function testMarketingAvecOptinEstAccepte(): void
    {
        self::assertTrue(
            email_consentement_optin_enregistrer(self::$pdo, 'client@exemple.fr', 'formulaire-inscription'),
            'L\'opt-in doit être enregistré.'
        );
        $id = email_queue_ajouter(self::$pdo, 'client@exemple.fr', 'Offre', '<p>Promo</p>', EMAILS_TYPE_MARKETING);
        self::assertNotFalse($id);
        self::assertSame(1, $this->nbEnAttente());
    }

    public function testMentionDesinscriptionAjouteeAuMarketing(): void
    {
        $email = 'client2@exemple.fr';
        email_consentement_optin_enregistrer(self::$pdo, $email, 'formulaire');
        $id = email_queue_ajouter(self::$pdo, $email, 'Offre', '<p>Corps</p>', EMAILS_TYPE_MARKETING);
        $row = self::$pdo->prepare("SELECT corps_html FROM emails_queue WHERE id = ?");
        $row->execute([$id]);
        $corps = (string)$row->fetchColumn();

        self::assertStringContainsString('desinscription.php?token=', $corps, 'La mention de désinscription doit être présente.');
        self::assertStringNotContainsString('desinscription.php?token=', '<p>Corps</p>', 'Le corps d\'origine doit rester intact.');
    }

    public function testTokenFormatAleatoireNonDevinable(): void
    {
        $t1 = email_obtenir_token(self::$pdo, 'a@exemple.fr');
        $t2 = email_obtenir_token(self::$pdo, 'b@exemple.fr');
        self::assertSame(64, strlen($t1));
        self::assertTrue((bool)ctype_xdigit($t1), 'Token hexadécimal attendu.');
        self::assertNotSame($t1, $t2, 'Tokens distincts par destinataire.');
    }

    public function testOppositionBloqueTousLesTypes(): void
    {
        $email = 'desinscrit@exemple.fr';
        $token = email_obtenir_token(self::$pdo, $email);
        self::assertTrue(email_desinscrire_par_token(self::$pdo, $token));

        self::assertFalse(email_verifier_consentement(self::$pdo, $email, EMAILS_TYPE_ALERTE));
        self::assertFalse(email_verifier_consentement(self::$pdo, $email, EMAILS_TYPE_MARKETING));
        self::assertFalse(
            email_queue_ajouter(self::$pdo, $email, 'Alerte', '<p>X</p>', EMAILS_TYPE_ALERTE),
            'Un destinataire opposé ne doit recevoir aucun e-mail.'
        );
        self::assertSame(0, $this->nbEnAttente());
    }

    public function testDesinscriptionTokenInconnuFausse(): void
    {
        self::assertFalse(email_desinscrire_par_token(self::$pdo, str_repeat('0', 64)));
        self::assertFalse(email_desinscrire_par_token(self::$pdo, 'token-court'));
        self::assertFalse(email_desinscrire_par_token(self::$pdo, ''));
    }

    public function testOppositionSeReinscritApresNouvelOptin(): void
    {
        $email = 'retour@exemple.fr';
        $token = email_obtenir_token(self::$pdo, $email);
        email_desinscrire_par_token(self::$pdo, $token);

        $ok = email_consentement_optin_enregistrer(self::$pdo, $email, 'formulaire');
        self::assertFalse($ok, 'L\'opposition prime sur un opt-in ultérieur.');
        self::assertFalse(email_verifier_consentement(self::$pdo, $email, EMAILS_TYPE_MARKETING));
    }

    public function testDroitEffacementSupprimeFileEtConsentement(): void
    {
        $email = 'efface@exemple.fr';
        email_consentement_optin_enregistrer(self::$pdo, $email, 'formulaire');
        email_queue_ajouter(self::$pdo, $email, 'Offre', '<p>X</p>', EMAILS_TYPE_MARKETING);
        email_queue_ajouter(self::$pdo, 'autre@exemple.fr', 'Alerte', '<p>Y</p>', EMAILS_TYPE_ALERTE);

        self::assertTrue(email_supprimer_donnees(self::$pdo, $email));

        $stmt = self::$pdo->prepare("SELECT COUNT(*) FROM emails_queue WHERE destinataire = ?");
        $stmt->execute([$email]);
        self::assertSame(0, (int)$stmt->fetchColumn(), 'Tous les e-mails du destinataire doivent être purgés.');
        $stmt = self::$pdo->prepare("SELECT COUNT(*) FROM emails_consentements WHERE email = ?");
        $stmt->execute([$email]);
        self::assertSame(0, (int)$stmt->fetchColumn(), 'Le consentement doit être supprimé.');

        // L'autre destinataire reste intact
        self::assertSame(1, $this->nbEnAttente());
    }

    public function testPurgeConservationArt5(): void
    {
        // E-mail traité il y a 40 jours → doit être purgé
        self::$pdo->exec(
            "INSERT INTO emails_queue (destinataire, type, sujet, corps_html, statut, date_envoi, date_creation)
             VALUES ('ancien@exemple.fr', 'alerte', 'S', '<p>X</p>', 'ENVOYE',
                     DATE_SUB(NOW(), INTERVAL 40 DAY), DATE_SUB(NOW(), INTERVAL 40 DAY))"
        );
        // E-mail traité il y a 5 jours → doit être conservé
        self::$pdo->exec(
            "INSERT INTO emails_queue (destinataire, type, sujet, corps_html, statut, date_envoi, date_creation)
             VALUES ('recent@exemple.fr', 'alerte', 'S', '<p>Y</p>', 'ENVOYE',
                     DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY))"
        );

        $supprimes = email_queue_purger(self::$pdo, 30);
        self::assertSame(1, $supprimes, 'Seul l\'e-mail de plus de 30 jours doit disparaître.');

        $stmt = self::$pdo->prepare("SELECT COUNT(*) FROM emails_queue WHERE destinataire = ?");
        $stmt->execute(['ancien@exemple.fr']);
        self::assertSame(0, (int)$stmt->fetchColumn());
        $stmt->execute(['recent@exemple.fr']);
        self::assertSame(1, (int)$stmt->fetchColumn());
    }

    public function testEnAttenteNonPurgee(): void
    {
        self::$pdo->exec(
            "INSERT INTO emails_queue (destinataire, type, sujet, corps_html, statut, date_creation)
             VALUES ('attt@exemple.fr', 'alerte', 'S', '<p>X</p>', 'EN_ATTENTE', DATE_SUB(NOW(), INTERVAL 100 DAY))"
        );
        self::assertSame(0, email_queue_purger(self::$pdo, 30), 'Les e-mails non traités ne sont jamais purgés.');
    }
}