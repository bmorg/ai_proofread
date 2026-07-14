<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Tests\Unit\Service;

use Bmorg\AiProofread\Service\PromptSettings;
use TYPO3\CMS\Core\Registry;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The registry-backed prompt/content settings store. Guards that it is
 * authoritative for its four keys — {@see PromptSettings::values()} always returns
 * a complete, well-typed set (saved values, or the shipped defaults) — and the
 * normalize() type coercion / default-filling.
 */
final class PromptSettingsTest extends UnitTestCase
{
    public function testValuesAreShippedDefaultsUntilSaved(): void
    {
        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturn(null);

        self::assertSame([
            'siteDescription' => '',
            'extraPromptInstructions' => '',
            'enableGenderInclusiveLanguage' => true,
            'genderInclusiveStyle' => 'Doppelpunkt (z.B. Nutzer:innen)',
        ], (new PromptSettings($registry))->values());
    }

    public function testValuesReturnSavedValuesNormalized(): void
    {
        $registry = $this->createMock(Registry::class);
        // Stored data with legacy shapes: untrimmed strings, a truthy int for the flag.
        $registry->method('get')->willReturn([
            'siteDescription' => '  eine Bäckerei  ',
            'extraPromptInstructions' => "Korrigiere NIE Anführungszeichen.\n",
            'enableGenderInclusiveLanguage' => 1,
            'genderInclusiveStyle' => ' Doppelpunkt ',
        ]);

        self::assertSame([
            'siteDescription' => 'eine Bäckerei',
            'extraPromptInstructions' => 'Korrigiere NIE Anführungszeichen.',
            'enableGenderInclusiveLanguage' => true,
            'genderInclusiveStyle' => 'Doppelpunkt',
        ], (new PromptSettings($registry))->values());
    }

    public function testAbsentKeyInStoredDataFallsBackToDefault(): void
    {
        // A partial stored array fills the missing keys from the shipped defaults —
        // notably the gender flag defaults to on, never silently off.
        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturn(['siteDescription' => 'x']);

        $values = (new PromptSettings($registry))->values();

        self::assertSame('x', $values['siteDescription']);
        self::assertSame('', $values['extraPromptInstructions']);
        self::assertTrue($values['enableGenderInclusiveLanguage']);
        self::assertSame('Doppelpunkt (z.B. Nutzer:innen)', $values['genderInclusiveStyle']);
    }

    public function testSaveNormalizesBeforeWriting(): void
    {
        $captured = null;
        $registry = $this->createMock(Registry::class);
        $registry->expects(self::once())->method('set')->with(
            'ai_proofread',
            'promptSettings',
            self::callback(static function (mixed $value) use (&$captured): bool {
                $captured = $value;
                return true;
            })
        );

        // Mimics the controller: all four keys present, the checkbox an explicit false.
        (new PromptSettings($registry))->save([
            'siteDescription' => '  Fahrrad-Shop  ',
            'extraPromptInstructions' => '',
            'enableGenderInclusiveLanguage' => false,
            'genderInclusiveStyle' => 'Sternchen',
        ]);

        self::assertSame([
            'siteDescription' => 'Fahrrad-Shop',
            'extraPromptInstructions' => '',
            'enableGenderInclusiveLanguage' => false,
            'genderInclusiveStyle' => 'Sternchen',
        ], $captured);
    }
}
