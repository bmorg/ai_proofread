# CLAUDE.md — ai_proofread

Guidance for Claude Code (and contributors) working in this extension. It covers what's specific and non-obvious; the conventions this extension follows are listed at the end.

## What this is

A TYPO3 backend extension that runs **AI proofreading of page content**. An editor (or a bulk job) sends a page's text to an LLM and gets back a structured report of issues. It is **advisory only — it never blocks saving or publishing.**

- **Composer:** `bmorg/ai_proofread` · **extension key:** `ai_proofread` · **namespace:** `Bmorg\AiProofread\`
- **Targets:** TYPO3 **v11.5 / v12.4 / v13.4**, PHP **8.2+**. **v11 is strictly required** — it's the one place cross-version glue costs us (legacy module registration; see below). PHP 8.2 is the common floor across v11–v13.
- Publicly published, so favor no-code admin configuration and a clean public extension surface.

## Status (read before assuming things work)

This is an **early alpha** (0.1.0), in production use on **TYPO3 v11** — the only version it's actually been run on. **v12/v13 are code-targeted but not yet exercised on a live instance**: the dev sandbox here has no TYPO3 core checked out, so the loop is `php -l` + `composer.json`/XLF validation only. The things most likely to need attention on v12/v13:

- Backend **module registration** is split: `Configuration/Backend/Modules.php` (v12/13) and legacy `addModule` in `ext_tables.php` (v11). **Rendering is two-layer:** inner views via `StandaloneView::setTemplatePathAndFilename()` (absolute `EXT:` path — version-neutral, fine everywhere), and the module **chrome** via the version-guarded `ReviewModuleController::moduleResponse()` — v11/12 use `setContent()`/`renderContent()`, v13 (where those were removed) uses `assignMultiple(['content'=>…])` + `renderResponse('ModuleBody')`. The v13 path resolves `Resources/Private/Templates/ModuleBody.html` through the module route's `packageName` option (the backend-view convention is plain `Resources/Private/Templates/`, **not** a `Backend/` subdir — verified against v13 core source, but **not** yet on a live v13; this template resolution is the single most important thing to confirm there).
- **Run / mark / report all live in the KI-Lektorat module** (no modal, no AJAX, **no backend JavaScript at all**). Run/mark are tokenized GET links on the docheader (`?id=<page>&action=run|mark`).
- **Entry point** is a "KI-Lektorat" docheader button injected into the **Page** module (`web_layout`) by `EventListener\AddProofreadButton`. Two mechanisms, version-guarded so they never double-add: **v11 uses the legacy `getButtonsHook`** (`SC_OPTIONS['Backend\Template\Components\ButtonBar']['getButtonsHook']`, registered in `ext_localconf.php`, method `getButtons`); **v12/13 use the PSR-14 `ModifyButtonBarEvent`** (`__invoke`, tagged in `Services.yaml`). v11 was originally event-only and the button never showed — that's why both exist now.

Scheduler/CLI commands: `bin/typo3 aiproofread:process-queue` (drains the module's run queue — register as a recurring Scheduler task) and `bin/typo3 aiproofread:subtree <rootPageUid>` (bulk).

## Architecture

### LLM backend

**Scope is deliberately narrow: one backend — OpenRouter, via the OpenAI-compatible wire format.** (The native Anthropic `ClaudeClient` and a multi-backend selection mechanism existed earlier and were removed — we only use Claude *through OpenRouter*, and there were no resources to test anything else.)

`Service\Llm\LlmClientInterface` has `complete(...): LlmResult` + `completeBatch(requests, concurrency): list<LlmResult|LlmException>` (concurrent batch — see below). Two implementations:
- **`OpenAiCompatibleClient`** — the real client. OpenAI `/chat/completions`; with OpenRouter it also covers any OpenAI-compatible endpoint, but OpenRouter is what's configured/tested. Config (the **`provider`** tab): `baseUrl` (default `https://openrouter.ai/api/v1`), `apiKey` (`sk-or-…`), `model` (e.g. `anthropic/claude-opus-4.8`), `structuredOutput` (`json_schema`/`json_object`/`prompt`), `reasoning`, `maxTokens`. Auth is a fixed `Authorization: Bearer <key>` (the configurable auth-header/scheme/extra-headers knobs were dropped — speculative for OpenRouter-only). Extends `AbstractHttpLlmClient`, which owns transport, the **concurrent Guzzle pool**, single-call `complete()` (= a batch of 1), and error→`LlmException` mapping; the client just implements `buildCall()`/`parseResponse()`/`requestTimeout()`.
- **`MockClient`** — deterministic fake report, no API/key/cost; implements the interface directly.

