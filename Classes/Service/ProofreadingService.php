<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service;

use Bmorg\AiProofread\Enum\Category;
use Bmorg\AiProofread\Service\Llm\LlmClientInterface;
use Bmorg\AiProofread\Service\Llm\LlmException;

/**
 * Orchestrates a proofreading run: extract text → ask the LLM → store the
 * report snapshot. Also builds the German review prompt and the structured
 * output schema from the configured categories.
 *
 * Proofreading is purely advisory — it never blocks editing or publishing.
 */
final class ProofreadingService
{
    public function __construct(
        private readonly ContentExtractor $extractor,
        private readonly ReviewRepository $reviews,
        private readonly ReportRepository $reports,
        private readonly LlmClientInterface $llm,
        private readonly LogRepository $log,
        private readonly ExtensionSettings $settings,
    ) {
    }

    /**
     * Proofread a page and store the report as a new run in the history.
     *
     * Scope matters for recall: a model gliding over a whole page misses subtle
     * local errors it catches when focused on one element (attention dilution —
     * found empirically in local tests). So localized
     * `findings` are gathered with one focused LLM pass **per content element**,
     * while the page-wide `pageFindings`/`other` come from a single whole-page
     * pass that preserves cross-element context.
     *
     * These N+1 passes are independent and I/O-bound, so they are dispatched
     * **concurrently** (`completeBatch`, bounded by `maxConcurrency`) — wall-clock
     * is ~one slow call, not the sum. Every call is logged individually (tagged
     * with the run and the content element); a single failed pass fails the whole
     * run (surfaced to the queue job).
     *
     * $onProgress, if given, is invoked as each pass settles — the queue worker
     * uses it to heartbeat a long-running job so it isn't reclaimed as stale.
     *
     * @param (callable(): void)|null $onProgress
     * @return array<string, mixed> the merged three-bucket report
     */
    public function proofreadPage(int $pageUid, int $beUserId, int $languageUid = 0, ?callable $onProgress = null): array
    {
        $wholeText = $this->extractor->extractPageText($pageUid, $languageUid);
        if ($wholeText === '') {
            // No checkable text on the page (empty page, or only non-text
            // elements). Still store a report run with an explanatory note —
            // otherwise the queue job is deleted as "success" with no report
            // row, and the page silently falls back to "Ungeprüft" with no
            // explanation for the editor.
            return $this->storeEmptyReport($pageUid, $languageUid, $beUserId);
        }

        $system = $this->buildSystemPrompt();
        $schema = $this->buildSchema();

        // One focused pass per element (findings) + one whole-page pass
        // (pageFindings/other), tracking which element each request belongs to.
        $requests = [];
        $elements = [];
        foreach ($this->extractor->getContentElementsForPage($pageUid, $languageUid) as $row) {
            $text = $this->extractor->extractText($row);
            if ($text === '') {
                continue;
            }
            $requests[] = ['system' => $system, 'userText' => $text, 'schema' => $schema];
            $elements[] = ['uid' => (int)($row['uid'] ?? 0), 'label' => $this->extractor->elementLabel($row)];
        }
        $pageIndex = \count($requests);
        $requests[] = ['system' => $system, 'userText' => $wholeText, 'schema' => $schema];
        $elements[] = ['uid' => 0, 'label' => 'Gesamte Seite'];

        $startedAt = microtime(true);
        $outcomes = $this->llm->completeBatch($requests, $this->maxConcurrency(), $onProgress);
        $durationMs = (int)round((microtime(true) - $startedAt) * 1000);

        $findings = [];
        $pageFindings = [];
        $other = [];
        $model = '';
        $totalCost = 0.0;
        $firstError = null;
        $logEntries = [];

        foreach ($outcomes as $i => $outcome) {
            $element = $elements[$i] ?? ['uid' => 0, 'label' => ''];
            if ($outcome instanceof LlmException) {
                $logEntries[] = [
                    'success' => false,
                    'model' => (string)($outcome->requestBody['model'] ?? ''),
                    'request' => $outcome->requestBody,
                    'rawResponse' => $outcome->rawResponse,
                    'error' => $outcome->getMessage(),
                    'durationMs' => $outcome->durationMs,
                    'element' => $element,
                ];
                $firstError ??= $outcome;
                continue;
            }

            $totalCost += $outcome->cost ?? 0.0; // OpenRouter usage.cost (USD); 0 if omitted
            $model = $outcome->model;
            $logEntries[] = [
                'success' => true,
                'model' => $outcome->model,
                'request' => $outcome->requestBody,
                'response' => $outcome->responseBody,
                'inputTokens' => $outcome->inputTokens,
                'outputTokens' => $outcome->outputTokens,
                'cost' => $outcome->cost ?? 0.0,
                'durationMs' => $outcome->durationMs,
                'element' => $element,
            ];

            if ($i === $pageIndex) {
                // Whole-page pass: keep only the cross-element buckets.
                $pageFindings = $outcome->payload['pageFindings'] ?? [];
                $other = $outcome->payload['other'] ?? [];
            } else {
                // Per-element pass: tag each finding with the element it came from,
                // so the review-and-fix UI can offer "apply" (write-back) and a
                // deep-link to that element's edit form. The whole-page pass (above)
                // owns pageFindings/other, which stay un-attributed by design.
                foreach (($outcome->payload['findings'] ?? []) as $finding) {
                    if (\is_array($finding)) {
                        $finding['elementUid'] = (int)$element['uid'];
                        $finding['elementLabel'] = (string)$element['label'];
                    }
                    $findings[] = $finding;
                }
            }
        }

        // A failed pass means an incomplete report: log the calls (unattached —
        // there is no report row) and surface the error to the queue job.
        if ($firstError !== null) {
            $this->writeLogs($logEntries, $beUserId, $pageUid, 0);
            throw $firstError;
        }

        $report = $this->buildReport([
            'findings' => $findings,
            'pageFindings' => $pageFindings,
            'other' => $other,
        ]);

        $reportUid = $this->reports->store(
            $pageUid,
            $languageUid,
            $beUserId,
            $model,
            json_encode($report, JSON_THROW_ON_ERROR),
            // The categories enabled for this run — so the Report-Verlauf can tell
            // "checked, found 0" from "category was disabled" (shown as "–").
            implode(',', array_map(static fn (Category $c): string => $c->value, $this->enabledCategories())),
            $totalCost,
            $durationMs,
            $this->extractor->hash($wholeText),
        );

        $this->writeLogs($logEntries, $beUserId, $pageUid, $reportUid);

        return $report;
    }

