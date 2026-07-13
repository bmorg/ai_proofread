<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Writes a finding's suggestion back into its content element — the "apply" step
 * of the review-and-fix queue.
 *
 * A finding carries a plain-text `quote` (the wrong text) and a `suggestion`. To
 * apply it we must (1) re-locate that quote in the *current* stored field — the
 * report is a historical snapshot, so the content may have changed — and (2)
 * replace it. Two field kinds:
 *
 *  - `header`/`subheader` are plain text: an exact, unique substring match.
 *  - `bodytext` is RTE HTML: {@see locateInHtml()} splits it into raw **text runs**
 *    (skipping tags/attributes/comments) and matches against each run's
 *    `html_entity_decode`d text — the same decoding `ContentExtractor` fed the
 *    model ({@see ContentExtractor::ENTITY_DECODE_FLAGS}). The match must sit
 *    within ONE run and be unique — a quote that spans
 *    element boundaries (e.g. includes `<strong>…</strong>`) is reported as
 *    SPANS_MARKUP and left to the deep-link, never spliced. The write edits only
 *    the matched run's raw span, so character entities elsewhere in the field
 *    (`&quot;`, `&bdquo;`, `&nbsp;`, …) are preserved byte-for-byte — a
 *    `DOMDocument::saveHTML()` reserialization would decode them all and churn
 *    unrelated content + sys_history. Only CTypes whose bodytext is RTE-configured
 *    (`enableRichtext`) qualify at all: plain-text bodytext (core "table",
 *    "bullets") would be mangled if treated as HTML, so it is reported as
 *    UNSUPPORTED and left to the deep-link.
 *
 * The write goes through DataHandler (run as the current BE user) so tt_content
 * edit permissions, RTE transformation, sys_history/undo and the refindex all
 * apply — this is the extension's only content mutation, and it is explicit,
 * opt-in and reversible. Anything ambiguous fails with a typed status rather than
 * risking a wrong edit.
 */
final class SuggestionApplier
{
    public const APPLIED = 'applied';
    public const NOT_FOUND = 'notFound';
    public const AMBIGUOUS = 'ambiguous';
    public const SPANS_MARKUP = 'spansMarkup';
    public const UNSUPPORTED = 'unsupported';
    public const NO_PERMISSION = 'noPermission';
    public const ERROR = 'error';

    /** Characters of surrounding text shown as context on each side of the quote. */
    private const CONTEXT_CHARS = 60;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Render-time pre-check: can this finding's quote be uniquely and safely
     * located in its element right now? Drives whether the UI offers "apply" and
     * provides the surrounding context. Does not modify anything.
     */
    public function locate(int $pageUid, int $elementUid, string $quote): LocateResult
    {
        $row = $this->loadElement($pageUid, $elementUid);
        if ($row === null || $quote === '') {
            return new LocateResult(false, self::NOT_FOUND, '', '', '');
        }
        $analysis = $this->analyze($row, $quote);

        return new LocateResult(
            $analysis['status'] === self::APPLIED,
            $analysis['status'] === self::APPLIED ? '' : $analysis['status'],
            (string)$analysis['field'],
            (string)$analysis['before'],
            (string)$analysis['after'],
        );
    }

