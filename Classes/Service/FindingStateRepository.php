<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Per-finding review state for the review-and-fix queue (tx_aiproofread_finding_state).
 *
 * A report run is an immutable snapshot, so an editor's accept/dismiss decision on
 * a single localized finding is stored here, keyed by the run (report_uid) and the
 * finding's index in that run's stored, already-sorted findings array. Absence of a
 * row means the finding is still open; "accepted" means its suggestion was written
 * back to the content element, "manual" means the editor fixed it themselves, and
 * "dismissed" means the editor rejected it.
 */
final class FindingStateRepository
{
    public const ACCEPTED = 'accepted';
    public const MANUAL = 'manual';
    public const DISMISSED = 'dismissed';

    private const TABLE = 'tx_aiproofread_finding_state';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * The stored state of every decided finding in a run, keyed by finding index.
     * Findings without a row are open and simply absent from the map.
     *
     * @return array<int, string> finding index => status
     */
    public function statesFor(int $reportUid): array
    {
        $rows = $this->connection()
            ->select(['finding_index', 'status'], self::TABLE, ['report_uid' => $reportUid])
            ->fetchAllAssociative();

        $states = [];
        foreach ($rows as $row) {
            $states[(int)$row['finding_index']] = (string)$row['status'];
        }
        return $states;
    }

    /**
     * Record (or overwrite) the editor's decision on a single finding. Idempotent:
     * the unique key (report_uid, finding_index) means one decision per finding,
     * so re-deciding updates the existing row.
     */
    public function setState(int $reportUid, int $findingIndex, string $status, int $beUser): void
    {
        $connection = $this->connection();
        $where = ['report_uid' => $reportUid, 'finding_index' => $findingIndex];
        if ($connection->count('uid', self::TABLE, $where) > 0) {
            $connection->update(self::TABLE, ['status' => $status, 'be_user' => $beUser, 'tstamp' => time()], $where);
            return;
        }
        $connection->insert(self::TABLE, $where + [
            'status' => $status,
            'be_user' => $beUser,
            'crdate' => time(),
            'tstamp' => time(),
        ]);
    }

    /**
     * Clear a finding's decision, returning it to "open" (used by the dismiss-undo).
     */
    public function clearState(int $reportUid, int $findingIndex): void
    {
        $this->connection()->delete(self::TABLE, ['report_uid' => $reportUid, 'finding_index' => $findingIndex]);
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
