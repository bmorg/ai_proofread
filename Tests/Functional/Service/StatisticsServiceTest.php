<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Tests\Functional\Service;

use Bmorg\AiProofread\Service\FindingStateRepository;
use Bmorg\AiProofread\Service\ReportRepository;
use Bmorg\AiProofread\Service\ReviewRepository;
use Bmorg\AiProofread\Service\StatisticsService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The Statistik view's numbers against a real schema. The fixture covers the
 * denominator edges (folder, deleted page, translation must not count) and the
 * core success property: decisions on superseded runs keep being credited —
 * "found, fixed, next report shows 0" still counts the fixes.
 */
final class StatisticsServiceTest extends FunctionalTestCase
{
    protected $testExtensionsToLoad = ['typo3conf/ext/ai_proofread'];

    private ReportRepository $reports;
    private ReviewRepository $reviews;
    private FindingStateRepository $states;
    private StatisticsService $statistics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/StatisticsScenario.csv');

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->reports = new ReportRepository($pool);
        $this->reviews = new ReviewRepository($pool);
        $this->states = new FindingStateRepository($pool);
        $this->statistics = new StatisticsService($pool, $this->reports, $this->reviews, $this->states);
    }

    /**
     * @param list<array<string, string>> $findings
     */
    private function storeRun(int $pageUid, array $findings): int
    {
        return $this->reports->store(
            $pageUid,
            0,
            1,
            'test-model',
            json_encode(['findings' => $findings, 'pageFindings' => [], 'other' => []], JSON_THROW_ON_ERROR),
            'spelling,punctuation,grammar',
            0.01,
            100,
            'hash-' . $pageUid
        );
    }

    /**
     * @return array<string, string>
     */
    private function finding(string $category): array
    {
        return ['category' => $category, 'quote' => 'a', 'suggestion' => 'b', 'explanation' => 'c'];
    }

    public function testCollectComputesCoverageAndFindingOutcomes(): void
    {
        // Page 1: one run, both findings applied, then signed off → Geprüft.
        $runA = $this->storeRun(1, [$this->finding('spelling'), $this->finding('grammar')]);
        $this->states->setState($runA, 0, FindingStateRepository::ACCEPTED, 1);
        $this->states->setState($runA, 1, FindingStateRepository::ACCEPTED, 1);
        $this->reviews->markProofed(1, 1);

        // Page 2: a superseded run with a manual decision — the decision must
        // still be credited although the run is no longer the latest — then a
        // newer run with one dismissed and two open findings (one of them with
        // an unknown category, which counts in the total only).
        $runB = $this->storeRun(2, [$this->finding('spelling')]);
        $this->states->setState($runB, 0, FindingStateRepository::MANUAL, 1);
        $runC = $this->storeRun(2, [$this->finding('punctuation'), $this->finding('spelling'), $this->finding('does-not-exist')]);
        $this->states->setState($runC, 0, FindingStateRepository::DISMISSED, 1);

        // Runs of non-content pages must not count toward coverage or open
        // findings: a folder (doktype 254) and a deleted page.
        $this->storeRun(4, [$this->finding('spelling')]);
        $this->storeRun(5, [$this->finding('spelling')]);

        $result = $this->statistics->collect();

        // Denominator: pages 1, 2, 3 (folder, deleted page, translation excluded).
        self::assertSame(3, $result['totalPages']);
        self::assertSame(2, $result['checkedPages']);
        self::assertSame(67, $result['checkedPercent']);
        self::assertSame(1, $result['proofedPages']);
        self::assertSame(1, $result['reportCreatedPages']);
        self::assertSame(1, $result['uncheckedPages']);

        // Decisions are cumulative across all runs, incl. the superseded one.
        self::assertSame(2, $result['fixedAccepted']);
        self::assertSame(1, $result['fixedManual']);
        self::assertSame(3, $result['fixedTotal']);
        self::assertSame(1, $result['dismissed']);

        // Open = undecided findings of the latest runs of live content pages:
        // page 1 has none (all accepted), page 2's latest run has indices 1 + 2.
        self::assertSame(2, $result['openTotal']);
        self::assertSame([['label' => 'Rechtschreibung', 'count' => 1]], $result['openByCategory']);
    }

    public function testEmptyInstallationYieldsZeroesWithoutErrors(): void
    {
        $result = $this->statistics->collect();

        self::assertSame(3, $result['totalPages']);
        self::assertSame(0, $result['checkedPages']);
        self::assertSame(0, $result['checkedPercent']);
        self::assertSame(0, $result['fixedTotal']);
        self::assertSame(0, $result['openTotal']);
        self::assertSame([], $result['openByCategory']);
    }
}
