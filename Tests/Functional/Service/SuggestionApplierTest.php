<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Tests\Functional\Service;

use Bmorg\AiProofread\Service\SuggestionApplier;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The write path end-to-end against a real TYPO3: real TCA (fluid_styled_content
 * CTypes incl. enableRichtext), real DataHandler (permissions, RTE transformation,
 * sys_history). This is the extension's only content mutation — the tests assert
 * both that it writes what it should and that it never touches what it must not.
 */
final class SuggestionApplierTest extends FunctionalTestCase
{
    protected $coreExtensionsToLoad = ['fluid_styled_content', 'rte_ckeditor'];

    protected $testExtensionsToLoad = ['typo3conf/ext/ai_proofread'];

    private SuggestionApplier $applier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/ApplyScenario.csv');
        $this->applier = new SuggestionApplier(GeneralUtility::makeInstance(ConnectionPool::class));
    }

    private function loginAdmin(): void
    {
        $this->setUpBackendUser(1);
        Bootstrap::initializeLanguageObject();
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchElement(int $uid): array
    {
        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content')
            ->select(['header', 'bodytext'], 'tt_content', ['uid' => $uid])
            ->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    public function testApplyOnHeaderWritesFieldAndRecordsHistory(): void
    {
        $this->loginAdmin();

        $status = $this->applier->apply(1, 10, 'unsrer', 'unserer');

        self::assertSame(SuggestionApplier::APPLIED, $status);
        self::assertSame('Willkommen auf unserer Seite', $this->fetchElement(10)['header']);

        // DataHandler recorded the change — the undo path for applied suggestions.
        $historyCount = (int)GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_history')
            ->count('uid', 'sys_history', ['tablename' => 'tt_content', 'recuid' => 10]);
        self::assertGreaterThan(0, $historyCount);
    }

    public function testApplyOnRteBodytextReplacesQuoteAndKeepsMarkup(): void
    {
        $this->loginAdmin();

        $locate = $this->applier->locate(1, 10, 'Fehlern drin');
        self::assertTrue($locate->applicable);

        $status = $this->applier->apply(1, 10, 'Fehlern drin', 'Fehler drin');

        self::assertSame(SuggestionApplier::APPLIED, $status);
        $bodytext = (string)$this->fetchElement(10)['bodytext'];
        self::assertStringContainsString('Fehler drin', $bodytext);
        self::assertStringNotContainsString('Fehlern drin', $bodytext);
        // DataHandler RTE round-trip must not lose surrounding markup/content.
        self::assertStringContainsString('<strong>wichtiger</strong>', $bodytext);
        self::assertStringContainsString('Zweiter Absatz.', $bodytext);
    }

    /**
     * THE corruption regression on the real stack: a "table" element's plain-text
     * bodytext must be refused and stay byte-identical ("a & b" once came back
     * as "a &amp; b" from the HTML round-trip).
     */
    public function testApplyOnNonRteBodytextIsRefusedAndFieldUntouched(): void
    {
        $this->loginAdmin();

        $locate = $this->applier->locate(1, 11, 'a & b');
        self::assertFalse($locate->applicable);

        $status = $this->applier->apply(1, 11, 'a & b', 'a und b');

        self::assertSame(SuggestionApplier::UNSUPPORTED, $status);
        self::assertSame('Produkt|a & b|Preis', $this->fetchElement(11)['bodytext']);
    }

    public function testHeaderOfNonRteElementIsStillAppliable(): void
    {
        $this->loginAdmin();

        $status = $this->applier->apply(1, 11, '2023', '2026');

        self::assertSame(SuggestionApplier::APPLIED, $status);
        self::assertSame('Preisliste 2026', $this->fetchElement(11)['header']);
    }

    public function testEditorWithoutTablesModifyIsRefusedCleanly(): void
    {
        $this->setUpBackendUser(2);
        Bootstrap::initializeLanguageObject();

        $status = $this->applier->apply(1, 10, 'unsrer', 'unserer');

        self::assertSame(SuggestionApplier::NO_PERMISSION, $status);
        self::assertSame('Willkommen auf unsrer Seite', $this->fetchElement(10)['header']);
    }

    public function testElementOnAnotherPageIsNotFound(): void
    {
        $this->loginAdmin();

        // Element 10 lives on page 1 — resolving it through page 2 must fail
        // (the pid scoping that keeps hand-crafted elementUids on the gated page).
        $status = $this->applier->apply(2, 10, 'unsrer', 'unserer');

        self::assertSame(SuggestionApplier::NOT_FOUND, $status);
        self::assertSame('Willkommen auf unsrer Seite', $this->fetchElement(10)['header']);
    }
}