`LlmClientFactory::create()` returns the mock when the **`useMock`** setting is on, else the OpenAI-compatible client; bound to the interface via `factory:` in `Services.yaml` (the binding keeps `class:` to satisfy autoconfigure). Constructors stay side-effect-free; validate config in `buildCall()`. On failure every client throws `LlmException` carrying the attempted request + raw response, so failures are auditable.

**Prompt design (`ProofreadingService::buildSystemPrompt`):** one prompt, model-agnostic (not model-specific — that rots). It combines an explicit error-type checklist (recall) with strict routing (precision): clear orthographic/grammar/punctuation errors **always** go in `findings`; only interpretive/stylistic doubt goes to `other`; `other` is explicitly liberal (style, content, currency notes). Plus "don't change meaning", "correct idiom isn't an error", and gender-inclusive language restraint (don't impose gendered forms; flag inconsistency in `pageFindings`). **Keep the default general** — resist baking in narrow, single-FP-derived clauses. That is exactly what `extraPromptInstructions` is for.

**Site context** is configurable so nothing site-specific is baked into the default prompt (the extension is publishable): the **`siteDescription`** setting fills the opening line (e.g. *"…für die Inhalte folgender Website: eine Fanseite für Klemmbaustein-Sets."*); empty → a fully generic *"…für Website-Inhalte."* with no domain reference.

**Site-specific rules** live in the `extraPromptInstructions` setting, **appended** to the prompt so they can't break the schema/category contract. This is the home for narrow per-site exceptions. Ready-to-paste examples: *headings/definitions may be sentence fragments* — `Sätze, die eine Überschrift beantworten oder einen Begriff definieren, dürfen Satzfragmente sein.`; *stylistic restraint* — `Sei bei Stilfragen besonders zurückhaltend.` Watch the first per-element runs for fragment false positives (an element is often a heading + short answer seen in isolation); if they appear, the fragment rule above is the fix. The full model/prompt evaluation — capability tiers, failure modes, prompt iterations, the scope finding, the test harness/outputs — lives in `model-experiments/README.md` (dev-only; not shipped).

**Quality / robustness (learned from testing):**
- **Reasoning** is the biggest quality lever — `reasoning` adds `{"reasoning":{"enabled":true}}` (OpenRouter); with it + the three-bucket prompt, Opus 4.8 matches/exceeds claude.ai web output. Needs a reasoning-class model; flash/mini models under-perform and some open models hallucinate suggestions.
- **Truncation detection is mandatory.** Reasoning tokens count against `max_tokens`; a verbose reasoner can exhaust the budget and — because strict structured output **force-closes the JSON** — emit a *parseable but near-empty* answer. So the client checks the **finish reason** (`finish_reason: length` / `native_finish_reason: max_tokens`) and throws `LlmException` — never trust "the JSON parsed". Without this, reasoning silently produces empty reports (observed: Sonnet 4.6 even at 30k).
- **OpenRouter can route to a provider that ignores `response_format`.** Then you get the JSON wrapped in ```json fences, or even *invalid* JSON (unescaped `"` in `quote`/`suggestion`, since no enforcing decoder escaped it) — observed with `"provider": "Google"` (Vertex) serving Claude. Two defences: (1) the **`providerOrder`** setting → OpenRouter `provider: {order: [...]}` (default `anthropic`) routes to a provider that enforces valid JSON, combined with **`allowFallbacks`** → `allow_fallbacks` (snake_case + real bool; default **off** = pin strictly: a clear error if no listed provider is available, rather than silently routing to a bad one — set on to fall back). Two pitfalls learned the hard way: don't use `require_parameters` (Claude providers don't advertise native `response_format` support, so it excludes them all → 404); and the **model slug must be one Anthropic actually serves** (`anthropic/claude-opus-4` is a *dead* slug — only a Google passthrough served it; with fallbacks off, pinning `anthropic` to it 404s. Use the current `anthropic/claude-opus-4.8`). (2) parsing is **tolerant** (`tryParseJson`: raw, else first `{…}` span) to strip fences/prose. A genuinely malformed reply still errors (no silent empty).
- **Straight `"` inside prose fields truncates them.** Under strict structured output the constrained decoder treats a literal `"` as the end of the JSON string — so when the model uses a straight quote as a *typographic* quotation mark in `explanation`/`observation`/`other` (e.g. `„Sie"`), the value is silently cut off there (valid JSON, short text; `finish_reason: stop`, so truncation detection won't catch it — it's not output truncation). Not a parse/merge/display bug; the text is genuinely lost in the model output. Mitigation is in the prompt: instruct German typographic quotes `„…“` and **never** a straight `"` in the free-text fields. (The `quote`/`suggestion` fields are unaffected — there the model escapes `\"` correctly.)
- **`max_tokens` is configurable** (`maxTokens`, 0 = auto). Auto-default jumps to 32000 when reasoning is on.
- **Request timeout is configurable** (`requestTimeout`, 0 = auto). Auto is 180s, or 600s for the OpenAI client when reasoning is on (reasoning models are slow — minutes). Runs happen in the **`aiproofread:process-queue` CLI/Scheduler** context (not the web request — see "Async run queue"). A page's N+1 passes run **concurrently** (`maxConcurrency`), so per-page wall-clock is ~one slow call; but the command may process several pages in a row, so still give the PHP CLI a generous `max_execution_time`.

**Decisions not to re-litigate:**
- **One backend only (OpenRouter / OpenAI-compatible).** A native Anthropic client + a multi-backend factory (tagged-iterator selection by a `backend` setting) were built and then removed: we use Claude *via OpenRouter*, and there were no resources to test other providers. Don't re-add provider plumbing speculatively — the interface + `AbstractHttpLlmClient` are enough to grow back into if a second backend ever earns its keep.
- The bundled **`claude-api` skill is Anthropic-native (SDK/Messages API)** — do **not** use it for this extension's OpenRouter/OpenAI-compatible HTTP; verify the chat-completions schema against current OpenRouter/OpenAI docs instead.

### Scope: split per request (N+1), merged into one page report

A check is **page-scoped in result but split in execution** (`ProofreadingService::proofreadPage`). Two scopes, because they want opposite things:

- **`findings` (localized) — one focused LLM pass per content element.** Whole-page input *dilutes attention*: a capable model glides past a subtle local error it catches when focused on the single element (proven empirically — a stray subject–predicate comma missed whole-page, caught on the lone paragraph; *same prompt*, only scope changed. See `model-experiments/README.md` → "input scope"). Prompt wording does **not** fix this; scope does.
- **`pageFindings` + `other` (page-wide) — one whole-page pass** (`extractPageText()`), which preserves the cross-element context those buckets need (consistency, currency). Its `findings` are discarded — the per-element passes own those.

So a run is **N+1 LLM requests** (N elements + 1 whole-page), merged into one three-bucket report. Same prompt/schema for every pass; the caller keeps the relevant buckets (element passes → `findings`; whole-page pass → `pageFindings`/`other`, its findings discarded). The passes are independent and I/O-bound, so `proofreadPage` fires them **concurrently** via `LlmClientInterface::completeBatch`, bounded by the `maxConcurrency` setting (default 4 — a provider rate-limit guard) — wall-clock is ~one slow call, not the sum. Each call is logged individually; one failed pass fails the whole run. Cost/latency is still why a run is **queued, not inline** (see below). There is no per-element attribution in the stored report — findings are flat.

The three buckets (`buildReport`, rendered in `Review/Current.html`): `findings` (localized, `quote→suggestion`), `pageFindings` (page-wide structured), `other` (free-text catch-all). Reaching web-chat quality needed all three plus reasoning — see below.

### Async run queue (Scheduler)

Because a run is N+1 reasoning requests (concurrent, but still minutes of wall-clock), the module **never proofreads inline**. "Report erstellen" enqueues a job in `tx_aiproofread_queue` (`QueueRepository::enqueue`, deduped per page/language); the **`aiproofread:process-queue`** command (register as a recurring **Scheduler** task) drains it via `proofreadPage`. Job lifecycle: pending → running → (success: row deleted, the review snapshot is the result) / (failure: kept as `error` + message). The module shows **"Report wird erstellt …"** while pending/running and surfaces a failed job; `findForPage` drives that. **Ops requirement:** without the Scheduler task running, clicks queue but nothing processes.

### Report history + status model

**Reports accumulate.** Each run is an **immutable row** in `tx_aiproofread_report` (`ReportRepository`): page, language, user, model, the merged report JSON, the `categories` enabled for that run (so the Verlauf can tell "checked, found 0" from "category disabled"), cost, duration, and a `content_hash` (metadata for the bulk command's skip-unchanged check only — *not* for status). The newest run for a page is its "Aktueller Report"; the full list is the "Report-Verlauf" (which decodes `report_json` for the per-category finding breakdown). No cross-run finding reconciliation — each run is just a frozen snapshot.

**Sign-off is page-level.** `tx_aiproofread_review` is now just per-page sign-off state (`proofed_at`/`proofed_by`); marking "geprüft" records *when*, nothing else.

**Status is derived from timestamps — no content hash, no "veraltet"** (`ReviewRepository::deriveStatus`, `Enum\ReviewStatus`):
- no run → **Ungeprüft**; signed off at/after the latest run → **Geprüft**; otherwise (a run newer than the sign-off, or never signed off) → **Report erstellt**.
- Rationale: the normal flow is *read report → edit page → mark geprüft*, so the content is **always** different afterward — tracking "changed since" would just nag. Re-running creates a newer run → the page naturally flips back to *Report erstellt*. (The hash + a "veraltet" state were removed; the hash lives on for the bulk skip only.)

### Removed / not built (deliberately)

- **Per-element *attribution* in the report.** The merged report is flat — findings aren't tagged back to a source element, and there are no per-element report sections. (Per-element *checking* was reintroduced for the `findings` bucket — see "Scope" — as an internal recall mechanism, and the per-element split *is* visible in the **API-Verlauf** (each call's Inhaltselement), but the editor-facing report stays flat.)
- **Applying changes.** The report is read-only. The editor reads the findings and edits the content manually; there is no write-back into fields.
- **Multi-language support.** The *write* side threads `language_uid` through (`ProofreadingService`, the queue, the report/queue tables keep the column), but the **read** side is deliberately L0-only for 0.1: `ReportRepository::find*ByPage()`, `ReviewRepository`, and the controller's `enqueue(..., 0, ...)` ignore language, so the module surfaces only the default language. The `subtree` command's `--language` option was **removed** (0.1) because it wrote rows the read paths couldn't correctly surface. The columns stay so a future language-aware read pass doesn't need a migration.