    /**
     * Apply the suggestion: locate the quote, then write the corrected field via
     * DataHandler as the current BE user ($GLOBALS['BE_USER'] — whose permissions
     * are checked and who is recorded in sys_history). Returns a status constant;
     * only APPLIED means the content changed.
     */
    public function apply(int $pageUid, int $elementUid, string $quote, string $suggestion): string
    {
        $row = $this->loadElement($pageUid, $elementUid);
        if ($row === null || $quote === '') {
            return self::NOT_FOUND;
        }

        $analysis = $this->analyze($row, $quote, $suggestion);
        if ($analysis['status'] !== self::APPLIED) {
            return $analysis['status'];
        }

        // Detect the common permission denials up front so they return a precise
        // NO_PERMISSION (not the generic ERROR): the table-level modify right, and
        // content-edit access on the element's page. Rarer record-level guards
        // (editlock, language access) still surface via DataHandler::errorLog below.
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser === null || !$backendUser->check('tables_modify', 'tt_content')) {
            return self::NO_PERMISSION;
        }
        $pageRow = BackendUtility::getRecord('pages', (int)$row['pid']);
        if (!\is_array($pageRow) || !$backendUser->doesUserHaveAccess($pageRow, Permission::CONTENT_EDIT)) {
            return self::NO_PERMISSION;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        // Third arg defaults to $GLOBALS['BE_USER']; DataHandler enforces this
        // user's page/record edit permissions and applies RTE transformation.
        $dataHandler->start(
            ['tt_content' => [$elementUid => [(string)$analysis['field'] => (string)$analysis['newValue']]]],
            []
        );
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog !== []) {
            // A permission denial surfaces here too; we can't reliably distinguish
            // it from other DataHandler errors, so the page-edit case maps to ERROR
            // (the tables_modify guard above already catches the table-level denial).
            return self::ERROR;
        }

