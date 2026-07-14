<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Tests\Unit\Service;

use Bmorg\AiProofread\Service\ActivePreset;
use Bmorg\AiProofread\Service\ContentExtractor;
use Bmorg\AiProofread\Service\ExtensionSettings;
use Bmorg\AiProofread\Service\Llm\MockClient;
use Bmorg\AiProofread\Service\LogRepository;
use Bmorg\AiProofread\Service\ProofreadingService;
use Bmorg\AiProofread\Service\PromptSettings;
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
     * @param array<string, mixed> $prompt prompt/content settings under test; keys
     *   absent here fall back to the shipped defaults in {@see PromptSettings}
     */
    private function createService(array $prompt): ProofreadingService
    {
        // The prompt/content keys are authoritative in PromptSettings (registry), not
        // ext config — nothing the prompt builder reads touches ext config, so it throws.
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(
            new \RuntimeException('not set', 1751500000)
        );

        // Registry: default preset + no custom values (null), and the prompt settings
        // under test (PromptSettings fills any absent key from the shipped defaults).
        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturnCallback(
            static fn (string $namespace, string $key): mixed => $key === 'promptSettings' ? $prompt : null
        );

        $pool = $this->createMock(ConnectionPool::class);

        return new ProofreadingService(
            new ContentExtractor($pool),
            new ReviewRepository($pool),
            new ReportRepository($pool),
            new MockClient(),
            new LogRepository($pool),
            new ExtensionSettings($extensionConfiguration, new ActivePreset($registry), new PromptSettings($registry)),
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
     * With nothing saved, PromptSettings supplies the shipped defaults: gender-inclusive
     * language on, shipped gender style present. (Style is not a category — it never
     * appears in the checklist; it lives in the free-text `other` bucket.)
     */
    public function testDefaultsApplyWhenNothingIsConfigured(): void
    {
        $prompt = $this->createService([])->buildSystemPrompt();

        self::assertStringNotContainsString('(style)', $prompt);
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
        $schema = $this->createService(['enableGenderInclusiveLanguage' => '0'])->buildSchema();

        $findingsEnum = $schema['properties']['findings']['items']['properties']['category']['enum'];
        $pageFindingsEnum = $schema['properties']['pageFindings']['items']['properties']['category']['enum'];

        self::assertSame($findingsEnum, $pageFindingsEnum);
        self::assertContains('spelling', $findingsEnum);
        self::assertContains('grammar', $findingsEnum);
        self::assertNotContains('gender-inclusive-language', $findingsEnum);
        // Style is not a category at all — never in the enum, toggle or not.
        self::assertNotContains('style', $findingsEnum);
    }
}
