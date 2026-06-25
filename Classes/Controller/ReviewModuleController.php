<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Controller;

use Bmorg\AiProofread\Enum\Category;
use Bmorg\AiProofread\Enum\ReviewStatus;
use Bmorg\AiProofread\Service\LogRepository;
use Bmorg\AiProofread\Service\ProofreadingService;
use Bmorg\AiProofread\Service\QueueRepository;
use Bmorg\AiProofread\Service\ReportRepository;
use Bmorg\AiProofread\Service\ReviewRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * Backend module "KI-Lektorat". One module with a docheader view switcher:
 *  - "Aktueller Report": status + the latest report for the page (open unless
 *    signed off).
 *  - "Report-Verlauf": the history of report runs for the page.
 *  - "API-Verlauf": the technical audit log of every LLM call.
 *
 * The default landing depends on status: a signed-off ("geprüft") page opens on
 * Report-Verlauf, otherwise on Aktueller Report. Run / mark happen via tokenized
 * GET actions that redirect (PRG) — no custom JS. The inner view is rendered with
 * StandaloneView; wrapping it in the module chrome is version-guarded (see
 * {@see moduleResponse()}): v11/12 use setContent()/renderContent(), v13 (where
 * those were removed) uses assign('content', …) + renderResponse().
 *
 * Access: the API-Verlauf (global audit log) is admin-only; every other view and
 * the run/mark actions are gated by read permission on the requested page.
 */
final class ReviewModuleController
{
    private const VIEW_CURRENT = 'current';
    private const VIEW_REPORTS = 'reports';
    private const VIEW_HISTORY = 'history';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly IconFactory $iconFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly ProofreadingService $proofreadingService,
        private readonly ReviewRepository $reviews,
        private readonly ReportRepository $reports,
        private readonly QueueRepository $queue,
        private readonly LogRepository $log,
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $pageUid = (int)($params['id'] ?? 0);
        $beUser = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        $isAdmin = (bool)$GLOBALS['BE_USER']->isAdmin();

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle('KI-Lektorat');

        // Page-level access gate: module access is "user", so without this any
        // editor could set ?id=<any page> to read content outside their mounts,
        // enqueue paid runs, or sign a page off. Refuse before any read/run/mark.
        // (Admins pass; getPagePermsClause() returns "1=1" for them.)
        if ($pageUid > 0) {
            $pageRecord = BackendUtility::readPageAccess(
                $pageUid,
                $GLOBALS['BE_USER']->getPagePermsClause(Permission::PAGE_SHOW)
            );
            if ($pageRecord === false) {
                return $this->moduleResponse(
                    $moduleTemplate,
                    $this->renderView('Review/Current.html', ['noAccess' => true])
                );
            }
        }

