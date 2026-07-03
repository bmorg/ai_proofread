<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Tests\Unit\Service;

use Bmorg\AiProofread\Service\ContentExtractor;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Text extraction: what the model receives. The block-boundary handling is the
 * regression surface — extraction must not depend on how the RTE happened to
 * format the stored HTML (compact HTML used to glue words together).
 */
final class ContentExtractorTest extends UnitTestCase
{
    private ContentExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new ContentExtractor($this->createMock(ConnectionPool::class));
    }

    /**
     * The real-world storage format observed on the production v11 instance:
     * CRLF between blocks, space+tab indentation before list items.
     */
    public function testRteFormattedStorageYieldsCleanLines(): void
    {
        $row = [
            'bodytext' => "<p>Text bew\u{00e4}ltigen.</p>\r\n\r\n<p>Eine Liste:</p>\r\n\r\n"
                . "<ul>\r\n \t<li>Punkt 1</li>\r\n \t<li>Punkt 2</li>\r\n</ul>",
        ];

        $text = $this->extractor->extractText($row);

        self::assertSame("Text bew\u{00e4}ltigen.\n\nEine Liste:\n\nPunkt 1\n\nPunkt 2", $text);
        self::assertStringNotContainsString("\r", $text);
        self::assertStringNotContainsString("\t", $text);
    }

    /**
     * Compact HTML (pasted/imported content, other serializers) has no inter-tag
     * whitespace — items and headings must still be separated, never glued.
     */
    public function testCompactHtmlDoesNotGlueWords(): void
    {
        $row = [
            'bodytext' => '<p>Eine Liste:</p><ul><li>Punkt 1</li><li>Punkt 2</li></ul>'
                . '<h2>Titel</h2><p>Absatz mit &amp; und „Zitat“.</p>',
        ];

        $text = $this->extractor->extractText($row);

        self::assertSame("Eine Liste:\nPunkt 1\nPunkt 2\nTitel\nAbsatz mit & und „Zitat“.", $text);
        self::assertStringNotContainsString('Punkt 1Punkt 2', $text);
    }

    public function testBrAndEntitiesAreDecodedAndNbspPreserved(): void
    {
        $row = ['bodytext' => '<p>Zeile eins<br />Zeile&nbsp;zwei</p>'];

        // The nbsp must stay U+00A0 (not a plain space): the applier matches the
        // model's quote against the decoded DOM text node, which contains U+00A0.
        self::assertSame("Zeile eins\nZeile\u{00a0}zwei", $this->extractor->extractText($row));
    }

    public function testHeaderSubheaderAndBodytextAreJoinedWithBlankLines(): void
    {
        $row = [
            'header' => 'Überschrift',
            'subheader' => 'Unterzeile',
            'bodytext' => '<p>Absatz.</p>',
        ];

        self::assertSame("Überschrift\n\nUnterzeile\n\nAbsatz.", $this->extractor->extractText($row));
    }

    public function testEmptyFieldsYieldEmptyText(): void
    {
        self::assertSame('', $this->extractor->extractText([]));
        self::assertSame('', $this->extractor->extractText(['header' => '  ', 'bodytext' => '']));
    }

    /**
     * hash() collapses whitespace runs, so whitespace-only extraction changes
     * (like the block-boundary normalization) never invalidate stored hashes —
     * the bulk command's skip-unchanged check keeps working.
     */
    public function testHashIsStableAcrossWhitespaceVariants(): void
    {
        self::assertSame(
            $this->extractor->hash("Punkt 1\n\nPunkt 2"),
            $this->extractor->hash("Punkt 1 \t Punkt 2")
        );
        self::assertNotSame(
            $this->extractor->hash('Punkt 1 Punkt 2'),
            $this->extractor->hash('Punkt 1 Punkt 3')
        );
    }
}
