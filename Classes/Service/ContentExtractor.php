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
            $body = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $parts[] = trim($body);
        }

        return implode("\n\n", $parts);
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
