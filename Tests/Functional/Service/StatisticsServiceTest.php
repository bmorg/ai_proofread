<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Tests\Functional\Service;

use Bmorg\AiProofread\Service\FindingStateRepository;
use Bmorg\AiProofread\Service\LogRepository;
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
        $this->statistics = new StatisticsService($pool, $this->reports, $this->reviews, $this->states, new LogRepository($pool));
    }

    /**
     * A raw audit-log row with a controlled crdate (LogRepository stamps
     * time() on insert, which the monthly bucketing tests can't use).
     */
    private function insertLogRow(string $model, int $reportUid, float $costUsd, int $inputTokens, int $outputTokens, int $crdate, bool $success = true): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_aiproofread_log')
            ->insert('tx_aiproofread_log', [
                'pid' => 0,
                'crdate' => $crdate,
                'tstamp' => $crdate,
                'be_user' => 1,
                'page_uid' => 1,
                'report_uid' => $reportUid,
                'model' => $model,
                'provider' => '',
                'request_json' => '{}',
                'response_json' => '{}',
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_usd' => $costUsd,
                'duration_ms' => 100,
                'success' => $success ? 1 : 0,
                'error_message' => '',
            ]);
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

        self::assertSame(0, $result['cost']['calls']);
        self::assertSame(0.0, $result['cost']['totalUsd']);
        self::assertNull($result['cost']['perRunUsd']);
        self::assertSame([], $result['cost']['months']);
        self::assertSame([], $result['cost']['byModel']);
    }

    public function testCostMetricsAggregateTheAuditLog(): void
    {
        $now = time();
        $year = (int)date('Y');
        $month = (int)date('n');
        // Mid-month timestamps so the buckets are unambiguous regardless of
        // when the test runs; mktime() normalizes across year boundaries.
        $previousMonth = mktime(12, 0, 0, $month - 1, 15, $year);
        $beyondWindow = mktime(12, 0, 0, $month - 13, 15, $year);

        // Current month: a two-call run, plus a call of a failed run
        // (report_uid 0 — counts toward spend but not the per-run average)
        // and a mock call that must be excluded everywhere.
        $this->insertLogRow('model-a', 101, 0.02, 1000, 500, $now);
        $this->insertLogRow('model-a', 101, 0.03, 2000, 700, $now);
        $this->insertLogRow('model-a', 0, 0.01, 0, 0, $now, false);
        $this->insertLogRow('mock', 102, 0.0, 9999, 9999, $now);
        // Previous month and beyond the 12-month window (the latter counts in
        // the all-time totals but not in the monthly breakdown).
        $this->insertLogRow('model-b', 103, 0.05, 4000, 1000, $previousMonth);
        $this->insertLogRow('model-b', 104, 0.40, 100, 100, $beyondWindow);

        $cost = $this->statistics->collect()['cost'];

        self::assertSame(5, $cost['calls']);
        self::assertSame(3, $cost['runs']);
        self::assertEqualsWithDelta(0.51, $cost['totalUsd'], 1e-9);
        self::assertSame(9400, $cost['totalTokens']);
        self::assertEqualsWithDelta(0.06, $cost['currentMonthUsd'], 1e-9);
        // Run-attributed spend only: (0.51 - 0.01 failed) / 3 runs.
        self::assertEqualsWithDelta(0.5 / 3, $cost['perRunUsd'], 1e-9);

        // Monthly breakdown: newest first, empty months and the >12-month-old
        // row omitted.
        self::assertCount(2, $cost['months']);
        self::assertSame(3, $cost['months'][0]['calls']);
        self::assertSame(1, $cost['months'][0]['runs']);
        self::assertEqualsWithDelta(0.06, $cost['months'][0]['costUsd'], 1e-9);
        self::assertSame(4200, $cost['months'][0]['tokens']);
        self::assertSame(1, $cost['months'][1]['calls']);
        self::assertEqualsWithDelta(0.05, $cost['months'][1]['costUsd'], 1e-9);

        // Per model, most expensive first; the failed call is in model-a's
        // spend but not its per-run average.
        self::assertCount(2, $cost['byModel']);
        self::assertSame('model-b', $cost['byModel'][0]['model']);
        self::assertEqualsWithDelta(0.45, $cost['byModel'][0]['costUsd'], 1e-9);
        self::assertSame(2, $cost['byModel'][0]['runs']);
        self::assertEqualsWithDelta(0.225, $cost['byModel'][0]['perRunUsd'], 1e-9);
        self::assertSame('model-a', $cost['byModel'][1]['model']);
        self::assertSame(3, $cost['byModel'][1]['calls']);
        self::assertSame(1, $cost['byModel'][1]['runs']);
        self::assertEqualsWithDelta(0.06, $cost['byModel'][1]['costUsd'], 1e-9);
        self::assertEqualsWithDelta(0.05, $cost['byModel'][1]['perRunUsd'], 1e-9);
    }
}