        return self::APPLIED;
    }

    /**
     * Locate the quote across the element's fields and compute the replacement.
     * The match must be unique across all fields and (for bodytext) confined to a
     * single text node.
     *
     * @internal public only so the matching truth table (incl. the non-RTE
     *   corruption guard) is unit-testable without a database; use locate()/apply().
     *
     * @param array<string, mixed> $row
     * @return array{status: string, field: string, newValue: string, before: string, after: string}
     */
    public function analyze(array $row, string $quote, string $suggestion = ''): array
    {
        $miss = ['status' => self::NOT_FOUND, 'field' => '', 'newValue' => '', 'before' => '', 'after' => ''];

        $candidates = [];
        $spanOnlyInBody = false;
        $unsupportedBodyHit = false;

        foreach (['header', 'subheader'] as $field) {
            $value = (string)($row[$field] ?? '');
            $count = $value !== '' ? $this->countOccurrences($value, $quote) : 0;
            if ($count === 1) {
                $candidates[] = [
                    'field' => $field,
                    'newValue' => $this->replaceOnce($value, $quote, $suggestion),
                    'before' => $this->contextBefore($value, $quote),
                    'after' => $this->contextAfter($value, $quote),
                ];
            } elseif ($count > 1) {
                return ['status' => self::AMBIGUOUS, 'field' => $field, 'newValue' => '', 'before' => '', 'after' => ''];
            }
        }

        $body = (string)($row['bodytext'] ?? '');
        if ($body !== '') {
            if (!$this->bodytextIsRichtext((string)($row['CType'] ?? ''))) {
                // Non-RTE bodytext (core: "table", "bullets"): locateInHtml() would
                // parse the plain text as HTML and write back the re-serialized
                // document, corrupting the field ("a & b" → "a &amp; b"). Never
                // auto-apply here; record a hit so the outcome stays honest.
                $unsupportedBodyHit = str_contains($body, $quote);
            } else {
                $bodyMatch = $this->locateInHtml($body, $quote, $suggestion);
                if ($bodyMatch['count'] === 1) {
                    $candidates[] = [
                        'field' => 'bodytext',
                        'newValue' => (string)$bodyMatch['newValue'],
                        'before' => (string)$bodyMatch['before'],
                        'after' => (string)$bodyMatch['after'],
                    ];
                } elseif ($bodyMatch['count'] > 1) {
                    return ['status' => self::AMBIGUOUS, 'field' => 'bodytext', 'newValue' => '', 'before' => '', 'after' => ''];
                } else {
                    $spanOnlyInBody = $bodyMatch['spanContains'];
                }
            }
        }

        // A bodytext hit that can't be applied still counts toward ambiguity —
        // whether in a guarded non-RTE field or spanning markup in an RTE field:
        // a quote that also occurs there can't be safely attributed to the
        // cleanly matched field.
        if (\count($candidates) + ($unsupportedBodyHit ? 1 : 0) + ($spanOnlyInBody ? 1 : 0) > 1) {
            // Same quote present in more than one field — too risky to guess which.
            return ['status' => self::AMBIGUOUS, 'field' => '', 'newValue' => '', 'before' => '', 'after' => ''];
        }
        if (\count($candidates) === 1) {
            return ['status' => self::APPLIED] + $candidates[0];
        }
        if ($unsupportedBodyHit) {
            return ['status' => self::UNSUPPORTED] + $miss;
        }

        // No unique single-node match anywhere. If the quote only appears across
        // markup boundaries in bodytext, say so (deep-link fallback); else missing.
        return ['status' => $spanOnlyInBody ? self::SPANS_MARKUP : self::NOT_FOUND] + $miss;
    }

    /**
     * Locate the quote inside RTE HTML, confined to a single text run, and — when
     * uniquely applyable — splice the suggestion straight into the raw HTML.
     *
     * **One parser.** The field is split into its raw **text runs** (`textRuns()`
     * skips tags, attributes and comments) and each run is decoded with the
     * *same* flags `ContentExtractor` used to build the text the model quoted
     * ({@see ContentExtractor::ENTITY_DECODE_FLAGS}), so the match is
     * apples-to-apples. The
     * quote must occur exactly once across all runs AND not span markup (a match in
     * the separator-less concatenation but not in any single run → SPANS_MARKUP).
     * The write edits only the matched run's raw span, so every other byte — the
     * character entities the rest of the field uses (`&quot;`, `&bdquo;`, `&nbsp;`,
     * …) — is preserved verbatim; a `DOMDocument::saveHTML()` round-trip would
     * decode them all and churn unrelated content + sys_history.
     *
     * @return array{count: int, spanContains: bool, newValue: ?string, before: string, after: string}
     */
    private function locateInHtml(string $html, string $quote, string $suggestion): array
    {
        $totalCount = 0;
        $fullText = '';
        $hit = null;
        $hitDecoded = '';
        foreach ($this->textRuns($html) as $run) {
            $decoded = html_entity_decode($run['text'], ContentExtractor::ENTITY_DECODE_FLAGS, ContentExtractor::ENTITY_CHARSET);
            $fullText .= $decoded;
            $occurrences = $this->countOccurrences($decoded, $quote);
            $totalCount += $occurrences;
            if ($occurrences === 1) {
                $hit = $run;
                $hitDecoded = $decoded;
            }
        }

        // Occurrences in the concatenated run text — beyond the run-local ones this
        // also finds occurrences spanning markup boundaries. More of these than
        // run-local ones means the quote also exists spanning markup: applying to
        // the run-local hit could then edit the wrong instance. (The separator-less
        // concatenation can also fabricate matches across block boundaries; refusing
        // those too errs on the safe side — the finding falls back to the deep-link.)
        $fullTextCount = $this->countOccurrences($fullText, $quote);

        if ($totalCount === 1 && $fullTextCount <= 1 && $hit !== null) {
            $decodedPos = (int)strpos($hitDecoded, $quote);
            $rawStart = $this->rawOffsetForDecodedLength($hit['text'], $decodedPos);
            $rawEnd = $this->rawOffsetForDecodedLength($hit['text'], $decodedPos + \strlen($quote));

            // The suggestion is inserted as text, never markup: encode &, <, > so a
            // model (or tampered report) can't inject tags. Quotes stay literal —
            // valid in text content and a minimal, unsurprising edit.
            $newRun = substr($hit['text'], 0, $rawStart)
                . htmlspecialchars($suggestion, ENT_NOQUOTES, 'UTF-8')
                . substr($hit['text'], $rawEnd);
            $newValue = substr($html, 0, $hit['offset'])
                . $newRun
                . substr($html, $hit['offset'] + \strlen($hit['text']));

            return [
                'count' => 1,
                'spanContains' => false,
                'newValue' => $newValue,
                'before' => $this->contextBefore($hitDecoded, $quote),
                'after' => $this->contextAfter($hitDecoded, $quote),
            ];
        }

        if ($totalCount > 1 || $fullTextCount > 1) {
            return ['count' => max($totalCount, $fullTextCount), 'spanContains' => false, 'newValue' => null, 'before' => '', 'after' => ''];
        }

        // Not within any single text run: does it appear only across boundaries?
        return ['count' => 0, 'spanContains' => $fullTextCount > 0, 'newValue' => null, 'before' => '', 'after' => ''];
    }

    /**
     * Count occurrences of $needle in $haystack **including overlapping ones**
     * (`substr_count` counts only non-overlapping, so it undercounts a quote that
     * overlaps itself — e.g. "e Straße" in "…Straße Straße…"). We must catch those
     * as duplicates so an overlapping quote is reported AMBIGUOUS, never applied to
     * an arbitrary occurrence. Used for every uniqueness check in the matcher.
     */
    private function countOccurrences(string $haystack, string $needle): int
    {
        if ($needle === '') {
            return 0;
        }
        $count = 0;
        $offset = 0;
        while (($pos = strpos($haystack, $needle, $offset)) !== false) {
            $count++;
            $offset = $pos + 1; // +1 (not +strlen) so overlapping matches are counted
        }

        return $count;
    }

    /**
     * Split raw HTML into its text runs — the spans between tags — each with its
     * byte offset in the source. Markup (tags, comments, declarations/PIs) is
     * skipped in full, quoted attribute values included, so a splice can never land
     * inside a tag or an attribute value.
     *
     * @return list<array{offset: int, text: string}>
     */
    private function textRuns(string $html): array
    {
        $runs = [];
        $i = 0;
        $n = \strlen($html);
        $runStart = 0;
        while ($i < $n) {
            if ($html[$i] === '<') {
                if ($i > $runStart) {
                    $runs[] = ['offset' => $runStart, 'text' => substr($html, $runStart, $i - $runStart)];
                }
                $i = $this->markupEnd($html, $i);
                $runStart = $i;
                continue;
            }
            $i++;
        }
        if ($n > $runStart) {
            $runs[] = ['offset' => $runStart, 'text' => substr($html, $runStart)];
        }

        return $runs;
    }

    /**
     * The byte offset just past the markup construct starting at $start (a '<'):
     * a comment (`<!-- … -->`), a declaration or PI (`<! … >` / `<? … >`), or a
     * regular tag whose `>` is found while respecting quoted attribute values.
     */
    private function markupEnd(string $html, int $start): int
    {
        $n = \strlen($html);
        if (substr($html, $start, 4) === '<!--') {
            $close = strpos($html, '-->', $start + 4);
            return $close === false ? $n : $close + 3;
        }
        if ($start + 1 < $n && ($html[$start + 1] === '!' || $html[$start + 1] === '?')) {
            $close = strpos($html, '>', $start + 2);
            return $close === false ? $n : $close + 1;
        }
        $i = $start + 1;
        $quote = '';
        while ($i < $n) {
            $ch = $html[$i];
            if ($quote !== '') {
                if ($ch === $quote) {
                    $quote = '';
                }
            } elseif ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === '>') {
                return $i + 1;
            }
            $i++;
        }

        return $n;
    }

    /**
     * The byte offset in a raw text run at which the decoded content first reaches
     * $targetBytes bytes, stepping over character entities (which are longer raw
     * than decoded). Translates a decoded match position into a raw offset.
     *
     * This MUST decode exactly the same tokens as the whole-run `html_entity_decode`
     * that produced the match position (same {@see ContentExtractor::ENTITY_DECODE_FLAGS}),
     * or the two disagree and the offset is wrong. So we recognise an entity only by
     * its well-formed shape (`&name;` / `&#123;` / `&#xAB;`) — the same set PHP
     * decodes — and confirm with `html_entity_decode`;
     * there is deliberately no length cap (a rare long entity such as
     * `&CounterClockwiseContourIntegral;` must still map). A bare `&` that is not a
     * well-formed entity token is treated as one literal byte, exactly as the
     * whole-run decode leaves it.
     *
     * Assumes an entity's decoded output is not split by a quote boundary — true for
     * the single-codepoint entities RTE emits. A multi-codepoint entity (e.g.
     * `&fjlig;` → "fj") with the quote starting between its codepoints would snap to
     * the entity edge; not reachable with real RTE content.
     */
    private function rawOffsetForDecodedLength(string $raw, int $targetBytes): int
    {
        if ($targetBytes <= 0) {
            return 0;
        }
        $ri = 0;
        $decoded = 0;
        $n = \strlen($raw);
        while ($ri < $n && $decoded < $targetBytes) {
            if (
                $raw[$ri] === '&'
                && preg_match('/&(?:#[xX][0-9a-fA-F]+|#[0-9]+|[a-zA-Z][a-zA-Z0-9]*);/A', $raw, $m, 0, $ri)
            ) {
                $piece = html_entity_decode($m[0], ContentExtractor::ENTITY_DECODE_FLAGS, ContentExtractor::ENTITY_CHARSET);
                if ($piece !== $m[0]) {
                    $decoded += \strlen($piece);
                    $ri += \strlen($m[0]);
                    continue;
                }
            }
            $decoded++;
            $ri++;
        }

        return $ri;
    }

    /**
     * Whether this CType's bodytext is an RTE (richtext) field. Only then is the
     * splice write in locateInHtml() attempted: plain-text bodytext (core: "table",
     * "bullets") must never be treated as HTML.
     */
    private function bodytextIsRichtext(string $cType): bool
    {
        $tca = $GLOBALS['TCA']['tt_content'] ?? [];

        return (bool)($tca['types'][$cType]['columnsOverrides']['bodytext']['config']['enableRichtext']
            ?? $tca['columns']['bodytext']['config']['enableRichtext']
            ?? false);
    }

    /**
     * Replace the first occurrence of $search with $replace. (Callers guarantee a
     * single occurrence, so this is effectively a unique replace.)
     */
    private function replaceOnce(string $haystack, string $search, string $replace): string
    {
        $pos = strpos($haystack, $search);
        if ($pos === false) {
            return $haystack;
        }
        return substr($haystack, 0, $pos) . $replace . substr($haystack, $pos + \strlen($search));
    }

    private function contextBefore(string $value, string $quote): string
    {
        $pos = strpos($value, $quote);
        if ($pos === false || $pos === 0) {
            return '';
        }
        // Multibyte-aware: measure/cut in characters, not bytes, so a German
        // umlaut on the boundary can't be split into a broken glyph.
        $before = substr($value, 0, $pos);
        if (mb_strlen($before, 'UTF-8') <= self::CONTEXT_CHARS) {
            return $before;
        }
        // Trim to a word boundary on the left, prefixed with an ellipsis.
        $slice = mb_substr($before, -self::CONTEXT_CHARS, null, 'UTF-8');
        $space = strpos($slice, ' ');
        return '…' . ($space !== false ? substr($slice, $space + 1) : $slice);
    }

    private function contextAfter(string $value, string $quote): string
    {
        $pos = strpos($value, $quote);
        if ($pos === false) {
            return '';
        }
        $after = substr($value, $pos + \strlen($quote));
        if (mb_strlen($after, 'UTF-8') <= self::CONTEXT_CHARS) {
            return $after;
        }
        $slice = mb_substr($after, 0, self::CONTEXT_CHARS, 'UTF-8');
        $space = strrpos($slice, ' ');
        return ($space !== false ? substr($slice, 0, $space) : $slice) . '…';
    }

    /**
     * Load the target content element's editable fields, scoped to its page (a
     * defence so a hand-crafted elementUid can't reach content off the page the
     * module already gated access to). Deleted records excluded; hidden included.
     *
     * @return array<string, mixed>|null
     */
    private function loadElement(int $pageUid, int $elementUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $row = $queryBuilder
            ->select('uid', 'pid', 'CType', 'header', 'subheader', 'bodytext')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($elementUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }
}
