<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Tests\Unit\Service;

use Bmorg\AiProofread\Service\SuggestionApplier;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Fuzz coverage for the bodytext apply path. Content mutation must be airtight:
 * no wrong-position edits, no dropped/duplicated text, no markup corruption, and
 * no churn of character entities elsewhere in the field.
 *
 * The generator builds randomized RTE HTML — random words (incl. astral / emoji:
 * 4-byte, variation-selector, ZWJ and flag sequences), each character randomly
 * entity-encoded (named / decimal / hex / literal), wrapped in random nested
 * inline tags, `<br>`, HTML comments, tricky attributes (`title="a > b"`) and
 * inter-block whitespace — while tracking the EXACT raw byte span of every text
 * node. This exercises the byte-level raw↔decoded offset arithmetic over
 * multi-byte and supplementary-plane characters. Two independent oracles judge
 * each applied edit:
 *
 *  1. **Byte-exact** — the expected result, computed by splicing the KNOWN raw
 *     span, must equal `newValue` byte-for-byte. Proves no churn anywhere.
 *  2. **Decoded-text** — the DOM-decoded text of `newValue` must equal the
 *     original decoded text with the one quote occurrence replaced. Proves the
 *     right text changed at the right place (and would also hold on the churn
 *     fallback, so it independently checks correctness there too).
 *
 * Seeds are fixed for determinism (a reproducible corpus, not a flaky test);
 * bump SEED/ITERATIONS to widen the search. This suite was additionally run at
 * ~400k cases across 10 seeds offline with zero failures.
 */
final class SuggestionApplierFuzzTest extends UnitTestCase
{
    private const SEED = 20260713;
    private const ITERATIONS = 3000;

    /**
     * @var list<string> Words; several carry entity-encodable or structural
     * characters. The trailing group carries **astral / multi-codepoint** text —
     * 4-byte emoji (`😀`), a variation-selector cluster (`❤️` = U+2764 U+FE0F),
     * a ZWJ sequence (`👨‍👩‍👧`) and a regional-indicator flag (`🇩🇪`) — so the
     * byte-level raw↔decoded offset mapping is fuzzed over supplementary-plane
     * characters, both literal and as `&#…;`/`&#x…;` numeric entities.
     */
    private const WORDS = [
        'Grün', 'Facharzt', 'erstattungsfähig', 'Straße', 'größer', 'schön', 'Test',
        'Wert', 'Preis', 'Nutzer', '„Zitat“', 'Fee & Co', 'über', 'Maß', 'weiß',
        'Psychotherapeut', 'heute', 'wichtig', 'zentral', 'Kosten', 'und', 'der', 'die',
        '3<5', 'a>b', 'C&D',
        '😀', 'Party 🎉', 'Danke 🙏', 'Herz ❤️', 'Familie 👨‍👩‍👧', 'Land 🇩🇪',
    ];

