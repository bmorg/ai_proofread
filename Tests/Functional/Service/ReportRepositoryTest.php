<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Tests\Functional\Service;

use Bmorg\AiProofread\Service\ReportRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Report persistence + the page-ownership rule. findByUidForPage() is the guard
 * behind every ?reportUid= entry point — the regression test for the cross-page
 * report-content disclosure.
 */
final class ReportRepositoryTest extends FunctionalTestCase
{
    protected $testExtensionsToLoad = ['typo3conf/ext/ai_proofread'];

    private ReportRepository $reports;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reports = new ReportRepository(GeneralUtility::makeInstance(ConnectionPool::class));
    }

    private function storeRun(int $pageUid): int
    {
        return $this->reports->store(
            $pageUid,
            0,
            1,
            'test-model',
            '{"findings":[],"pageFindings":[],"other":[]}',
            'spelling,punctuation,grammar',
            0.01,
            1200,
            'hash-' . $pageUid
        );
    }

    public function testStoreAndFindLatestRoundTrip(): void
    {
        $first = $this->storeRun(1);
        $second = $this->storeRun(1);

        $latest = $this->reports->findLatestByPage(1);
        self::assertNotNull($latest);
        self::assertSame($second, (int)$latest['uid']);
        self::assertGreaterThan($first, $second);
        self::assertCount(2, $this->reports->findByPage(1));
    }

    public function testFindByUidForPageReturnsOwnPagesRun(): void
    {
        $uid = $this->storeRun(1);

        $run = $this->reports->findByUidForPage($uid, 1);

        self::assertNotNull($run);
        self::assertSame(1, (int)$run['page_uid']);
    }

    /**
     * Disclosure regression: a run resolved through a different page's context
     * must not be returned — otherwise ?id=<accessible page>&reportUid=<foreign
     * run> would leak the foreign page's report content (verbatim quotes).
     */
    public function testFindByUidForPageRefusesForeignPage(): void
    {
        $uid = $this->storeRun(2);

        self::assertNull($this->reports->findByUidForPage($uid, 1));
        // The run itself exists — only the page-scoped resolution refuses.
        self::assertNotNull($this->reports->findByUid($uid));
    }

    public function testFindByUidForPageToleratesUnknownUid(): void
    {
        self::assertNull($this->reports->findByUidForPage(999999, 1));
    }
}
