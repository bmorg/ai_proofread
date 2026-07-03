<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Tests\Unit\Service;

use Bmorg\AiProofread\Service\ActivePreset;
use Bmorg\AiProofread\Service\ContentExtractor;
use Bmorg\AiProofread\Service\ExtensionSettings;
use Bmorg\AiProofread\Service\Llm\MockClient;
use Bmorg\AiProofread\Service\LogRepository;
use Bmorg\AiProofread\Service\ProofreadingService;
use Bmorg\AiProofread\Service\ReportRepository;
use Bmorg\AiProofread\Service\ReviewRepository;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Registry;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Prompt and schema construction from configuration. Guards the category
 * routing contract and the gender-style conditionals (a cleared style once
 * produced the garbled instruction "Beim Gendern ist die Hausschreibweise: .").
 *
 * Final services are never mocked — the object graph is built for real on top
 * of mocked core dependencies (ExtensionConfiguration, Registry, ConnectionPool).
 */
final class ProofreadingServicePromptTest extends UnitTestCase
{
    /**
     * @param array<string, mixed> $config ext-config values; keys absent here
     *   behave like unset keys (ExtensionConfiguration throws, DEFAULTS apply)
     */
    private function createService(array $config): ProofreadingService
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnCallback(
            static function (string $extension, string $path = '') use ($config) {
                if (\array_key_exists($path, $config)) {
                    return $config[$path];
                }
                throw new \RuntimeException('not set: ' . $path, 1751500000);
            }
        );

        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturn(null); // default preset, no custom values

        $pool = $this->createMock(ConnectionPool::class);

        return new ProofreadingService(
            new ContentExtractor($pool),
            new ReviewRepository($pool),
            new ReportRepository($pool),
            new MockClient(),
            new LogRepository($pool),
            new ExtensionSettings($extensionConfiguration, new ActivePreset($registry)),
        );
    }

    public function testGenderStyleLineIsPresentWhenConfigured(): void
    {
        $prompt = $this->createService([
            'genderInclusiveStyle' => 'Doppelpunkt (z.B. Nutzer:innen)',
        ])->buildSystemPrompt();

        self::assertStringContainsString(
            'Beim Gendern ist die Hausschreibweise: Doppelpunkt (z.B. Nutzer:innen).',
            $prompt
        );
    }

    /**
     * The setting documents: "Cleared = no house style given to the model."
     */
    public function testGenderStyleLineIsOmittedWhenStyleCleared(): void
    {
        $prompt = $this->createService(['genderInclusiveStyle' => ''])->buildSystemPrompt();

        self::assertStringNotContainsString('Hausschreibweise', $prompt);
    }

    public function testGenderContentIsOmittedWhenCategoryDisabled(): void
    {
        $service = $this->createService([
            'enableGenderInclusiveLanguage' => '0',
            'genderInclusiveStyle' => 'Doppelpunkt (z.B. Nutzer:innen)',
        ]);

        $prompt = $service->buildSystemPrompt();

        self::assertStringNotContainsString('Hausschreibweise', $prompt);
        self::assertStringNotContainsString('- Gendern:', $prompt);
        self::assertStringNotContainsString('(gender-inclusive-language)', $prompt);
    }

    /**
     * Unset keys fall back to ExtensionSettings::DEFAULTS: both optional
     * categories on, shipped gender style present.
     */
    public function testDefaultsApplyWhenNothingIsConfigured(): void
    {
        $prompt = $this->createService([])->buildSystemPrompt();

        self::assertStringContainsString('(style)', $prompt);
        self::assertStringContainsString('(gender-inclusive-language)', $prompt);
        self::assertStringContainsString(
            'Beim Gendern ist die Hausschreibweise: Doppelpunkt (z.B. Nutzer:innen).',
            $prompt
        );
    }

    public function testSiteDescriptionShapesTheIntro(): void
    {
        $with = $this->createService(['siteDescription' => 'eine Fanseite für Klemmbaustein-Sets'])
            ->buildSystemPrompt();
        $without = $this->createService([])->buildSystemPrompt();

        self::assertStringContainsString(
            'für die Inhalte folgender Website: eine Fanseite für Klemmbaustein-Sets.',
            $with
        );
        self::assertStringContainsString('für Website-Inhalte.', $without);
    }

    public function testExtraInstructionsAreAppendedAtTheEnd(): void
    {
        $prompt = $this->createService([
            'extraPromptInstructions' => 'Sei bei Stilfragen besonders zurückhaltend.',
        ])->buildSystemPrompt();

        self::assertStringContainsString(
            "Zusätzliche Anweisungen:\nSei bei Stilfragen besonders zurückhaltend.",
            $prompt
        );
    }

    /**
     * The schema's category enum must track the enabled categories — the prompt
     * routes gender-inclusive findings to pageFindings, so a disabled category
     * must disappear from BOTH enums, or the model is told to use a value it
     * cannot emit.
     */
    public function testSchemaEnumTracksEnabledCategories(): void
    {
        $schema = $this->createService(['enableStyle' => '0'])->buildSchema();

        $findingsEnum = $schema['properties']['findings']['items']['properties']['category']['enum'];
        $pageFindingsEnum = $schema['properties']['pageFindings']['items']['properties']['category']['enum'];

        self::assertSame($findingsEnum, $pageFindingsEnum);
        self::assertContains('spelling', $findingsEnum);
        self::assertContains('gender-inclusive-language', $findingsEnum);
        self::assertNotContains('style', $findingsEnum);
    }
}
