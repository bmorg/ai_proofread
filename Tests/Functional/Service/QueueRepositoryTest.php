<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Tests\Functional\Service;

use Bmorg\AiProofread\Service\QueueRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Queue lifecycle: one row per page/language. The status check dedupes the
 * normal path; the UNIQUE key on (page_uid, language_uid) closes the
 * check-then-insert race (double-click → double-billed run) at schema level —
 * this suite verifies both, against the real ext_tables.sql schema.
 */
final class QueueRepositoryTest extends FunctionalTestCase
{
    private const TABLE = 'tx_aiproofread_queue';

    protected $testExtensionsToLoad = ['typo3conf/ext/ai_proofread'];

    private QueueRepository $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = new QueueRepository(GeneralUtility::makeInstance(ConnectionPool::class));
    }

    private function connection(): Connection
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);
    }

    private function rowCount(int $pageUid): int
    {
        return (int)$this->connection()->count('uid', self::TABLE, ['page_uid' => $pageUid]);
    }

    public function testEnqueueDedupesWhilePendingAndRunning(): void
    {
        $this->queue->enqueue(1, 0, 1);
        $this->queue->enqueue(1, 0, 1);
        self::assertSame(1, $this->rowCount(1));

        $job = $this->queue->claimNext();
        self::assertNotNull($job);
        self::assertSame(QueueRepository::RUNNING, $job['status']);

        $this->queue->enqueue(1, 0, 1);
        self::assertSame(1, $this->rowCount(1));
    }

    public function testEnqueueReplacesErrorRowWithFreshPendingJob(): void
    {
        $this->queue->enqueue(1, 0, 1);
        $job = $this->queue->claimNext();
        $this->queue->markError((int)$job['uid'], 'kaputt');

        $this->queue->enqueue(1, 0, 2);

        self::assertSame(1, $this->rowCount(1));
        $row = $this->queue->findForPage(1);
        self::assertSame(QueueRepository::PENDING, $row['status']);
        self::assertSame(2, (int)$row['be_user']);
    }

    /**
     * The race-closing guarantee itself: a second insert for the same
     * page/language — the state two concurrent enqueues would reach after both
     * passing the status check — is rejected by the schema, not merely by code.
     */
    public function testSchemaRejectsSecondRowForSamePageAndLanguage(): void
    {
        $fields = ['page_uid' => 1, 'language_uid' => 0, 'be_user' => 1, 'status' => QueueRepository::PENDING, 'crdate' => 1, 'tstamp' => 1];
        $this->connection()->insert(self::TABLE, $fields);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection()->insert(self::TABLE, $fields);
    }

    public function testClaimNextReturnsNullOnEmptyQueueAndClaimsOldestFirst(): void
    {
        self::assertNull($this->queue->claimNext());

        $this->queue->enqueue(1, 0, 1);
        $this->queue->enqueue(2, 0, 1);

        self::assertSame(1, (int)$this->queue->claimNext()['page_uid']);
        self::assertSame(2, (int)$this->queue->claimNext()['page_uid']);
        self::assertNull($this->queue->claimNext());
    }
}
