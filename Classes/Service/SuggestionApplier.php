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
 *  - `bodytext` is RTE HTML: we parse it and match against the decoded text of a
 *    single text node (the same decoded text the model was given). The match must
 *    sit within ONE text node and be unique — a quote that spans element
 *    boundaries (e.g. includes `<strong>…</strong>`) is reported as SPANS_MARKUP
 *    and left to the deep-link, never spliced.
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
     * DataHandler as the current BE user. Returns a status constant; only APPLIED
     * means the content changed.
     */
    public function apply(int $pageUid, int $elementUid, string $quote, string $suggestion, int $beUser): string
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
     * @param array<string, mixed> $row
     * @return array{status: string, field: string, newValue: string, before: string, after: string}
     */
    private function analyze(array $row, string $quote, string $suggestion = ''): array
    {
        $miss = ['status' => self::NOT_FOUND, 'field' => '', 'newValue' => '', 'before' => '', 'after' => ''];

        $candidates = [];
        $spanOnlyInBody = false;

        foreach (['header', 'subheader'] as $field) {
            $value = (string)($row[$field] ?? '');
            $count = $value !== '' ? substr_count($value, $quote) : 0;
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

        if (\count($candidates) > 1) {
            // Same quote present in more than one field — too risky to guess which.
            return ['status' => self::AMBIGUOUS, 'field' => '', 'newValue' => '', 'before' => '', 'after' => ''];
        }
        if (\count($candidates) === 1) {
            return ['status' => self::APPLIED] + $candidates[0];
        }

        // No unique single-node match anywhere. If the quote only appears across
        // markup boundaries in bodytext, say so (deep-link fallback); else missing.
        return ['status' => $spanOnlyInBody ? self::SPANS_MARKUP : self::NOT_FOUND] + $miss;
    }

    /**
     * Locate the quote inside RTE HTML, confined to a single text node.
     *
     * @return array{count: int, spanContains: bool, newValue: ?string, before: string, after: string}
     */
    private function locateInHtml(string $html, string $quote, string $suggestion): array
    {
        $none = ['count' => 0, 'spanContains' => false, 'newValue' => null, 'before' => '', 'after' => ''];

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // Wrap in a known root so NOIMPLIED/NODEFDTD give us clean inner HTML with
        // no synthetic <html>/<body>/doctype; the XML hint forces UTF-8 decoding.
        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8"?><div id="aiproofread-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || $dom->documentElement === null) {
            return $none;
        }

        $root = $dom->documentElement;
        $xpath = new \DOMXPath($dom);
        $textNodes = $xpath->query('.//text()', $root);
        if ($textNodes === false) {
            return $none;
        }

        $totalCount = 0;
        $fullText = '';
        $matchNode = null;
        foreach ($textNodes as $node) {
            $value = $node->nodeValue ?? '';
            $fullText .= $value;
            $occurrences = $value !== '' ? substr_count($value, $quote) : 0;
            $totalCount += $occurrences;
            if ($occurrences === 1) {
                $matchNode = $node;
            }
        }

        if ($totalCount === 1 && $matchNode !== null) {
            $value = $matchNode->nodeValue ?? '';
            $before = $this->contextBefore($value, $quote);
            $after = $this->contextAfter($value, $quote);
            $matchNode->nodeValue = $this->replaceOnce($value, $quote, $suggestion);

            $out = '';
            foreach (iterator_to_array($root->childNodes) as $child) {
                $out .= $dom->saveHTML($child);
            }
            return ['count' => 1, 'spanContains' => false, 'newValue' => $out, 'before' => $before, 'after' => $after];
        }

        if ($totalCount > 1) {
            return ['count' => $totalCount, 'spanContains' => false, 'newValue' => null, 'before' => '', 'after' => ''];
        }

        // Not within any single text node: does it appear only across boundaries?
        return ['count' => 0, 'spanContains' => $fullText !== '' && str_contains($fullText, $quote), 'newValue' => null, 'before' => '', 'after' => ''];
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
            ->select('uid', 'pid', 'header', 'subheader', 'bodytext')
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
