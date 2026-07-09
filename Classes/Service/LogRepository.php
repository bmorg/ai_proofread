<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service;

use Bmorg\AiProofread\Service\Llm\MockClient;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Append-only audit log of every LLM call. Records the full request and
 * response, the initiating backend user, token usage and estimated cost — for
 * both successful and failed generations.
 */
final class LogRepository
{
    private const TABLE = 'tx_aiproofread_log';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @param array<string, mixed> $request the full request payload sent to the API
     * @param array<string, mixed> $response the full decoded API response
     */
    public function logSuccess(int $beUser, int $pageUid, string $model, string $provider, array $request, array $response, int $inputTokens, int $outputTokens, float $costUsd, ?int $durationMs = null, int $reportUid = 0, int $elementUid = 0, string $elementLabel = ''): void
    {
        $this->insert([
            'be_user' => $beUser,
            'page_uid' => $pageUid,
            'report_uid' => $reportUid,
            'element_uid' => $elementUid,
            'element_label' => $elementLabel,
            'model' => $model,
            'provider' => $provider,
            'request_json' => $this->encode($request),
            'response_json' => $this->encode($response),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_usd' => $costUsd,
            'duration_ms' => (int)$durationMs,
            'success' => 1,
            'error_message' => '',
        ]);
    }

    /**
     * @param array<string, mixed> $request the request that was attempted
     */
    public function logError(int $beUser, int $pageUid, string $model, string $provider, array $request, string $rawResponse, string $error, ?int $durationMs = null, int $reportUid = 0, int $elementUid = 0, string $elementLabel = ''): void
    {
        $this->insert([
            'be_user' => $beUser,
            'page_uid' => $pageUid,
            'report_uid' => $reportUid,
            'element_uid' => $elementUid,
            'element_label' => $elementLabel,
            'model' => $model,
            'provider' => $provider,
            'request_json' => $this->encode($request),
            'response_json' => $rawResponse,
            'duration_ms' => (int)$durationMs,
            'success' => 0,
            'error_message' => $error,
        ]);
    }

    /**
     * Most-recent-first page of log rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findRecent(int $limit = 200): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return $queryBuilder
            ->select('uid', 'crdate', 'be_user', 'page_uid', 'element_uid', 'model', 'provider', 'input_tokens', 'output_tokens', 'cost_usd', 'duration_ms', 'success')
            ->from(self::TABLE)
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function findByUid(int $uid): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['*'], self::TABLE, ['uid' => $uid])
            ->fetchAssociative();

        return $row ?: null;
    }

    /**
     * Aggregated API usage over a crdate window (0 = unbounded), for the
     * Statistik view's cost metrics. Mock calls are excluded throughout — they
     * are free and would dilute counts and averages. "runs" counts distinct
     * reports; "runCostUsd" is the share of the cost attributable to those
     * runs (failed runs log with report_uid = 0), the denominator-matching
     * base for a per-run average, while "costUsd" is the full spend including
     * failed runs.
     *
     * @return array{calls: int, runs: int, costUsd: float, runCostUsd: float, inputTokens: int, outputTokens: int}
     */
    public function costTotals(int $from = 0, int $until = 0): array
    {
        $queryBuilder = $this->costQuery();
        $queryBuilder->selectLiteral(
            'COUNT(*) AS calls',
            'COUNT(DISTINCT CASE WHEN report_uid > 0 THEN report_uid END) AS runs',
            'SUM(cost_usd) AS cost_usd',
            'SUM(CASE WHEN report_uid > 0 THEN cost_usd ELSE 0 END) AS run_cost_usd',
            'SUM(input_tokens) AS input_tokens',
            'SUM(output_tokens) AS output_tokens'
        );
        if ($from > 0) {
            $queryBuilder->andWhere($queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($from, Connection::PARAM_INT)));
        }
        if ($until > 0) {
            $queryBuilder->andWhere($queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter($until, Connection::PARAM_INT)));
        }
        $row = $queryBuilder->executeQuery()->fetchAssociative() ?: [];

        return [
            'calls' => (int)($row['calls'] ?? 0),
            'runs' => (int)($row['runs'] ?? 0),
            'costUsd' => (float)($row['cost_usd'] ?? 0),
            'runCostUsd' => (float)($row['run_cost_usd'] ?? 0),
            'inputTokens' => (int)($row['input_tokens'] ?? 0),
            'outputTokens' => (int)($row['output_tokens'] ?? 0),
        ];
    }

    /**
     * The same aggregates grouped by model, all-time, most expensive first
     * (mock excluded, like {@see costTotals}).
     *
     * @return list<array{model: string, calls: int, runs: int, costUsd: float, runCostUsd: float}>
     */
    public function costByModel(): array
    {
        $queryBuilder = $this->costQuery();
        $rows = $queryBuilder
            ->select('model')
            ->addSelectLiteral(
                'COUNT(*) AS calls',
                'COUNT(DISTINCT CASE WHEN report_uid > 0 THEN report_uid END) AS runs',
                'SUM(cost_usd) AS cost_usd',
                'SUM(CASE WHEN report_uid > 0 THEN cost_usd ELSE 0 END) AS run_cost_usd'
            )
            ->groupBy('model')
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'model' => (string)$row['model'],
                'calls' => (int)$row['calls'],
                'runs' => (int)$row['runs'],
                'costUsd' => (float)$row['cost_usd'],
                'runCostUsd' => (float)$row['run_cost_usd'],
            ];
        }
        // Sorted in PHP, not SQL: ORDER BY an aggregate alias is not portable
        // across the supported DB platforms.
        usort($result, static fn (array $a, array $b): int => $b['costUsd'] <=> $a['costUsd']);
        return $result;
    }

    /** Base query for the cost aggregates: the log table minus mock calls. */
    private function costQuery(): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->neq('model', $queryBuilder->createNamedParameter(MockClient::MODEL)));
        return $queryBuilder;
    }

    private function insert(array $fields): void
    {
        $now = time();
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(
            self::TABLE,
            $fields + ['pid' => 0, 'crdate' => $now, 'tstamp' => $now]
        );
    }

    private function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