### Possible future improvements (discussed, not built)

- **Apply suggestions** (`quote → suggestion` write-back via DataHandler). Hard part is anchoring a plain-text quote into stored RTE HTML — trivial for `header`/`subheader`, needs care for `bodytext`; would need a copy/edit fallback when the quote can't be located.
- **Co-locate the report with the edit form** (findings panel inside the content element's FormEngine form).
- **Richer report in the module**: per-finding "copy" buttons and category filtering (both need backend JS, deliberately excluded), and deep-links to the specific content element (needs per-element attribution, deliberately removed). *Done already:* char-level red/green diff highlighting (server-side prefix/suffix diff in `ReviewModuleController::diffHighlight`) and whitespace glyphs for spacing errors.
- **Inline accept/reject in the RTE** (CKEditor plugin, Grammarly-style) — best UX, most work, crowded lane (WProofreader).
- **Splitting findings into localized vs. page-level/advisory** (only relevant if per-element attribution is reintroduced).

### Categories

`Enum\Category` — fixed display order (`sort()`): **Rechtschreibung → Zeichensetzung → Grammatik → Gendern → Stil**. Drives report section order, the Report-Verlauf columns (`abbreviation()`: Rechtsch./Typ./Gramm. — **gender-inclusive language *and* style are excluded** there: by prompt design neither is a localized finding (gender-inclusive language → pageFinding, style → the free-text `other` bucket), so their columns would always be 0 (style is already reflected in the Sonstiges count); a run that had a category disabled shows "–" for it, vs. "0" = checked/none), and the structured-output schema enum. Gendern and Stil are config-toggleable (Stil off by default; Gendern policy/form configurable). The Aktueller Report view shows whatever is in the stored report (all categories), regardless of current config.

### Audit log

Every LLM call (success or failure) is logged to `tx_aiproofread_log`: full request/response JSON, initiating BE user, model, **provider** (the actual OpenRouter provider that served it, from the response — useful for debugging routing), tokens, cost, **call duration (`duration_ms`)**, success/error, plus the **run (`report_uid`)** and **content element (`element_uid`/`element_label`)** it belongs to. Surfaced in the **API-Verlauf** view (list + detail). The queue runs a page as **N+1 concurrent calls**, so one "Report erstellen" produces N+1 log rows (one per element + one whole-page "Gesamte Seite"), each with its own duration — all attributed to the requesting BE user (the queue job carries `be_user`). Note: logs are *buffered* during the run and written only after the report row exists (so `report_uid` can be set); a failed run logs its calls with `report_uid = 0`. Cost is **USD** (`cost_usd`), taken from OpenRouter's reported `usage.cost` (the client sets `usage.include=true` to get it); 0 if a backend omits it.

### Backend modules

**One** module under Web — **KI-Lektorat** (`ReviewModuleController`), with a docheader **view-switcher dropdown** (`MenuRegistry`, `?view=…`) of three views:
- *Aktueller Report* (`current`): status + docheader actions (Report erstellen / Neuen Report erstellen, Als geprüft markieren, Seiteninhalt bearbeiten → `web_layout`) + the latest run's report (a `<details>` — **open** unless the page is geprüft, then collapsed) + a descendant-pages table. `?reportUid=` opens a specific historical run (always expanded).
- *Report-Verlauf* (`reports`): the page's report runs (date · user · model · counts · cost); each row opens that run in *Aktueller Report*.
- *API-Verlauf* (`history`): the per-call audit log (technical; not for editors).
- **Default landing depends on status:** no explicit `?view=` → geprüft pages open on *Report-Verlauf*, others on *Aktueller Report*. Every dropdown item carries an explicit `view=` so a manual pick overrides that default. Run/mark are tokenized GET actions that **redirect (PRG)** to the clean URL.

Registered in `Configuration/Backend/Modules.php` (v12/13) and `ext_tables.php` (v11 fallback). All in-module links are built with `UriBuilder` (tokenized) — a bare `?logUid=…` href would drop the module token *and* the view.

## Conventions specific here

- Config lives in `ext_conf_template.txt`, flat camelCase keys grouped by `# cat=` (`backend`, `provider`, `categories`). Reads go through the injectable **`ExtensionSettings::get()`** service (swallows the missing-key exception → null so callers default); each consumer keeps a thin `config()` that delegates to it. The `Service\PageTreeService` (QueryBuilder BFS, permission-filtered) resolves subtree page UIDs for the module's descendant list and the bulk command — it replaced the `@internal` `PageTreeView`.
- **Toggling `useMock` requires a TYPO3 cache flush** — the factory's interface binding is resolved at container-build time (true of any Services.yaml-driven behavior).
- Tables: `tx_aiproofread_report` (report history), `tx_aiproofread_review` (per-page sign-off), `tx_aiproofread_log` (per-call audit), `tx_aiproofread_queue` (run queue). No TCA (accessed via QueryBuilder only).

## General conventions

This is a TYPO3 CMS extension. Frontend/UI language is **German**; code, comments and identifiers are **English**.

- **PHP 8.2+**, `declare(strict_types=1)` in every file.
- **Constructor injection** for dependencies — no `GeneralUtility::makeInstance()` for injectable services, no `inject*` methods. Register listeners/tagged services in `Configuration/Services.yaml`.
- Prefer **QueryBuilder** over raw SQL; use **`EXT:`** path syntax for resources; do not use `TYPO3\CMS\Extbase\Domain\Model\FrontendUser` (removed in v12).
- Enums (PHP 8.1+) are used for fixed vocabularies (`Enum\Category`, `Enum\ReviewStatus`).
- **No backend JavaScript at all** — the UI is server-rendered Fluid + docheader links. (The earlier context-menu/modal JS, and the v11 RequireJS cache-busting headache it brought, are gone.)
- Verify changes with `php -l` for syntax. Running tests/behavior needs a TYPO3 host (none is bundled in this repo); install into a TYPO3 v11–v13 instance via Composer (`bmorg/ai_proofread`).
