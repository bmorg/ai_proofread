<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Pulls human-readable text out of tt_content records so it can be proofread,
 * and locates the content elements that live on a given page.
 *
 * sys_language_uid is respected so German vs. translated content is judged in
 * the right language by the caller.
 */
final class ContentExtractor
{
    /**
     * CTypes whose bodytext is no human-readable prose. The "html" element holds
     * raw markup — proofreading it yields nonsense findings and wastes tokens, so
     * it is excluded from extraction entirely (and thus can never produce a
     * finding the applier would touch).
     */
    private const EXCLUDED_CTYPES = ['html'];

    /**
     * How HTML entities are decoded when reducing markup to the plain text the
     * model proofreads (see {@see htmlToText()}). This flag set + charset is a
     * **contract the applier must mirror byte-for-byte**: {@see SuggestionApplier}
     * decodes its text runs with exactly these so a model quote matches the text
     * it was actually shown. Decode with a different entity set (`ENT_HTML401`)
     * or charset there and matching silently misaligns — always reference these
     * constants, never a second literal.
     */
    public const ENTITY_DECODE_FLAGS = ENT_QUOTES | ENT_HTML5;
    public const ENTITY_CHARSET = 'UTF-8';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Content elements on a page, ordered as in the backend.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getContentElementsForPage(int $pageUid, int $languageUid = 0): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        // Only deleted records are excluded — hidden elements are intentionally
        // included, so editors can proofread unpublished/draft content before it
        // goes live.
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(new DeletedRestriction());

        return $queryBuilder
            ->select('uid', 'pid', 'sys_language_uid', 'CType', 'header', 'subheader', 'bodytext')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->notIn('CType', $queryBuilder->createNamedParameter(self::EXCLUDED_CTYPES, Connection::PARAM_STR_ARRAY))
            )
            ->orderBy('colPos')
            ->addOrderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Reduce a content element to the readable text a proofreader cares about.
     *
     * @param array<string, mixed> $row
     */
    public function extractText(array $row): string
    {
        $parts = [];
        foreach (['header', 'subheader'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        $body = trim((string)($row['bodytext'] ?? ''));
        if ($body !== '') {
            // bodytext is RTE HTML for text CTypes; strip to plain readable text.
            $parts[] = $this->htmlToText($body);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Convert RTE HTML to the plain text the model proofreads. Every block-level
     * boundary becomes a line break *deterministically* — relying on the RTE's
     * stored indentation (inter-tag whitespace) to separate list items, headings
     * or table cells breaks on compact HTML (pasted/imported content, other RTE
     * presets), gluing words together ("Punkt 1Punkt 2") and producing false
     * positives the applier can then never locate.
     *
     * Only whitespace *between* blocks is normalized (indentation around line
     * breaks, CR, blank-line runs) — whitespace inside a text node is preserved
     * byte-for-byte, so a model quote still matches its single DOM text node in
     * SuggestionApplier::locate(). The whitespace-only nature of this cleanup
     * also keeps hash() stable (it collapses all \s+ runs before hashing).
     */
    private function htmlToText(string $html): string
    {
        $text = str_replace("\r", '', $html);
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = preg_replace('#</(p|li|h[1-6]|td|th|tr|div|blockquote|dt|dd|figcaption)>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), self::ENTITY_DECODE_FLAGS, self::ENTITY_CHARSET);
        // Tidy inter-block whitespace: drop indentation around breaks, collapse
        // runs of blank lines to one blank line.
        $text = preg_replace('/[ \t]*\n[ \t]*/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Concatenated readable text of a whole page's content elements, in backend
     * order. This is the unit that gets proofread, so the model sees the full
     * page and can catch issues that span content elements.
     */
    public function extractPageText(int $pageUid, int $languageUid = 0): string
    {
        $parts = [];
        foreach ($this->getContentElementsForPage($pageUid, $languageUid) as $row) {
            $text = $this->extractText($row);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Stable hash of the extracted text, used by the bulk command to skip pages
     * unchanged since their last report run.
     */
    public function hash(string $text): string
    {
        return hash('sha256', preg_replace('/\s+/u', ' ', trim($text)) ?? $text);
    }

    /**
     * Short human label for a content element (for the audit log's element
     * column): its header, else "<CType> #<uid>".
     *
     * @param array<string, mixed> $row
     */
    public function elementLabel(array $row): string
    {
        $header = trim((string)($row['header'] ?? ''));
        if ($header !== '') {
            return $header;
        }
        $cType = trim((string)($row['CType'] ?? '')) ?: 'Element';
        return $cType . ' #' . (int)($row['uid'] ?? 0);
    }
}
