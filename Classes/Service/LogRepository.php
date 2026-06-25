<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

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