        // Mutating actions (run/mark): do, then redirect (PRG) to the clean URL.
        $error = '';
        $action = (string)($params['action'] ?? '');
        if ($pageUid > 0 && \in_array($action, ['run', 'mark'], true)) {
            try {
                if ($action === 'run') {
                    // A run is N+1 LLM requests — too slow to do inline; enqueue and
                    // let the Scheduler command process it. enqueue() dedupes.
                    $this->queue->enqueue($pageUid, 0, $beUser);
                    // Land on Aktueller Report so the "wird erstellt" note is visible
                    // (even from the Report-Verlauf, where the page is geprüft).
                    $redirectParams = ['id' => $pageUid, 'view' => self::VIEW_CURRENT];
                } else {
                    $this->proofreadingService->markPageProofed($pageUid, $beUser);
                    $redirectParams = ['id' => $pageUid];
                }
                return new RedirectResponse(
                    (string)$this->uriBuilder->buildUriFromRoute('web_aiproofread', $redirectParams)
                );
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $latestReport = $pageUid > 0 ? $this->reports->findLatestByPage($pageUid) : null;
        $review = $pageUid > 0 ? $this->reviews->findByPage($pageUid) : null;
        $status = $this->reviews->deriveStatus($latestReport, $review);

        $job = $pageUid > 0 ? $this->queue->findForPage($pageUid) : null;
        $jobPending = $job !== null && \in_array((string)$job['status'], [QueueRepository::PENDING, QueueRepository::RUNNING], true);
        $jobError = ($job !== null && (string)$job['status'] === QueueRepository::ERROR) ? (string)($job['error_message'] ?? '') : '';

        // Effective view: explicit ?view= wins; otherwise a generating report opens
        // on Aktueller Report (to show the "wird erstellt" note), else default by
        // status (signed off → Report-Verlauf, else Aktueller Report). An action
        // error forces the current view so the message is shown.
        $explicitView = (string)($params['view'] ?? '');
        if ($explicitView !== '') {
            $view = $explicitView;
        } elseif ($jobPending) {
            $view = self::VIEW_CURRENT;
        } elseif ($status === ReviewStatus::Proofed) {
            $view = self::VIEW_REPORTS;
        } else {
            $view = self::VIEW_CURRENT;
        }
        if ($error !== '') {
            $view = self::VIEW_CURRENT;
        }
        // The API-Verlauf exposes the global audit log (full page content + cost of
        // every call, system-wide) — admins only. A non-admin requesting it (stale
        // link/hand-crafted URL) falls back to the current view.
        if ($view === self::VIEW_HISTORY && !$isAdmin) {
            $view = self::VIEW_CURRENT;
        }

        $this->addViewMenu($moduleTemplate, $pageUid, $view, $isAdmin);

        return match ($view) {
            self::VIEW_HISTORY => $this->historyResponse($request, $moduleTemplate, $pageUid, $isAdmin),
            self::VIEW_REPORTS => $this->reportsResponse($moduleTemplate, $pageUid, $review, $status, $jobPending, $jobError),
            default => $this->currentResponse($request, $moduleTemplate, $pageUid, $latestReport, $review, $status, $error, $jobPending, $jobError),
        };
    }

    // -- view switcher -------------------------------------------------------

    private function addViewMenu(ModuleTemplate $moduleTemplate, int $pageUid, string $view, bool $isAdmin): void
    {
        $menuRegistry = $moduleTemplate->getDocHeaderComponent()->getMenuRegistry();
        $menu = $menuRegistry->makeMenu();
        $menu->setIdentifier('aiproofread_view');

        $items = [
            self::VIEW_CURRENT => 'Aktueller Report',
            self::VIEW_REPORTS => 'Report-Verlauf',
        ];
        // API-Verlauf is admin-only (see handleRequest).
        if ($isAdmin) {
            $items[self::VIEW_HISTORY] = 'API-Verlauf';
        }

        foreach ($items as $key => $title) {
            // Every item carries an explicit view= so picking one overrides the
            // status-based default (e.g. opening Aktueller Report on a geprüft page).
            $menu->addMenuItem(
                $menu->makeMenuItem()
                    ->setTitle($title)
                    ->setHref((string)$this->uriBuilder->buildUriFromRoute('web_aiproofread', ['id' => $pageUid, 'view' => $key]))
                    ->setActive($view === $key)
            );
        }

        $menuRegistry->addMenu($menu);
    }

    // -- Aktueller Report view ----------------------------------------------

    /**
     * @param array<string, mixed>|null $latestReport
     * @param array<string, mixed>|null $review
     */
    private function currentResponse(ServerRequestInterface $request, ModuleTemplate $moduleTemplate, int $pageUid, ?array $latestReport, ?array $review, ReviewStatus $status, string $error, bool $jobPending, string $jobError): ResponseInterface
    {
        if ($pageUid <= 0) {
            return $this->moduleResponse($moduleTemplate, $this->renderView('Review/Current.html', ['noPage' => true]));
        }

        // A specific historical run can be opened via ?reportUid= (from the
        // Report-Verlauf); otherwise show the latest run.
        $reportUid = (int)($request->getQueryParams()['reportUid'] ?? 0);
        $run = $reportUid > 0 ? $this->reports->findByUid($reportUid) : $latestReport;
        $isHistorical = $run !== null && $latestReport !== null && (int)$run['uid'] !== (int)$latestReport['uid'];

        $pageRecord = BackendUtility::getRecord('pages', $pageUid, 'uid,title');

        $this->addProofreadButtons($moduleTemplate, $pageUid, $latestReport !== null, $jobPending);

        $report = $this->decodeReport($run);
        $proofedAt = ($review !== null && (int)($review['proofed_at'] ?? 0) > 0) ? BackendUtility::datetime((int)$review['proofed_at']) : '';

        return $this->moduleResponse($moduleTemplate, $this->renderView('Review/Current.html', [
            'pageUid' => $pageUid,
            'pageTitle' => (string)($pageRecord['title'] ?? ('Seite #' . $pageUid)),
            'status' => $status->label(),
            'queued' => $jobPending,
            'queueError' => $jobError,
            'hasReport' => $run !== null,
            // Collapse the latest report once the page is signed off, or while a
            // new report is being generated (the shown one is about to be stale);
            // an explicitly opened historical run is always shown expanded.
            'collapsed' => !$isHistorical && ($status === ReviewStatus::Proofed || $jobPending),
            'isHistorical' => $isHistorical,
            'reportsUrl' => (string)$this->uriBuilder->buildUriFromRoute('web_aiproofread', ['id' => $pageUid, 'view' => self::VIEW_REPORTS]),
            'reportCreatedAt' => $run !== null ? BackendUtility::datetime((int)$run['crdate']) : '',
            'proofedAt' => $proofedAt,
            'model' => $run !== null ? (string)$run['model'] : '',
            'findingsCount' => \count($report['findings'] ?? []),
            'sections' => $this->buildReportSections($report),
            'pageFindings' => $this->buildPageFindings($report),
            'other' => $this->buildOther($report),
            'error' => $error,
        ]));
    }

    // -- Report-Verlauf view -------------------------------------------------

    /**
     * @param array<string, mixed>|null $review
     */
    private function reportsResponse(ModuleTemplate $moduleTemplate, int $pageUid, ?array $review, ReviewStatus $status, bool $jobPending, string $jobError): ResponseInterface
    {
        if ($pageUid <= 0) {
            return $this->moduleResponse($moduleTemplate, $this->renderView('Review/Reports.html', ['noPage' => true]));
        }

        $latest = $this->reports->findLatestByPage($pageUid);
        $this->addProofreadButtons($moduleTemplate, $pageUid, $latest !== null, $jobPending);

        // Sign-off banner: who marked the page geprüft, and when.
        $proofed = $status === ReviewStatus::Proofed && $review !== null;
        $proofedAt = $proofed ? BackendUtility::datetime((int)$review['proofed_at']) : '';
        $proofedBy = $proofed ? $this->userName((int)$review['proofed_by'], true) : '';

        // One column per category (abbreviated), except gender-inclusive language and
        // style: by prompt design neither is a localized finding — gender-inclusive
        // language is reported as a pageFinding, style goes to the free-text "other"
        // bucket — so their columns would always be 0 (style is already reflected in
        // the Sonstiges count). A run for which a category was disabled shows "–"
        // (vs. "0" = checked, none).
        $categories = array_values(array_filter(
            Category::ordered(),
            static fn (Category $c): bool => $c !== Category::GenderInclusiveLanguage && $c !== Category::Style,
        ));

        $rows = [];
        foreach ($this->reports->findByPage($pageUid) as $run) {
            $report = $this->decodeReport($run);
            $byCategory = [];
            foreach (($report['findings'] ?? []) as $finding) {
                if (\is_array($finding)) {
                    $cat = (string)($finding['category'] ?? '');
                    $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;
                }
            }
            // Categories enabled when this run was generated. Empty for legacy rows
            // (recorded before this was stored) → treat as unknown and show counts.
            $enabledForRun = array_filter(explode(',', (string)($run['categories'] ?? '')));
            $categoryCounts = [];
            foreach ($categories as $category) {
                if ($enabledForRun !== [] && !\in_array($category->value, $enabledForRun, true)) {
                    $categoryCounts[] = '–';
                } else {
                    $categoryCounts[] = (int)($byCategory[$category->value] ?? 0);
                }
            }

            $rows[] = [
                'date' => BackendUtility::datetime((int)$run['crdate']),
                'user' => $this->userName((int)$run['be_user'], true),
                'categories' => $categoryCounts,
                'pageFindings' => \count($report['pageFindings'] ?? []),
                'other' => \count($report['other'] ?? []),
                'cost' => $this->formatCost((float)$run['cost_usd']),
                'url' => (string)$this->uriBuilder->buildUriFromRoute('web_aiproofread', ['id' => $pageUid, 'view' => self::VIEW_CURRENT, 'reportUid' => (int)$run['uid']]),
            ];
        }

        $categoryHeaders = array_map(static fn (Category $c): string => $c->abbreviation(), $categories);

        $pageRecord = BackendUtility::getRecord('pages', $pageUid, 'uid,title');
        return $this->moduleResponse($moduleTemplate, $this->renderView('Review/Reports.html', [
            'pageUid' => $pageUid,
            'pageTitle' => (string)($pageRecord['title'] ?? ('Seite #' . $pageUid)),
            'queued' => $jobPending,
            'queueError' => $jobError,
            'proofed' => $proofed,
            'proofedAt' => $proofedAt,
            'proofedBy' => $proofedBy,
            'categoryHeaders' => $categoryHeaders,
            // Datum + Benutzer + categories + Seite + Weitere + Kosten
            'columnCount' => \count($categoryHeaders) + 5,
            'rows' => $rows,
        ]));
    }

    private function addProofreadButtons(ModuleTemplate $moduleTemplate, int $pageUid, bool $hasReport, bool $queued): void
    {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        if ($queued) {
            // A run is already queued/running. Replace the run trigger with an
            // in-progress affordance: a spinner labelled "wird erstellt", whose
            // link just reloads the page to check status (re-running would no-op
            // anyway — enqueue() dedupes per page).
            $runButton = $buttonBar->makeLinkButton()
                ->setHref((string)$this->uriBuilder->buildUriFromRoute('web_aiproofread', ['id' => $pageUid]))
                ->setTitle('Report wird erstellt …')
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('spinner-circle', Icon::SIZE_SMALL));
        } else {
            $runButton = $buttonBar->makeLinkButton()
                ->setHref((string)$this->uriBuilder->buildUriFromRoute('web_aiproofread', ['id' => $pageUid, 'action' => 'run']))
                ->setTitle($hasReport ? 'Neuen Report erstellen' : 'Report erstellen')
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('aiproofread-robot', Icon::SIZE_SMALL));
        }
        $buttonBar->addButton($runButton);

