<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Tests\Unit\Service;

use Bmorg\AiProofread\Service\SuggestionApplier;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The quote-matching truth table of the apply write path. This is the
 * content-safety core: anything ambiguous or non-RTE must refuse with a typed
 * status and an empty replacement — never risk a wrong or corrupting edit.
 */
final class SuggestionApplierAnalyzeTest extends UnitTestCase
{
    private SuggestionApplier $applier;

    protected function setUp(): void
    {
        parent::setUp();
        // Minimal TCA mirroring core: RTE bodytext for "text"/"textmedia" via
        // columnsOverrides; "table" and "bullets" keep the plain-text base config.
        $GLOBALS['TCA']['tt_content'] = [
            'columns' => [
                'bodytext' => ['config' => ['type' => 'text']],
            ],
            'types' => [
                'text' => ['columnsOverrides' => ['bodytext' => ['config' => ['enableRichtext' => true]]]],
                'textmedia' => ['columnsOverrides' => ['bodytext' => ['config' => ['enableRichtext' => true]]]],
                'table' => [],
                'bullets' => [],
            ],
        ];
        $this->applier = new SuggestionApplier($this->createMock(ConnectionPool::class));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function textElement(array $overrides = []): array
    {
        return $overrides + [
            'uid' => 10,
            'pid' => 1,
            'CType' => 'text',
            'header' => '',
            'subheader' => '',
            'bodytext' => '',
        ];
    }

    public function testUniqueHeaderMatchApplies(): void
    {
        $row = $this->textElement(['header' => 'Willkommen auf unsrer Seite']);

        $result = $this->applier->analyze($row, 'unsrer', 'unserer');

        self::assertSame(SuggestionApplier::APPLIED, $result['status']);
        self::assertSame('header', $result['field']);
        self::assertSame('Willkommen auf unserer Seite', $result['newValue']);
    }

    public function testDuplicateInHeaderIsAmbiguous(): void
    {
        $row = $this->textElement(['header' => 'test und test']);

        $result = $this->applier->analyze($row, 'test', 'Test');

        self::assertSame(SuggestionApplier::AMBIGUOUS, $result['status']);
        self::assertSame('', $result['newValue']);
    }

    public function testBodytextSingleTextNodeMatchAppliesWithMarkupIntact(): void
    {
        $row = $this->textElement([
            'bodytext' => '<p>Das ist ein <strong>wichtiger</strong> Satz mit Fehlern drin.</p><p>Zweiter Absatz.</p>',
        ]);

        $result = $this->applier->analyze($row, 'Fehlern drin', 'Fehler drin');

        self::assertSame(SuggestionApplier::APPLIED, $result['status']);
        self::assertSame('bodytext', $result['field']);
        // Byte-identical outside the replaced span: markup, other paragraphs.
        self::assertSame(
            '<p>Das ist ein <strong>wichtiger</strong> Satz mit Fehler drin.</p><p>Zweiter Absatz.</p>',
            $result['newValue']
        );
    }

    public function testQuoteSpanningMarkupIsSpansMarkupAndNeverSpliced(): void
    {
        $row = $this->textElement([
            'bodytext' => '<p>Das ist ein <strong>wichtiger</strong> Satz.</p>',
        ]);

        $result = $this->applier->analyze($row, 'wichtiger Satz', 'zentraler Satz');

        self::assertSame(SuggestionApplier::SPANS_MARKUP, $result['status']);
        self::assertSame('', $result['newValue']);
    }

    public function testQuoteRepeatedWithinOneTextNodeIsAmbiguous(): void
    {
        $row = $this->textElement(['bodytext' => '<p>gut ist gut</p>']);

        $result = $this->applier->analyze($row, 'gut', 'schlecht');

        self::assertSame(SuggestionApplier::AMBIGUOUS, $result['status']);
    }

    public function testQuoteRepeatedAcrossParagraphsIsAmbiguous(): void
    {
        // One occurrence per text node — each node alone looks unique, only the
        // element-wide count reveals the ambiguity. Distinct code path from the
        // within-one-node repeat.
        $row = $this->textElement([
            'bodytext' => '<p>Der Preis ist gut.</p><p>Der Preis ist heiß.</p>',
        ]);

        $result = $this->applier->analyze($row, 'Der Preis', 'Die Preise');

        self::assertSame(SuggestionApplier::AMBIGUOUS, $result['status']);
        self::assertSame('', $result['newValue']);
    }

    public function testMarkupFullyInsideQuoteIsSpansMarkup(): void
    {
        // The quote starts and ends in plain text but contains a <strong> span —
        // no single text node holds it, only the concatenated element text does.
        $row = $this->textElement([
            'bodytext' => '<p>Das ist ein <strong>ganz</strong> toller Satz.</p>',
        ]);

        $result = $this->applier->analyze($row, 'ein ganz toller', 'ein sehr toller');

        self::assertSame(SuggestionApplier::SPANS_MARKUP, $result['status']);
        self::assertSame('', $result['newValue']);
    }

    public function testQuoteSpanningBrOrParagraphBoundaryIsNotFound(): void
    {
        // Extraction renders <br> and </p> as "\n" in the model's input, but the
        // DOM text nodes carry no such separator ("Zeile eins" + "Zeile zwei"
        // concatenate without one). A model quote spanning the boundary — with a
        // newline or a space where the break was — therefore matches neither a
        // single text node nor the concatenated element text: NOT_FOUND, not
        // SPANS_MARKUP. Both refuse and fall back to the deep-link; this test
        // pins which refusal (and thus which editor message) it is.
        $rowBr = $this->textElement(['bodytext' => '<p>Zeile eins<br/>Zeile zwei</p>']);
        $rowParagraphs = $this->textElement(['bodytext' => '<p>Erster Satz.</p><p>Zweiter Satz.</p>']);

        foreach (["eins\nZeile", 'eins Zeile'] as $quote) {
            $result = $this->applier->analyze($rowBr, $quote, 'egal');
            self::assertSame(SuggestionApplier::NOT_FOUND, $result['status'], 'br, quote: ' . json_encode($quote));
        }
        foreach (["Satz.\nZweiter", 'Satz. Zweiter'] as $quote) {
            $result = $this->applier->analyze($rowParagraphs, $quote, 'egal');
            self::assertSame(SuggestionApplier::NOT_FOUND, $result['status'], 'p-boundary, quote: ' . json_encode($quote));
        }
    }

    public function testSuggestionSpecialCharactersAreEscapedOnWrite(): void
    {
        // The suggestion is written as text, never as markup: a suggestion
        // containing & or < must end up entity-encoded in the stored HTML —
        // a model (or manipulated report) cannot inject tags via apply.
        $row = $this->textElement(['bodytext' => '<p>Drei ist kleiner als vier.</p>']);

        $result = $this->applier->analyze($row, 'kleiner als', '< als 4 & mehr als');

        self::assertSame(SuggestionApplier::APPLIED, $result['status']);
        self::assertSame('<p>Drei ist &lt; als 4 &amp; mehr als vier.</p>', $result['newValue']);
        self::assertStringNotContainsString('< als', $result['newValue']);
    }

    public function testQuoteInHeaderAndBodytextIsAmbiguous(): void
    {
        $row = $this->textElement([
            'header' => 'Ein Fehler passiert',
            'bodytext' => '<p>Hier ist ein Fehler passiert.</p>',
        ]);

        $result = $this->applier->analyze($row, 'Fehler passiert', 'Fehler passierte');

        self::assertSame(SuggestionApplier::AMBIGUOUS, $result['status']);
        self::assertSame('', $result['newValue']);
    }

    public function testEntitiesMatchTheDecodedTextTheModelWasGiven(): void
    {
        $row = $this->textElement(['bodytext' => '<p>Tom &amp; Jerry sind lustig.</p>']);

        $result = $this->applier->analyze($row, 'Tom & Jerry', 'Tom und Jerry');

        self::assertSame(SuggestionApplier::APPLIED, $result['status']);
        self::assertSame('<p>Tom und Jerry sind lustig.</p>', $result['newValue']);
    }

    /**
     * THE corruption regression: non-RTE bodytext ("table") must never be
     * round-tripped through the HTML parser ("a & b" would come back as
     * "a &amp; b"). The status is UNSUPPORTED and no replacement is computed.
     */
    public function testTableBodytextIsUnsupportedAndComputesNoReplacement(): void
    {
        $row = $this->textElement([
            'CType' => 'table',
            'bodytext' => 'Produkt|a & b|Preis',
        ]);

        $result = $this->applier->analyze($row, 'a & b', 'a und b');

        self::assertSame(SuggestionApplier::UNSUPPORTED, $result['status']);
        self::assertSame('', $result['newValue']);
    }

    public function testBulletsBodytextIsUnsupported(): void
    {
        $row = $this->textElement([
            'CType' => 'bullets',
            'bodytext' => 'Punkt eins mit Fehler',
        ]);

        $result = $this->applier->analyze($row, 'Fehler', 'Fehlern');

        self::assertSame(SuggestionApplier::UNSUPPORTED, $result['status']);
        self::assertSame('', $result['newValue']);
    }

    public function testHeaderOfNonRteElementStillApplies(): void
    {
        // Plain-text fields carry no serialization risk — a table element's
        // header stays one-click fixable.
        $row = $this->textElement([
            'CType' => 'table',
            'header' => 'Preisliste 2023',
            'bodytext' => 'Produkt|Preis',
        ]);

        $result = $this->applier->analyze($row, '2023', '2026');

        self::assertSame(SuggestionApplier::APPLIED, $result['status']);
        self::assertSame('header', $result['field']);
        self::assertSame('Preisliste 2026', $result['newValue']);
    }

    public function testHeaderHitPlusGuardedBodytextHitIsAmbiguousNotSilentlyApplied(): void
    {
        $row = $this->textElement([
            'CType' => 'table',
            'header' => 'Der Preis',
            'bodytext' => 'Produkt|Der Preis|10',
        ]);

        $result = $this->applier->analyze($row, 'Der Preis', 'Die Preise');

        self::assertSame(SuggestionApplier::AMBIGUOUS, $result['status']);
        self::assertSame('', $result['newValue']);
    }

    public function testAbsentQuoteIsNotFound(): void
    {
        $row = $this->textElement(['header' => 'Hallo', 'bodytext' => '<p>Welt</p>']);

        $result = $this->applier->analyze($row, 'nicht vorhanden', 'egal');

        self::assertSame(SuggestionApplier::NOT_FOUND, $result['status']);
    }
}