    /**
     * Store a report run for a page with no checkable text. No LLM call is made
     * (so no model/cost/duration and no audit-log rows); the single `other` note
     * tells the editor why the report is empty, and the stored row flips the page
     * status to "Report erstellt" instead of leaving it stuck on "Ungeprüft".
     *
     * @return array<string, mixed> the (empty) three-bucket report
     */
    private function storeEmptyReport(int $pageUid, int $languageUid, int $beUserId): array
    {
        $report = $this->buildReport([
            'findings' => [],
            'pageFindings' => [],
            'other' => ['Auf dieser Seite wurde kein prüfbarer Textinhalt gefunden.'],
        ]);

        $this->reports->store(
            $pageUid,
            $languageUid,
            $beUserId,
            '',
            json_encode($report, JSON_THROW_ON_ERROR),
            implode(',', array_map(static fn (Category $c): string => $c->value, $this->enabledCategories())),
            0.0,
            0,
            $this->extractor->hash(''),
        );

        return $report;
    }

    /**
     * Flush the buffered per-call log entries, tagging each with the run and the
     * content element. (Deferred because the report uid isn't known until after
     * all calls complete and the run is stored.)
     *
     * @param array<int, array<string, mixed>> $entries
     */
    private function writeLogs(array $entries, int $beUserId, int $pageUid, int $reportUid): void
    {
        foreach ($entries as $e) {
            $element = $e['element'];
            $elementUid = (int)$element['uid'];
            $elementLabel = (string)$element['label'];
            if ($e['success'] === true) {
                $this->log->logSuccess(
                    $beUserId,
                    $pageUid,
                    (string)$e['model'],
                    (string)($e['response']['provider'] ?? ''),
                    $e['request'],
                    $e['response'],
                    (int)$e['inputTokens'],
                    (int)$e['outputTokens'],
                    (float)$e['cost'],
                    $e['durationMs'],
                    $reportUid,
                    $elementUid,
                    $elementLabel
                );
            } else {
                // The provider (if any) is in the raw response body.
                $decoded = json_decode((string)$e['rawResponse'], true);
                $provider = \is_array($decoded) ? (string)($decoded['provider'] ?? '') : '';
                $this->log->logError(
                    $beUserId,
                    $pageUid,
                    (string)$e['model'],
                    $provider,
                    $e['request'],
                    (string)$e['rawResponse'],
                    (string)$e['error'],
                    $e['durationMs'],
                    $reportUid,
                    $elementUid,
                    $elementLabel
                );
            }
        }
    }

    /**
     * Max number of the N+1 passes to run concurrently (provider rate-limit
     * guard). Configured `maxConcurrency`, else a safe default of 4.
     */
    private function maxConcurrency(): int
    {
        $configured = (int)($this->config('maxConcurrency') ?? 0);
        return $configured > 0 ? $configured : 4;
    }

