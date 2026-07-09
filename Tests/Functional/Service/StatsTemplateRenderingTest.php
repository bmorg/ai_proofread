<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Tests\Functional\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Renders Stats.html the way the controller does (StandaloneView, absolute
 * EXT: path), with representative data for both the cost section and its
 * zero state — the templates are otherwise uncovered by the harness, and a
 * Fluid error only surfaces at render time.
 */
final class StatsTemplateRenderingTest extends FunctionalTestCase
{
    protected $testExtensionsToLoad = ['typo3conf/ext/ai_proofread'];

    private function render(array $variables): string
    {
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setTemplatePathAndFilename(
            GeneralUtility::getFileAbsFileName('EXT:ai_proofread/Resources/Private/Templates/Review/Stats.html')
        );
        $view->assignMultiple($variables);
        return $view->render();
    }

    private function baseStats(array $cost): array
    {
        return [
            'totalPages' => 3, 'checkedPages' => 2, 'checkedPercent' => 67,
            'proofedPages' => 1, 'reportCreatedPages' => 1, 'uncheckedPages' => 1,
            'fixedTotal' => 3, 'fixedAccepted' => 2, 'fixedManual' => 1,
            'dismissed' => 1, 'openTotal' => 2,
            'openByCategory' => [['label' => 'Rechtschreibung', 'count' => 1]],
            'cost' => $cost,
        ];
    }

    public function testRendersWithCostData(): void
    {
        $html = $this->render($this->baseStats([
            'calls' => 5, 'runs' => 3,
            'totalUsd' => 0.51, 'total' => '$0.51',
            'totalTokens' => 9400, 'totalTokensFormatted' => '9.400',
            'currentMonthUsd' => 0.06, 'currentMonth' => '$0.06', 'currentMonthLabel' => 'Juli 2026',
            'perRunUsd' => 0.1667, 'perRun' => '$0.17',
            'months' => [
                ['label' => 'Juli 2026', 'calls' => 3, 'runs' => 1, 'costUsd' => 0.06, 'cost' => '$0.06', 'tokens' => 4200, 'tokensFormatted' => '4.200'],
                ['label' => 'Juni 2026', 'calls' => 1, 'runs' => 1, 'costUsd' => 0.05, 'cost' => '$0.05', 'tokens' => 5000, 'tokensFormatted' => '5.000'],
            ],
            'byModel' => [
                ['model' => 'anthropic/claude-opus-4.8', 'calls' => 3, 'runs' => 1, 'costUsd' => 0.06, 'cost' => '$0.06', 'perRunUsd' => 0.05, 'perRun' => '$0.05'],
            ],
        ]));

        self::assertStringContainsString('<label for="aiproofread-tab-results">Ergebnisse</label>', $html);
        self::assertStringContainsString('<label for="aiproofread-tab-costs">Kosten</label>', $html);
        self::assertStringContainsString('Gesamtkosten', $html);
        self::assertStringContainsString('$0.51', $html);
        self::assertStringContainsString('Juli 2026', $html);
        self::assertStringContainsString('anthropic/claude-opus-4.8', $html);
        self::assertStringContainsString('Ø pro Report', $html);
        self::assertStringNotContainsString('Noch keine API-Aufrufe', $html);
    }

    public function testRendersZeroStateWithoutCostSection(): void
    {
        $html = $this->render($this->baseStats([
            'calls' => 0, 'runs' => 0,
            'totalUsd' => 0.0, 'total' => '$0.00',
            'totalTokens' => 0, 'totalTokensFormatted' => '0',
            'currentMonthUsd' => 0.0, 'currentMonth' => '$0.00', 'currentMonthLabel' => 'Juli 2026',
            'perRunUsd' => null, 'perRun' => '–',
            'months' => [], 'byModel' => [],
        ]));

        self::assertStringContainsString('Noch keine API-Aufrufe protokolliert.', $html);
        self::assertStringNotContainsString('Gesamtkosten', $html);
    }
}
