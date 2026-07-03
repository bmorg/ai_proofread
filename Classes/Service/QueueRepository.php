<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The async run queue (tx_aiproofread_queue). A "Report erstellen" click
 * enqueues a job; the aiproofread:process-queue command (run via the Scheduler)
 * drains it. A run is N+1 LLM requests, far too slow to do inline.
 *
 * At most one live row per page/language: while pending or running it blocks a
 * duplicate enqueue; a successful run deletes the row (the stored report run is
 * the result); a failed run keeps the row with status "error" and the message so
 * the module can show it.
 */
final class QueueRepository
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const ERROR = 'error';

    private const TABLE = 'tx_aiproofread_queue';

    // A running job whose heartbeat (tstamp) hasn't advanced for this long is
    // treated as abandoned (worker OOM/timeout/deploy). The worker heartbeats as
    // each of the N+1 passes settles, so a legitimately long multi-element run
    // keeps refreshing and is NOT reclaimed — only a dead worker goes stale. This
    // is comfortably beyond the longest a single pass can take (the request
    // timeout, ≤600s with reasoning), which bounds the gap between heartbeats.
    private const STALE_RUNNING_SECONDS = 1800;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Enqueue a run for a page, unless one is already pending/running. A prior
     * error row for the page is cleared and replaced by a fresh pending job.
     */
    public function enqueue(int $pageUid, int $languageUid, int $beUserId): void
    {
        $connection = $this->connection();

        $existing = $connection
            ->select(['status'], self::TABLE, ['page_uid' => $pageUid, 'language_uid' => $languageUid])
            ->fetchAllAssociative();
        foreach ($existing as $row) {
            if (\in_array((string)$row['status'], [self::PENDING, self::RUNNING], true)) {
                return;
            }
        }

        $connection->delete(self::TABLE, ['page_uid' => $pageUid, 'language_uid' => $languageUid]);

        $now = time();
        $connection->insert(self::TABLE, [
            'page_uid' => $pageUid,
            'language_uid' => $languageUid,
            'be_user' => $beUserId,
            'status' => self::PENDING,
            'crdate' => $now,
            'tstamp' => $now,
        ]);
    }

    /**
     * Claim the oldest pending job, marking it running. Returns the job row or
     * null if the queue is empty.
     *
     * The claim is atomic per row: the flip to "running" is a conditional UPDATE
     * guarded by status='pending', and we trust the affected-row count. If a
     * concurrent worker (overlapping Scheduler runs) won the same row, our UPDATE
     * matches 0 rows — we skip it and try the next pending job, instead of running
     * (and billing for) the same job twice. The loop terminates: each iteration
     * either claims a row or the pool of pending rows has shrunk by one.
     *
     * @return array<string, mixed>|null
     */
    public function claimNext(): ?array
    {
        $connection = $this->connection();

        while (true) {
            $row = $connection
                ->select(['*'], self::TABLE, ['status' => self::PENDING], [], ['uid' => 'ASC'], 1)
                ->fetchAssociative();
            if (!$row) {
                return null;
            }

            $now = time();
            $claimed = $connection->update(
                self::TABLE,
                ['status' => self::RUNNING, 'started_at' => $now, 'tstamp' => $now],
                ['uid' => (int)$row['uid'], 'status' => self::PENDING]
            );

            if ($claimed === 1) {
                $row['status'] = self::RUNNING;
                $row['started_at'] = $now;
                $row['tstamp'] = $now;
                return $row;
            }
            // Lost the race for this row; loop to try the next pending job.
        }
    }

    /**
     * Bump a running job's heartbeat (tstamp) so the reclaim treats it as alive.
     * The worker calls this as each of the N+1 concurrent passes settles — since a
     * single pass can take up to the request timeout, a long run keeps refreshing
     * instead of tripping reclaimStaleRunning(). Guarded on status='running' so it
     * can never resurrect a job that already finished, errored or was reclaimed.
     */
    public function heartbeat(int $uid): void
    {
        $this->connection()->update(
            self::TABLE,
            ['tstamp' => time()],
            ['uid' => $uid, 'status' => self::RUNNING]
        );
    }

    /**
     * Reclaim jobs stuck in "running": if a worker dies mid-run (timeout, fatal,
     * deploy) the row stays "running" forever — claimNext() only selects pending so
     * it is never retried, enqueue() refuses a new run, and the module shows
     * "wird erstellt …" indefinitely (auto-refreshing). Flip stale ones to "error"
     * so the page is un-wedged: the editor sees the failure, and a re-run is allowed
     * again (enqueue() clears the error row). Called at the start of each drain.
     *
     * Staleness is measured on the **heartbeat** (tstamp), not started_at: a live
     * run bumps tstamp as each pass settles (see heartbeat()), so a legitimately
     * long multi-element run — which can exceed the threshold in wall-clock — is
     * not mistaken for a dead worker and killed while alive.
     *
     * @return int number of jobs reclaimed
     */
    public function reclaimStaleRunning(int $olderThanSeconds = self::STALE_RUNNING_SECONDS): int
    {
        $cutoff = time() - max(0, $olderThanSeconds);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return (int)$queryBuilder
            ->update(self::TABLE)
            ->set('status', self::ERROR)
            ->set('error_message', 'Verarbeitung abgebrochen (Worker vorzeitig beendet). Bitte erneut starten.')
            ->set('tstamp', time())
            ->where(
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter(self::RUNNING)),
                $queryBuilder->expr()->lt('tstamp', $queryBuilder->createNamedParameter($cutoff, Connection::PARAM_INT))
            )
            ->executeStatement();
    }

    public function markError(int $uid, string $message): void
    {
        $this->connection()->update(
            self::TABLE,
            ['status' => self::ERROR, 'error_message' => $message, 'tstamp' => time()],
            ['uid' => $uid]
        );
    }

    public function delete(int $uid): void
    {
        $this->connection()->delete(self::TABLE, ['uid' => $uid]);
    }

    /**
     * The live queue row for a page (for the module status), or null.
     *
     * @return array<string, mixed>|null
     */
    public function findForPage(int $pageUid, int $languageUid = 0): ?array
    {
        $row = $this->connection()
            ->select(['*'], self::TABLE, ['page_uid' => $pageUid, 'language_uid' => $languageUid], [], ['uid' => 'DESC'], 1)
            ->fetchAssociative();

        return $row ?: null;
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
