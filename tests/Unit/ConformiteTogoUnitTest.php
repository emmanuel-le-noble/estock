<?php
/**
 * Tests unitaires — conformité fiscale togolaise (TPU / TVA).
 *
 * NB : param_regime_fiscal() lit les paramètres boutique (base de dev). Les
 * assertions sont structurelles et ne modifient pas la configuration.
 */
final class ConformiteTogoUnitTest extends PHPUnit\Framework\TestCase
{
    public function testRegimeFiscalEstBorne(): void
    {
        self::assertContains(param_regime_fiscal(), ['TPU', 'TVA']);
    }

    public function testRegimeTpuEtMentionCohérentes(): void
    {
        if (param_regime_tpu()) {
            self::assertSame(0.0, param_tva_taux(), 'En TPU, le taux TVA appliqué doit être 0.');
            self::assertStringContainsString('TVA non applicable', param_mention_tva());
        } else {
            self::assertSame('TVA', param_regime_fiscal());
            $mention = param_mention_tva();
            self::assertStringStartsWith('TVA', $mention);
            self::assertStringContainsString('%', $mention);
        }
    }

    public function testMentionTvaAvecTauxExplicite(): void
    {
        $mention = param_mention_tva(18.0);
        if (param_regime_tpu()) {
            self::assertStringContainsString('TVA non applicable', $mention);
        } else {
            self::assertStringContainsString('18,00', $mention);
        }
    }

    public function testPosConfigTransmetLeTauxEffectif(): void
    {
        $config = param_pos_config();
        self::assertSame(param_regime_fiscal(), $config['regime_fiscal']);
        self::assertSame(param_tva_taux(), (float)$config['taux_tva']);
        if (param_regime_tpu()) {
            self::assertSame(0.0, (float)$config['taux_tva']);
        }
    }
}