    /**
     * Record the editor's "geprüft" sign-off for the page.
     */
    public function markPageProofed(int $pageUid, int $beUserId): void
    {
        $this->reviews->markProofed($pageUid, $beUserId);
    }

    /**
     * @return list<Category> the categories enabled by the current configuration
     */
    public function enabledCategories(): array
    {
        // Both default to on when unset — see ExtensionSettings::DEFAULTS.
        $enableStyle = (bool)$this->config('enableStyle');
        $enableGenderInclusiveLanguage = (bool)$this->config('enableGenderInclusiveLanguage');

        return array_values(array_filter(Category::ordered(), static function (Category $c) use ($enableStyle, $enableGenderInclusiveLanguage): bool {
            return match ($c) {
                Category::Style => $enableStyle,
                Category::GenderInclusiveLanguage => $enableGenderInclusiveLanguage,
                default => true,
            };
        }));
    }

    private function buildSystemPrompt(): string
    {
        $categories = $this->enabledCategories();
        $lines = [];
        foreach ($categories as $c) {
            $lines[] = sprintf('- %s (%s)', $c->label(), $c->value);
        }
        $categoryIds = implode(', ', array_map(static fn (Category $c): string => $c->value, $categories));
        // Missing-key default lives in ExtensionSettings::DEFAULTS; an explicit empty
        // value is respected (the admin cleared it — the settings label names the default).
        $genderInclusiveStyle = (string)$this->config('genderInclusiveStyle');
        $genderEnabled = \in_array(Category::GenderInclusiveLanguage, $categories, true);

        // Optional site description so integrators can give the model context about
        // the kind of content it proofreads (e.g. "ein privates Weblog").
        $site = trim((string)($this->config('siteDescription') ?? ''));
        $intro = $site !== ''
            ? sprintf('Du bist ein sorgfältiger deutscher Lektor für die Inhalte folgender Website: %s.', $site)
            : 'Du bist ein sorgfältiger deutscher Lektor für Website-Inhalte.';

        $prompt = implode("\n", array_filter([
            $intro,
            'Du erhältst den Textinhalt einer Seite bzw. eines Inhaltselements (ohne Formatierungen).',
            '',
            'Kategorien:',
            implode("\n", $lines),
            '',
            'Achte unter anderem auf: Tippfehler, Groß-/Kleinschreibung, Zusammen- und Getrenntschreibung, Kommasetzung '
            . '(besonders vor erweitertem Infinitiv mit „zu“ und vor Relativsätzen), Numerus- und Kasuskongruenz, '
            . 'überflüssige oder fehlende Leerzeichen sowie Wortwiederholungen.',
            '',
            // Only when the Gendern category is on AND a house style is set: clearing
            // the style means "no house style" (per the setting), and emitting it while
            // empty produced the garbled "Beim Gendern ist die Hausschreibweise: .".
            ($genderEnabled && $genderInclusiveStyle !== '')
                ? 'Beim Gendern ist die Hausschreibweise: ' . $genderInclusiveStyle . '.'
                : null,
            '',
            'Gliedere deine Rückmeldung in drei Felder:',
            'findings = eindeutige, sichere Fehler in den genannten Kategorien, deren Korrektur die Bedeutung NICHT verändert. '
            . 'Felder: category (eine der Kennungen: ' . $categoryIds . '), quote (exaktes Originalzitat), suggestion (korrigierte Fassung), '
            . 'explanation (kurze Begründung). Auch kleine, aber eindeutige Fehler (z. B. fehlende Pflichtkommata) gehören hierher.',
            'pageFindings = seitenweite bzw. übergreifende Konsistenzhinweise, die sich NICHT auf eine einzelne Textstelle beziehen '
            . '(z. B. uneinheitliches Gendern, uneinheitliche Anführungszeichen): category, observation (Beschreibung), suggestion (Empfehlung).',
            'other = alle übrigen redaktionellen Hinweise als freie Textpunkte: Stilpräferenzen, Umformulierungen, Lesbarkeit sowie '
            . 'inhaltliche, fachliche und Aktualitäts-Hinweise (z. B. veraltete Angaben, nicht eingeführte Abkürzungen, '
            . 'fragwürdige Datums- oder Versionsangaben). In other ist Vollständigkeit erwünscht; sei hier großzügig, Unsicherheit ist unkritisch.',
            '',
            'Strenge Regeln:',
            '- Eindeutige Rechtschreib-, Grammatik- und Zeichensetzungsfehler — auch die oben genannten Fehlerarten — gelten als '
            . 'sicher und gehören IMMER in findings, auch wenn sie klein sind. „Unsicher" meint nur interpretative oder '
            . 'stilistische Zweifelsfälle; diese gehören nach other oder gar nicht in die Antwort.',
            '- Verändere niemals die Bedeutung und triff keine Annahmen über die gemeinte Bedeutung.',
            '- Korrekte, auch umgangssprachlich akzeptable Formulierungen (z. B. „mehrmals die Woche") sind keine Fehler.',
            '- Reine Stil-, Umformulierungs- oder Lesbarkeitsvorschläge gehören NICHT in findings, sondern in other.',
            // Only when the Gendern category is enabled — otherwise the schema enum
            // excludes "gender-inclusive-language" and this would route the model to a
            // category it can't emit.
            $genderEnabled
                ? '- Gendern: Schlage gegenderte Formen nicht von dir aus vor. Melde uneinheitliches Gendern nur als pageFinding. '
                    . 'Konkrete gegenderte Korrekturen in findings nur dort, wo der Text bereits gendert und die Form inkonsistent oder grammatisch falsch ist.'
                : null,
            '- Anführungszeichen in den Fließtext-Feldern (explanation, observation, other): Verwende für Hervorhebungen und Zitate '
            . 'IMMER die deutschen typografischen Anführungszeichen (öffnend „, schließend “) und NIEMALS das gerade Zeichen ". '
            . 'Ein gerades " beendet sonst die JSON-Zeichenkette vorzeitig und schneidet den Text ab.',
            '',
            'Lass Listen leer, wenn es nichts gibt. Erfinde keine Fehler. Antworte ausschließlich im vorgegebenen JSON-Schema.',
        ], static fn ($line): bool => $line !== null));

        // Site-specific custom rules.
        $extra = trim((string)($this->config('extraPromptInstructions') ?? ''));
        if ($extra !== '') {
            $prompt .= "\n\nZusätzliche Anweisungen:\n" . $extra;
        }

        return $prompt;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSchema(): array
    {
        $enums = array_map(static fn (Category $c): string => $c->value, $this->enabledCategories());

        return [
            'type' => 'object',
            'properties' => [
                // Localised findings: anchored to a single text span.
                'findings' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => ['type' => 'string', 'enum' => $enums],
                            'quote' => ['type' => 'string'],
                            'suggestion' => ['type' => 'string'],
                            'explanation' => ['type' => 'string'],
                        ],
                        'required' => ['category', 'quote', 'suggestion', 'explanation'],
                        'additionalProperties' => false,
                    ],
                ],
                // Page-wide observations that don't map to a single span
                // (e.g. inconsistent gender-inclusive language, recurring repetition, style).
                'pageFindings' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => ['type' => 'string', 'enum' => $enums],
                            'observation' => ['type' => 'string'],
                            'suggestion' => ['type' => 'string'],
                        ],
                        'required' => ['category', 'observation', 'suggestion'],
                        'additionalProperties' => false,
                    ],
                ],
                // Free-text catch-all: editorial notes (style, clarity, content)
                // that fit neither structured list.
                'other' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['findings', 'pageFindings', 'other'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Build the stored report from the model payload: the two structured lists
     * (each ordered by category) plus the free-text "other" list.
     *
     * @param array<string, mixed> $payload
     * @return array{findings: array<int, mixed>, pageFindings: array<int, mixed>, other: array<int, string>}
     */
    private function buildReport(array $payload): array
    {
        return [
            'findings' => $this->sortByCategory($payload['findings'] ?? []),
            'pageFindings' => $this->sortByCategory($payload['pageFindings'] ?? []),
            'other' => $this->stringList($payload['other'] ?? []),
        ];
    }

    /**
     * Normalize a free-text list (the "other" bucket) to non-empty strings.
     *
     * @param mixed $value
     * @return array<int, string>
     */
    private function stringList($value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $text = trim((string)$item);
            if ($text !== '') {
                $out[] = $text;
            }
        }
        return array_values($out);
    }

    /**
     * @param mixed $findings
     * @return array<int, mixed>
     */
    private function sortByCategory($findings): array
    {
        if (!\is_array($findings)) {
            return [];
        }
        usort($findings, static function ($a, $b): int {
            $sa = Category::tryFrom((string)(\is_array($a) ? ($a['category'] ?? '') : ''))?->sort() ?? 99;
            $sb = Category::tryFrom((string)(\is_array($b) ? ($b['category'] ?? '') : ''))?->sort() ?? 99;
            return $sa <=> $sb;
        });
        return array_values($findings);
    }

    private function config(string $key): mixed
    {
        return $this->settings->get($key);
    }
}