    private SuggestionApplier $applier;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TCA']['tt_content'] = [
            'columns' => ['bodytext' => ['config' => ['type' => 'text']]],
            'types' => ['text' => ['columnsOverrides' => ['bodytext' => ['config' => ['enableRichtext' => true]]]]],
        ];
        $this->applier = new SuggestionApplier($this->createMock(ConnectionPool::class));
    }

    public function testUniqueQuoteSplicesExactlyAndPreservesEverythingElse(): void
    {
        mt_srand(self::SEED);
        $checked = 0;

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            [$raw, $nodes] = $this->generateDoc();
            $full = $this->domText($raw);
            if ($full === null) {
                continue;
            }
            $pick = $this->pickUniqueQuote($raw, $nodes, $full);
            if ($pick === null) {
                continue;
            }
            [$quote, $rawStart, $rawEnd] = $pick;
            $suggestion = $this->randomSuggestion($quote);

            $expected = substr($raw, 0, $rawStart)
                . htmlspecialchars($suggestion, ENT_NOQUOTES, 'UTF-8')
                . substr($raw, $rawEnd);

            $ctx = sprintf("seed=%d i=%d\n  raw=%s\n  quote=%s\n  sugg=%s", self::SEED, $i, $raw, $quote, $suggestion);

            // Generator self-check: the raw span really decodes to the quote.
            self::assertSame(
                $quote,
                html_entity_decode(substr($raw, $rawStart, $rawEnd - $rawStart), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                "generator span sanity\n" . $ctx
            );

            $result = $this->applier->analyze($this->row(['bodytext' => $raw]), $quote, $suggestion);

            self::assertSame(SuggestionApplier::APPLIED, $result['status'], $ctx);
            self::assertSame('bodytext', $result['field'], $ctx);

            // Oracle 1: byte-exact — no churn anywhere.
            self::assertSame($expected, $result['newValue'], "byte-exact\n" . $ctx);

            // Oracle 2: decoded-text — right change at the right place.
            $pos = strpos($full, $quote);
            $expectedDecoded = substr($full, 0, (int)$pos) . $suggestion . substr($full, (int)$pos + \strlen($quote));
            self::assertSame($expectedDecoded, $this->domText((string)$result['newValue']), "decoded-text\n" . $ctx);

            $checked++;
        }

        self::assertGreaterThan(1500, $checked, 'too few applicable fuzz cases were generated');
    }

    public function testNonUniqueQuoteIsNeverApplied(): void
    {
        // Safety net: whenever a quote is NOT uniquely locatable, the matcher must
        // refuse — status != APPLIED and an EMPTY newValue (no partial/wrong edit).
        mt_srand(self::SEED + 1);
        $checked = 0;

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            [$raw, $nodes] = $this->generateDoc();
            $full = $this->domText($raw);
            if ($full === null) {
                continue;
            }
            $quote = $this->pickDuplicatedToken($full);
            if ($quote === null) {
                continue;
            }

            $ctx = sprintf("seed=%d i=%d\n  raw=%s\n  quote=%s", self::SEED + 1, $i, $raw, $quote);
            $result = $this->applier->analyze($this->row(['bodytext' => $raw]), $quote, 'ERSATZ');

            self::assertNotSame(SuggestionApplier::APPLIED, $result['status'], $ctx);
            self::assertSame('', $result['newValue'], $ctx);
            $checked++;
        }

        self::assertGreaterThan(500, $checked, 'too few duplicated-quote fuzz cases were generated');
    }

    public function testQuoteAlsoPresentInHeaderIsAmbiguous(): void
    {
        // A quote that is unique in bodytext but ALSO appears in another field can
        // not be attributed — the matcher must refuse rather than guess the field.
        mt_srand(self::SEED + 2);
        $checked = 0;

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            [$raw, $nodes] = $this->generateDoc();
            $full = $this->domText($raw);
            if ($full === null) {
                continue;
            }
            $pick = $this->pickUniqueQuote($raw, $nodes, $full);
            if ($pick === null) {
                continue;
            }
            [$quote] = $pick;
            $field = ($i % 2 === 0) ? 'header' : 'subheader';

            $ctx = sprintf("seed=%d i=%d field=%s\n  raw=%s\n  quote=%s", self::SEED + 2, $i, $field, $raw, $quote);
            $result = $this->applier->analyze(
                $this->row(['bodytext' => $raw, $field => 'Kontext ' . $quote . ' Ende']),
                $quote,
                'ERSATZ'
            );

            self::assertSame(SuggestionApplier::AMBIGUOUS, $result['status'], $ctx);
            self::assertSame('', $result['newValue'], $ctx);
            $checked++;
        }

        self::assertGreaterThan(1500, $checked, 'too few cross-field fuzz cases were generated');
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides): array
    {
        return $overrides + ['uid' => 10, 'pid' => 1, 'CType' => 'text', 'header' => '', 'subheader' => '', 'bodytext' => ''];
    }

    private function randomSuggestion(string $quote): string
    {
        $pool = [
            $this->randomPhrase(1, 3),
            'A & B',
            '< tag >',
            '„neu“',
            'R&D & Co',
            $quote,   // idempotent
            '',       // deletion
            'end&',
        ];

        return $pool[mt_rand(0, \count($pool) - 1)];
    }

    /**
     * @return array{0: string, 1: list<array{bnd: array<int,int>}>}
     */
    private function generateDoc(): array
    {
        $raw = '';
        $nodes = [];
        $inline = [
            ['<strong>', '</strong>'], ['<em>', '</em>'], ['<u>', '</u>'],
            ['<a href="#">', '</a>'], ['<a title="a > b">', '</a>'],
        ];
        $addNode = function (string $text) use (&$raw, &$nodes): void {
            [$nodeRaw, $bnd] = $this->renderNode($text);
            if ($nodeRaw === '') {
                return;
            }
            $base = \strlen($raw);
            $abs = [];
            foreach ($bnd as $d => $r) {
                $abs[$d] = $base + $r;
            }
            $raw .= $nodeRaw;
            $nodes[] = ['bnd' => $abs];
        };

        $blocks = mt_rand(1, 3);
        for ($b = 0; $b < $blocks; $b++) {
            if ($b > 0) {
                $raw .= $this->whitespace();
            }
            $raw .= '<p>';
            $pieces = mt_rand(1, 4);
            for ($p = 0; $p < $pieces; $p++) {
                if ($p > 0 && mt_rand(0, 2) === 0) {
                    $addNode($this->whitespace());
                }
                switch (mt_rand(0, 5)) {
                    case 0: // nested inline
                        $t1 = $inline[mt_rand(0, \count($inline) - 1)];
                        $t2 = $inline[mt_rand(0, \count($inline) - 1)];
                        $raw .= $t1[0] . $t2[0];
                        $addNode($this->randomPhrase(1, 3));
                        $raw .= $t2[1] . $t1[1];
                        break;
                    case 1: // comment then text
                        $raw .= '<!-- ' . str_replace(['--', '>'], '', $this->randomPhrase(1, 2)) . ' -->';
                        $addNode($this->randomPhrase(1, 3));
                        break;
                    case 2: // single inline
                        $t = $inline[mt_rand(0, \count($inline) - 1)];
                        $raw .= $t[0];
                        $addNode($this->randomPhrase(1, 3));
                        $raw .= $t[1];
                        break;
                    case 3: // <br/> then text
                        $raw .= '<br/>';
                        $addNode($this->randomPhrase(1, 3));
                        break;
                    default:
                        $addNode($this->randomPhrase(1, 4));
                }
            }
            $raw .= '</p>';
        }

        return [$raw, $nodes];
    }

    /**
     * Render a decoded string to raw HTML text, randomly entity-encoding each
     * character (named / decimal / hex / literal). Structural `<`, `>`, `&` are
     * always encoded. Returns [raw, boundaries] with boundaries[decodedLen]=rawLen.
     *
     * @return array{0: string, 1: array<int,int>}
     */
    private function renderNode(string $decoded): array
    {
        $named = [
            '"' => '&quot;', "\u{201E}" => '&bdquo;', "\u{201C}" => '&ldquo;',
            "\u{00FC}" => '&uuml;', "\u{00E4}" => '&auml;', "\u{00F6}" => '&ouml;',
            "\u{00DF}" => '&szlig;', "\u{00A0}" => '&nbsp;', "\u{2014}" => '&mdash;',
            "\u{20AC}" => '&euro;', "\u{2026}" => '&hellip;',
        ];
        $raw = '';
        $bnd = [0 => 0];
        $len = 0;
        foreach (preg_split('//u', $decoded, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $raw .= $this->encodeChar($ch, $named);
            $len += \strlen($ch);
            $bnd[$len] = \strlen($raw);
        }

        return [$raw, $bnd];
    }

    /**
     * @param array<string, string> $named
     */
    private function encodeChar(string $ch, array $named): string
    {
        if ($ch === '&') {
            return '&amp;';
        }
        if ($ch === '<') {
            return '&lt;';
        }
        if ($ch === '>') {
            return mt_rand(0, 1) ? '&gt;' : '>';
        }
        $opts = [$ch];
        if (isset($named[$ch])) {
            $opts[] = $named[$ch];
        }
        $cp = mb_ord($ch, 'UTF-8');
        if ($cp !== false && $cp > 127) {
            $opts[] = '&#' . $cp . ';';
            $opts[] = '&#x' . dechex($cp) . ';';
            $opts[] = '&#X' . strtoupper(dechex($cp)) . ';';
        }

        return $opts[mt_rand(0, \count($opts) - 1)];
    }

    private function whitespace(): string
    {
        return ['', ' ', "\n", "\t", '  ', "\n  "][mt_rand(0, 5)];
    }

    private function randomPhrase(int $min, int $max): string
    {
        $out = [];
        $n = mt_rand($min, $max);
        for ($i = 0; $i < $n; $i++) {
            $out[] = self::WORDS[mt_rand(0, \count(self::WORDS) - 1)];
        }

        return implode(' ', $out);
    }

    /**
     * Pick a uniquely-locatable, char-aligned quote from one node. The quote is
     * the DECODE of a known raw span, so it is always aligned to real text.
     *
     * @param list<array{bnd: array<int,int>}> $nodes
     * @return array{0: string, 1: int, 2: int}|null [quote, rawStart, rawEnd]
     */
    private function pickUniqueQuote(string $raw, array $nodes, string $full): ?array
    {
        $order = range(0, \count($nodes) - 1);
        shuffle($order);
        foreach ($order as $ni) {
            $offs = array_keys($nodes[$ni]['bnd']);
            sort($offs);
            if (\count($offs) < 2) {
                continue;
            }
            for ($try = 0; $try < 10; $try++) {
                $a = mt_rand(0, \count($offs) - 2);
                $b = mt_rand($a + 1, \count($offs) - 1);
                $rawStart = $nodes[$ni]['bnd'][$offs[$a]];
                $rawEnd = $nodes[$ni]['bnd'][$offs[$b]];
                $quote = html_entity_decode(substr($raw, $rawStart, $rawEnd - $rawStart), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (\strlen($quote) < 1 || trim($quote) === '') {
                    continue;
                }
                if ($this->countOccurrences($full, $quote) !== 1) {
                    continue;
                }

                return [$quote, $rawStart, $rawEnd];
            }
        }

        return null;
    }

    private function pickDuplicatedToken(string $full): ?string
    {
        $tokens = preg_split('/\s+/', $full, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        shuffle($tokens);
        foreach ($tokens as $token) {
            if (\strlen($token) >= 2 && $this->countOccurrences($full, $token) >= 2) {
                return $token;
            }
        }

        return null;
    }

    /** The DOM-decoded concatenated text (what the model / gate sees). */
    private function domText(string $html): ?string
    {
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadHTML(
            '<?xml encoding="utf-8"?><div id="r">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || $dom->documentElement === null) {
            return null;
        }
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('.//text()', $dom->documentElement);
        $text = '';
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                $text .= $node->nodeValue ?? '';
            }
        }

        return $text;
    }

    private function countOccurrences(string $haystack, string $needle): int
    {
        if ($needle === '') {
            return 0;
        }
        $count = 0;
        $offset = 0;
        while (($pos = strpos($haystack, $needle, $offset)) !== false) {
            $count++;
            $offset = $pos + 1;
        }

        return $count;
    }
}
