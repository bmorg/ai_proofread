<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service;

use Bmorg\AiProofread\Enum\Category;
use Bmorg\AiProofread\Enum\ReviewStatus;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Aggregates the numbers for the module's admin-only "Statistik" view: coverage
 * (how many content pages have a report / are signed off, against all that
 * exist) and outcome (what happened to the findings).
 *
 * "Behoben" counts recorded decisions (accepted + manual) cumulatively across
 * ALL runs: decisions persist per run, so errors that were fixed and no longer
 * appear in newer reports keep being credited — "25 found, all fixed, next
 * report shows 0" still reads as 25 fixes. Fixes made outside the
 * review-and-fix queue leave no decision row and are not counted (accepted
 * trade-off — it also nudges editors to use the queue).
 *
 * Everything is install-wide and default-language only (the extension is
 * L0-only by design).
 */
final class StatisticsService
{
    /**
     * Standard content page (doktype 1) — the coverage denominator. Folders,
     * shortcuts, external links etc. have no proofreadable content and would
     * make the ratio look artificially bad.
     */
    private const DOKTYPE_STANDARD = 1;

    /** German month names for the cost breakdown labels (1-indexed). */
    private const MONTHS = [
        1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
    ];

    /** How far back the monthly cost breakdown looks. */
    private const COST_MONTHS = 12;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ReportRepository $reports,
        private readonly ReviewRepository $reviews,
        private readonly FindingStateRepository $findingStates,
        private readonly LogRepository $logs,
    ) {
    }

    /**
     * All metrics for the Statistik view.
     *
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $totalPages = $this->countContentPages();

        // Latest run per page, narrowed to live content pages so runs of pages
        // that were since deleted (or that aren't content pages, e.g. a folder
        // checked via the bulk command) don't inflate coverage.
        $latestRuns = $this->reports->latestRunPerPage();
        $livePageUids = $this->filterContentPages(array_keys($latestRuns));
        $latestRuns = array_intersect_key($latestRuns, array_flip($livePageUids));

        $proofedAt = $this->reviews->proofedAtByPage();
        $proofed = 0;
        foreach ($latestRuns as $pageUid => $run) {
            // Same semantics as the per-page module status.
            $status = $this->reviews->deriveStatus($run, ['proofed_at' => $proofedAt[$pageUid] ?? 0]);
            if ($status === ReviewStatus::Proofed) {
                $proofed++;
            }
        }
        $checked = \count($latestRuns);

        $decisions = $this->findingStates->countByStatus();
        $accepted = $decisions[FindingStateRepository::ACCEPTED] ?? 0;
        $manual = $decisions[FindingStateRepository::MANUAL] ?? 0;
        $dismissed = $decisions[FindingStateRepository::DISMISSED] ?? 0;

        [$openTotal, $openByCategory] = $this->openFindings($latestRuns);

        return [
            'totalPages' => $totalPages,
            'checkedPages' => $checked,
            'checkedPercent' => $totalPages > 0 ? (int)round($checked / $totalPages * 100) : 0,
            'proofedPages' => $proofed,
            'reportCreatedPages' => $checked - $proofed,
            'uncheckedPages' => max(0, $totalPages - $checked),
            'fixedTotal' => $accepted + $manual,
            'fixedAccepted' => $accepted,
            'fixedManual' => $manual,
            'dismissed' => $dismissed,
            'openTotal' => $openTotal,
            'openByCategory' => $openByCategory,
            'cost' => $this->costMetrics(),
        ];
    }

    /**
     * API spend, sourced from the audit log (the complete record — it includes
     * calls of failed runs, which the report table misses; mock calls are
     * excluded in the repository). All raw numbers; USD formatting is the
     * controller's job.
     *
     * @return array<string, mixed>
     */
    private function costMetrics(): array
    {
        $allTime = $this->logs->costTotals();
        $monthStart = mktime(0, 0, 0, (int)date('n'), 1, (int)date('Y'));
        $currentMonth = $this->logs->costTotals($monthStart);

        $byModel = [];
        foreach ($this->logs->costByModel() as $row) {
            $row['perRunUsd'] = $row['runs'] > 0 ? $row['runCostUsd'] / $row['runs'] : null;
            $byModel[] = $row;
        }

        return [
            'calls' => $allTime['calls'],
            'runs' => $allTime['runs'],
            'totalUsd' => $allTime['costUsd'],
            'totalTokens' => $allTime['inputTokens'] + $allTime['outputTokens'],
            'currentMonthUsd' => $currentMonth['costUsd'],
            'currentMonthLabel' => self::MONTHS[(int)date('n')] . ' ' . date('Y'),
            // Per-run average over run-attributed spend only, so calls of
            // failed runs (report_uid = 0) don't skew it.
            'perRunUsd' => $allTime['runs'] > 0 ? $allTime['runCostUsd'] / $allTime['runs'] : null,
            'months' => $this->monthlyCosts(),
            'byModel' => $byModel,
        ];
    }

    /**
     * Per-calendar-month spend for the last COST_MONTHS months, newest first,
     * months without any (non-mock) call omitted. Bucketing happens here via
     * crdate windows — SQL date functions aren't portable across the supported
     * DB platforms (functional tests run on sqlite).
     *
     * @return list<array<string, mixed>>
     */
    private function monthlyCosts(): array
    {
        $year = (int)date('Y');
        $month = (int)date('n');

        $rows = [];
        for ($i = 0; $i < self::COST_MONTHS; $i++) {
            // mktime() normalizes out-of-range months across year boundaries.
            $start = mktime(0, 0, 0, $month - $i, 1, $year);
            $end = mktime(0, 0, 0, $month - $i + 1, 1, $year);
            $totals = $this->logs->costTotals($start, $end);
            if ($totals['calls'] === 0) {
                continue;
            }
            $rows[] = [
                'label' => self::MONTHS[(int)date('n', $start)] . ' ' . date('Y', $start),
                'calls' => $totals['calls'],
                'runs' => $totals['runs'],
                'costUsd' => $totals['costUsd'],
                'tokens' => $totals['inputTokens'] + $totals['outputTokens'],
            ];
        }
        return $rows;
    }

    /**
     * The coverage denominator: live default-language content pages. Hidden
     * pages are included — drafts are checkable, matching the extractor's
     * treatment of hidden content elements.
     */
    private function countContentPages(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return (int)$queryBuilder
            ->count('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('doktype', $queryBuilder->createNamedParameter(self::DOKTYPE_STANDARD, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * The subset of $pageUids that are live default-language content pages
     * (same conditions as the denominator).
     *
     * @param list<int> $pageUids
     * @return list<int>
     */
    private function filterContentPages(array $pageUids): array
    {
        if ($pageUids === []) {
            return [];
        }

        $live = [];
        // Chunked so the IN() list stays within DB parameter limits (sqlite: 999).
        foreach (array_chunk($pageUids, 500) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
            $rows = $queryBuilder
                ->select('uid')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->eq('doktype', $queryBuilder->createNamedParameter(self::DOKTYPE_STANDARD, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                )
                ->executeQuery()
                ->fetchFirstColumn();
            foreach ($rows as $uid) {
                $live[] = (int)$uid;
            }
        }
        return $live;
    }

    /**
     * Findings in the latest runs that no one has decided yet, total and per
     * known category (unknown categories count in the total only — same known
     * edge as the report rendering).
     *
     * Decodes one report_json per checked page. Admin-only and occasional, so
     * this is fine at current scale; if it ever hurts, denormalize an open/
     * findings count onto the report row instead of optimizing here.
     *
     * @param array<int, array<string, mixed>> $latestRuns
     * @return array{0: int, 1: list<array{label: string, count: int}>}
     */
    private function openFindings(array $latestRuns): array
    {
        $total = 0;
        $byCategory = [];
        foreach ($latestRuns as $run) {
            $report = json_decode((string)($run['report_json'] ?? ''), true);
            $findings = \is_array($report) && \is_array($report['findings'] ?? null) ? $report['findings'] : [];
            if ($findings === []) {
                continue;
            }
            $states = $this->findingStates->statesFor((int)$run['uid']);
            foreach ($findings as $index => $finding) {
                if (isset($states[(int)$index])) {
                    continue; // decided — counted via countByStatus()
                }
                $total++;
                $category = \is_array($finding) ? Category::tryFrom((string)($finding['category'] ?? '')) : null;
                if ($category !== null) {
                    $byCategory[$category->value] = ($byCategory[$category->value] ?? 0) + 1;
                }
            }
        }

        $rows = [];
        foreach (Category::ordered() as $category) {
            if (($byCategory[$category->value] ?? 0) > 0) {
                $rows[] = ['label' => $category->label(), 'count' => $byCategory[$category->value]];
            }
        }
        return [$total, $rows];
    }
}
