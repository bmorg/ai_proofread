<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service;

use Bmorg\AiProofread\Enum\ReviewStatus;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Per-page editor sign-off state in tx_aiproofread_review (one row per page,
 * recording when/by whom it was last marked "geprüft"). The editor-facing
 * status is *derived* from this plus the page's latest report run — no stored
 * status string, no content hash.
 */
final class ReviewRepository
{
    private const TABLE = 'tx_aiproofread_review';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByPage(int $pageUid): ?array
    {
        $row = $this->connection()
            ->select(['*'], self::TABLE, ['page_uid' => $pageUid])
            ->fetchAssociative();

        return $row ?: null;
    }

    /**
     * Record that the editor signed off on the page now.
     */
    public function markProofed(int $pageUid, int $beUser): void
    {
        $fields = ['proofed_at' => time(), 'proofed_by' => $beUser];
        $connection = $this->connection();
        if ($this->findByPage($pageUid) !== null) {
            $connection->update(self::TABLE, $fields, ['page_uid' => $pageUid]);
            return;
        }
        $connection->insert(self::TABLE, $fields + ['page_uid' => $pageUid]);
    }

    /**
     * Derive the status from the page's latest report run and its sign-off row.
     * No run → Ungeprüft. Signed off at/after the latest run → Geprüft.
     * Otherwise (run newer than the sign-off, or never signed off) → Report erstellt.
     *
     * @param array<string, mixed>|null $latestReport
     * @param array<string, mixed>|null $review
     */
    public function deriveStatus(?array $latestReport, ?array $review): ReviewStatus
    {
        if ($latestReport === null) {
            return ReviewStatus::Unchecked;
        }
        $proofedAt = (int)($review['proofed_at'] ?? 0);
        if ($proofedAt > 0 && $proofedAt >= (int)($latestReport['crdate'] ?? 0)) {
            return ReviewStatus::Proofed;
        }

        return ReviewStatus::ReportCreated;
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