        if ($hasReport) {
            $markButton = $buttonBar->makeLinkButton()
                ->setHref((string)$this->uriBuilder->buildUriFromRoute('web_aiproofread', ['id' => $pageUid, 'action' => 'mark']))
                ->setTitle('Als geprüft markieren')
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('actions-check', Icon::SIZE_SMALL));
            $buttonBar->addButton($markButton);
        }

        $editButton = $buttonBar->makeLinkButton()
            ->setHref((string)$this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $pageUid]))
            ->setTitle('Seiteninhalt bearbeiten')
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-open', Icon::SIZE_SMALL));
        $buttonBar->addButton($editButton);
    }

    /**
     * Decode a report run's stored JSON.
     *
     * @param array<string, mixed>|null $run
     * @return array<string, mixed>
     */
    private function decodeReport(?array $run): array
    {
        if ($run === null || empty($run['report_json'])) {
            return [];
        }
        $report = json_decode((string)$run['report_json'], true);
        return \is_array($report) ? $report : [];
    }

    /**
     * Localised findings, grouped by category in urgency order.
     *
     * @param array<string, mixed> $report
     * @return array<int, array{label: string, findings: array<int, array<string, mixed>>}>
     */
    private function buildReportSections(array $report): array
    {
        $findings = $report['findings'] ?? [];
        if (!\is_array($findings)) {
            return [];
        }

        $sections = [];
        foreach (Category::ordered() as $category) {
            $items = [];
            foreach ($findings as $f) {
                if (!\is_array($f) || ($f['category'] ?? '') !== $category->value) {
                    continue;
                }
                [$quoteHtml, $suggestionHtml] = $this->diffHighlight(
                    (string)($f['quote'] ?? ''),
                    (string)($f['suggestion'] ?? ''),
                );
                $f['quoteHtml'] = $quoteHtml;
                $f['suggestionHtml'] = $suggestionHtml;
                $items[] = $f;
            }
            if ($items !== []) {
                $sections[] = ['label' => $category->label(), 'findings' => $items];
            }
        }
        return $sections;
    }

    /**
     * Char-level highlight of the change between quote and suggestion, using a
     * common-prefix/common-suffix diff — the right granularity for an atomic
     * finding, and multibyte-safe (umlauts). Whitespace inside the changed
     * segment is made visible so spacing errors are unmissable. Returns
     * [quoteHtml, suggestionHtml] as safe HTML: the text is escaped, only our
     * own markup is injected, so the template renders it with <f:format.raw>.
     *
     * @return array{0: string, 1: string}
     */
    private function diffHighlight(string $quote, string $suggestion): array
    {
        if ($quote === '' || $suggestion === '' || $quote === $suggestion) {
            return [$this->escapeText($quote), $this->escapeText($suggestion)];
        }

        $a = mb_str_split($quote);
        $b = mb_str_split($suggestion);
        $na = \count($a);
        $nb = \count($b);

        $p = 0;
        while ($p < $na && $p < $nb && $a[$p] === $b[$p]) {
            $p++;
        }
        $s = 0;
        while ($s < ($na - $p) && $s < ($nb - $p) && $a[$na - 1 - $s] === $b[$nb - 1 - $s]) {
            $s++;
        }

        return [
            $this->renderDiffSide(
                implode('', \array_slice($a, 0, $p)),
                implode('', \array_slice($a, $p, $na - $p - $s)),
                implode('', \array_slice($a, $na - $s)),
            ),
            $this->renderDiffSide(
                implode('', \array_slice($b, 0, $p)),
                implode('', \array_slice($b, $p, $nb - $p - $s)),
                implode('', \array_slice($b, $nb - $s)),
            ),
        ];
    }

    private function renderDiffSide(string $prefix, string $changed, string $suffix): string
    {
        $html = $this->escapeText($prefix);
        if ($changed !== '') {
            $html .= '<span class="aiproofread-chg">' . $this->visualizeWhitespace($this->escapeText($changed)) . '</span>';
        }
        return $html . $this->escapeText($suffix);
    }

    private function escapeText(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Make whitespace visible in already-escaped HTML: a regular space becomes a
     * middle dot, a (invisible, error-prone) non-breaking space a distinct glyph.
     */
    private function visualizeWhitespace(string $escaped): string
    {
        $escaped = str_replace(' ', "<span class=\"aiproofread-ws\">\u{00B7}</span>", $escaped);
        return str_replace("\u{00A0}", "<span class=\"aiproofread-ws aiproofread-ws--nbsp\">\u{2423}</span>", $escaped);
    }

    /**
     * Page-wide observations (consistency, style across the whole page).
     *
     * @param array<string, mixed> $report
     * @return array<int, array{label: string, observation: string, suggestion: string}>
     */
    private function buildPageFindings(array $report): array
    {
        $pageFindings = $report['pageFindings'] ?? [];
        if (!\is_array($pageFindings)) {
            return [];
        }

        $rows = [];
        foreach ($pageFindings as $finding) {
            if (!\is_array($finding)) {
                continue;
            }
            $category = Category::tryFrom((string)($finding['category'] ?? ''));
            $rows[] = [
                'label' => $category?->label() ?? '',
                'observation' => (string)($finding['observation'] ?? ''),
                'suggestion' => (string)($finding['suggestion'] ?? ''),
            ];
        }
        return $rows;
    }

    /**
     * Free-text catch-all notes.
     *
     * @param array<string, mixed> $report
     * @return array<int, string>
     */
    private function buildOther(array $report): array
    {
        $other = $report['other'] ?? [];
        if (!\is_array($other)) {
            return [];
        }
        $notes = [];
        foreach ($other as $note) {
            $text = trim((string)$note);
            if ($text !== '') {
                $notes[] = $text;
            }
        }
        return $notes;
    }

    // -- history (log) view --------------------------------------------------

    private function historyResponse(ServerRequestInterface $request, ModuleTemplate $moduleTemplate, int $pageUid, bool $isAdmin): ResponseInterface
    {
        // Defense in depth: the view is already gated to admins in handleRequest,
        // but never render the global log without re-checking here.
        if (!$isAdmin) {
            return $this->moduleResponse($moduleTemplate, $this->renderView('Review/Current.html', ['noAccess' => true]));
        }

        $logUid = (int)($request->getQueryParams()['logUid'] ?? 0);
        // The log is global, but we keep the page id in links so the view
        // dropdown can still switch back to this page's report views.
        $content = $logUid > 0 ? $this->renderLogShow($logUid, $pageUid) : $this->renderLogIndex($pageUid);
        return $this->moduleResponse($moduleTemplate, $content);
    }

    private function renderLogIndex(int $pageUid): string
    {
        $rows = [];
        foreach ($this->log->findRecent() as $row) {
            $rows[] = [
                'uid' => (int)$row['uid'],
                'date' => BackendUtility::datetime((int)$row['crdate']),
                'user' => $this->userName((int)$row['be_user'], true),
                'page' => (int)$row['page_uid'],
                // Element uid (0 = the whole-page pass → "–"), to match the page column.
                'element' => (int)($row['element_uid'] ?? 0) > 0 ? (string)(int)$row['element_uid'] : '–',
                'model' => (string)$row['model'],
                'provider' => (string)($row['provider'] ?? ''),
                'tokens' => (int)$row['input_tokens'] + (int)$row['output_tokens'],
                'cost' => $this->formatCost((float)$row['cost_usd']),
                'duration' => $this->formatDuration((int)($row['duration_ms'] ?? 0)),
                'success' => (bool)$row['success'],
                'detailUrl' => (string)$this->uriBuilder->buildUriFromRoute('web_aiproofread', ['id' => $pageUid, 'view' => self::VIEW_HISTORY, 'logUid' => (int)$row['uid']]),
            ];
        }
        return $this->renderView('Log/Index.html', ['rows' => $rows]);
    }

    private function renderLogShow(int $logUid, int $pageUid): string
    {
        $backUrl = (string)$this->uriBuilder->buildUriFromRoute('web_aiproofread', ['id' => $pageUid, 'view' => self::VIEW_HISTORY]);
        $row = $this->log->findByUid($logUid);
        if ($row === null) {
            return $this->renderView('Log/Show.html', ['notFound' => true, 'backUrl' => $backUrl]);
        }
        return $this->renderView('Log/Show.html', [
            'backUrl' => $backUrl,
            'entry' => [
                'uid' => (int)$row['uid'],
                'date' => BackendUtility::datetime((int)$row['crdate']),
                'user' => $this->userName((int)$row['be_user']),
                'page' => (int)$row['page_uid'],
                'element' => (string)($row['element_label'] ?? ''),
                'model' => (string)$row['model'],
                'provider' => (string)($row['provider'] ?? ''),
                'inputTokens' => (int)$row['input_tokens'],
                'outputTokens' => (int)$row['output_tokens'],
                'cost' => $this->formatCost((float)$row['cost_usd']),
                'duration' => $this->formatDuration((int)($row['duration_ms'] ?? 0)),
                'success' => (bool)$row['success'],
                'error' => (string)($row['error_message'] ?? ''),
                'request' => $this->prettyJson((string)($row['request_json'] ?? '')),
                'response' => $this->prettyJson((string)($row['response_json'] ?? '')),
            ],
        ]);
    }

    private function userName(int $beUserId, bool $usernameOnly = false): string
    {
        if ($beUserId === 0) {
            return '–';
        }
        $user = BackendUtility::getRecord('be_users', $beUserId, 'username,realName');
        if ($user === null) {
            return '#' . $beUserId;
        }
        $username = (string)($user['username'] ?? '');
        if ($usernameOnly) {
            return $username !== '' ? $username : ('#' . $beUserId);
        }
        return trim(($user['realName'] ?? '') . ' (' . $username . ')');
    }

    private function formatCost(float $costUsd): string
    {
        return '$' . number_format($costUsd, 4, '.', ',');
    }

    private function formatDuration(int $durationMs): string
    {
        if ($durationMs <= 0) {
            return '–';
        }
        return $durationMs < 1000
            ? $durationMs . ' ms'
            : number_format($durationMs / 1000, 1, ',', '.') . ' s';
    }

    private function prettyJson(string $json): string
    {
        $decoded = json_decode($json, true);
        if ($decoded === null) {
            return $json;
        }
        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $json;
    }

    // -- shared --------------------------------------------------------------

    /**
     * Wrap already-rendered inner HTML in the backend module chrome and return a
     * response. Version-guarded: setContent()/renderContent() were the v11/12 API
     * and were removed in v13, where assign('content', …) + renderResponse() (a
     * thin wrapper template, ModuleBody.html) is used instead. Branching on the
     * method that exists keeps one code path working across v11–v13.
     */
    private function moduleResponse(ModuleTemplate $moduleTemplate, string $html): ResponseInterface
    {
        if (method_exists($moduleTemplate, 'renderContent')) {
            $moduleTemplate->setContent($html);
            return new HtmlResponse($moduleTemplate->renderContent());
        }

        $moduleTemplate->assignMultiple(['content' => $html]);
        return $moduleTemplate->renderResponse('ModuleBody');
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function renderView(string $template, array $variables): string
    {
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setTemplatePathAndFilename(
            GeneralUtility::getFileAbsFileName('EXT:ai_proofread/Resources/Private/Templates/' . $template)
        );
        $view->assignMultiple($variables);

        return $view->render();
    }
}
