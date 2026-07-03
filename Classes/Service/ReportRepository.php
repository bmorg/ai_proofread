<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Report history: one immutable row per generated report (one run) in
 * tx_aiproofread_report. The newest row for a page is its "Aktueller Report";
 * the full list is the "Report-Verlauf" (which decodes report_json for the
 * per-category finding breakdown).
 */
final class ReportRepository
{
    private const TABLE = 'tx_aiproofread_report';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Persist a finished report run. Returns the new row uid.
     */
    public function store(
        int $pageUid,
        int $languageUid,
        int $beUser,
        string $model,
        string $reportJson,
        string $categories,
        float $costUsd,
        int $durationMs,
        string $contentHash,
    ): int {
        $connection = $this->connection();
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'crdate' => time(),
            'page_uid' => $pageUid,
            'language_uid' => $languageUid,
            'be_user' => $beUser,
            'model' => $model,
            'report_json' => $reportJson,
            'categories' => $categories,
            'cost_usd' => $costUsd,
            'duration_ms' => $durationMs,
            'content_hash' => $contentHash,
        ]);

        return (int)$connection->lastInsertId();
    }

    /**
     * All report runs for a page, newest first (for the Report-Verlauf table).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByPage(int $pageUid): array
    {
        return $this->connection()
            ->select(['*'], self::TABLE, ['page_uid' => $pageUid], [], ['crdate' => 'DESC', 'uid' => 'DESC'])
            ->fetchAllAssociative();
    }

    /**
     * The newest report run for a page, or null.
     *
     * @return array<string, mixed>|null
     */
    public function findLatestByPage(int $pageUid): ?array
    {
        $row = $this->connection()
            ->select(['*'], self::TABLE, ['page_uid' => $pageUid], [], ['crdate' => 'DESC', 'uid' => 'DESC'], 1)
            ->fetchAssociative();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUid(int $uid): ?array
    {
        $row = $this->connection()
            ->select(['*'], self::TABLE, ['uid' => $uid])
            ->fetchAssociative();

        return $row ?: null;
    }

    /**
     * A report run by uid, but only when it belongs to the given page. This is the
     * page-ownership rule for every ?reportUid= entry point: the module gates
     * access per page, so a run may only be resolved through a page the user was
     * already cleared for — a hand-crafted uid for another page's run returns null
     * (no disclosure of foreign report content, no action on it).
     *
     * @return array<string, mixed>|null
     */
    public function findByUidForPage(int $uid, int $pageUid): ?array
    {
        $row = $this->findByUid($uid);
        if ($row === null || (int)($row['page_uid'] ?? 0) !== $pageUid) {
            return null;
        }

        return $row;
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
