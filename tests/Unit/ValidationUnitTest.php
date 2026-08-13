<?php
/**
 * Tests unitaires — extract_post_data (validation & coercition POST).
 * Conventions réelles : min/max = bornage silencieux, strlen excessif =
 * flash + redirect (exit) si une redirection est configurée, sinon champ
 * conservé ; les champs inconnus ne sont jamais intégrés.
 */
final class ValidationUnitTest extends PHPUnit\Framework\TestCase
{
    public function testExtractPostData_CoercitionEtNettoyage(): void
    {
        $_POST = ['nb' => '5', 'genre' => '  V  ', 'montant_fixe' => '1000.5'];
        $data = extract_post_data([
            'nb'     => ['type' => 'int', 'required' => true, 'min' => 0, 'max' => 100],
            'genre'  => ['type' => 'string', 'max' => 1],
            'montant_fixe' => ['type' => 'float'],
        ], 't.php');
        self::assertSame(5, $data['nb']);
        self::assertSame('V', $data['genre']);
        self::assertSame(1000.5, $data['montant_fixe']);
    }

    public function testExtractPostData_BornageSilencieux(): void
    {
        $_POST = ['nb' => '150'];
        $data = extract_post_data(['nb' => ['type' => 'int', 'required' => true, 'max' => 100]], '');
        self::assertSame(100, $data['nb']);

        $_POST = ['nb' => '-3'];
        $data = extract_post_data(['nb' => ['type' => 'int', 'min' => 0, 'max' => 100]], '');
        self::assertSame(0, $data['nb']);
    }

    public function testExtractPostData_ChampRequiseNonNumerique(): void
    {
        // La validation « required » silencieuse (sans redirect) laisse le champ vide
        $_POST = ['nb' => 'abc'];
        $data = extract_post_data(['nb' => ['type' => 'int', 'required' => true]], '');
        self::assertSame(0, $data['nb']);
    }

    public function testExtractPostData_ChampStringAbsent(): void
    {
        $_POST = [];
        $data = extract_post_data(['nom' => ['type' => 'string']], '');
        self::assertSame('', $data['nom']);
    }

    public function testExtractPostData_ChampInconnuIgnore(): void
    {
        $_POST = ['connu' => 'ok', 'tamper' => 'x'];
        $data = extract_post_data(['connu' => ['type' => 'string']], 't.php');
        self::assertArrayNotHasKey('tamper', $data);
        self::assertSame('ok', $data['connu']);
    }

    public function testExtractPostData_Bool(): void
    {
        $_POST = ['actif' => '1'];
        $data = extract_post_data(['actif' => ['type' => 'bool']], '');
        self::assertTrue($data['actif']);

        $_POST = ['actif' => ''];
        $data = extract_post_data(['actif' => ['type' => 'bool']], '');
        self::assertFalse($data['actif']);
    }
}