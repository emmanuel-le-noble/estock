<?php
/**
 * Tests unitaires — helpers publics purs (aucun accès réseau/BDD en écriture).
 * NB : money()/date_fr() lisent les paramètres boutique (base de dev) — tests
 * structurels pour rester stables quelle que soit la configuration.
 */
final class HelpersUnitTest extends PHPUnit\Framework\TestCase
{
    public function testH_EchappeLeHTML(): void
    {
        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', h('<script>alert(1)</script>'));
        self::assertSame('a&amp;b', h('a&b'));
        self::assertSame('&quot;guillemets&quot;', h('"guillemets"'));
    }

    public function testH_LaisseLesAccentsIntacts(): void
    {
        self::assertSame('Été n°1 — test', h('Été n°1 — test'));
    }

    public function testMoney_FormatageStructurel(): void
    {
        $montant = money(1250);
        self::assertStringEndsWith(' ' . param('devise_symbole', 'FCFA'), $montant);
        self::assertStringStartsWith('1 250', $montant);
        $parts = explode(' ', $montant);
        self::assertSame('1', $parts[0]);
        self::assertSame('250', explode(',', $parts[1])[0]);
    }

    public function testMoney_NonNumericRetourneZero(): void
    {
        self::assertStringStartsWith('0', money('abc'));
    }

    public function testDateFr_FormatFrancaisSansHeure(): void
    {
        self::assertSame('13/08/2026', date_fr('2026-08-13', false));
    }

    public function testDateFr_Vide(): void
    {
        self::assertSame('', date_fr(''));
        self::assertSame('', date_fr(null));
    }

    public function testHmacUrl_RoundTrip(): void
    {
        if (!defined('SECRET_URL_KEY')) {
            define('SECRET_URL_KEY', 'cle_de_test_min_32_caracteres_00000000001');
        }
        $url = generate_signed_url('facture_view.php', 42, ['action' => 'pdf']);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
        $_GET = ['id' => '42', 'action' => 'pdf', 'ts' => $params['ts'], 'token' => $params['token']];
        self::assertSame('42', $params['id']);
        self::assertSame('pdf', $params['action']);
        self::assertTrue(verify_url_signature(42, (string)$params['token'], ['action' => 'pdf']));
    }

    public function testHmacUrl_RejetteTokenAltere(): void
    {
        if (!defined('SECRET_URL_KEY')) {
            define('SECRET_URL_KEY', 'cle_de_test_min_32_caracteres_00000000001');
        }
        $url = generate_signed_url('facture_view.php', 42);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
        $_GET = ['id' => '42', 'ts' => $params['ts'], 'token' => $params['token']];
        self::assertFalse(verify_url_signature(43, (string)$params['token'], []));
        self::assertFalse(verify_url_signature(42, 'tokenbidon', []));
    }
}