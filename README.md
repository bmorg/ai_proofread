# AI Proof Reader for Typo3

AI-assisted proofreading for TYPO3 page content. Detects issues
like spelling, punctuation, grammar, gender-inclusive language, style.
Results are shown in a dedicated report, where suggestions can be
applied to the content with one click.

> **Status: alpha.** Works and is in production use on TYPO3 11, but has **not** been widely tested.
> See "Limitations" below.
> If you are using this, please report any issues!

## Limitations

This is an early exploratory version with quite some limitations:

- **German content only.** The A.I. prompt was optimized over multiple iterations for German language content.
- **Single backend: OpenRouter** The general idea is to support generic OpenAI-compatible backends, but I have not been able to test this with anything other than OpenRouter yet. The extension currently relies on a strict JSON output format.
- **Single page language only.** So far, only L=0 is checked.
- **No workspace support.** The extension reads and reports on live content only; workspaces are ignored and untested.
- Only **tested** on Typo3 11. Targets Typo3 11-13, but 12-13 are thus untested.

## How it works

- Access the new module **"KI-Lektorat"** from any page:

  <img src=".github/images/docheader.png" alt="KI-Lektorat module in document header" width="600"/>

- Checks need to be scheduled ("Report erstellen"). Depending on settings the check can take multiple minutes.

  <img src=".github/images/schedule-check.png" alt="Schedule check" width="600"/>

- When ready, the report is automatically shown:

  <img src=".github/images/report.png" alt="Generated report view" width="600"/>

- Findings can be auto-applied (where possible), marked as manually applied or discarded.
- When done, mark the report as "Geprüft" to update the header icon and statistics.

## Installation

- **TYPO3:** 11.5 / 12.4 / 13.4 · **PHP:** 8.2+

```bash
composer require bmorg/ai_proofread
```

Or download via [Github Releases](https://github.com/bmorg/ai_proofread/releases).

In the TYPO3 backend:

1. **Database tables** are created on install.
2. Adapt extension configuration (see below).
3. **Scheduler task** Register the command **`aiproofread:process-queue`** as a recurring Scheduler task (every 60s).

## Configuration

### 1. Extension settings

Admin Tools → Settings → Extension Configuration → `ai_proofread`

Fill in at least the provider settings. Recommended:

| Setting | Value                                                                                   |
|---|-----------------------------------------------------------------------------------------------|
| `baseUrl` | `https://openrouter.ai/api/v1`                                                        |
| `apiKey` | `sk-or-…`                                                                              |
| `requestTimeout` |                                                                                |
| `maxConcurrency` |                                                                                |
| `useMock` | `0`                                                                                   |


### 2. Model configuration (KI-Lektorat → Dropdown: Einstellungen)

#### Model selection

Ships with a set of preconfigured models.

The current prompt was tested against a set of ~25 frontier models with a sample page and scored against
a set of expected finds (+ false-positives).

| Preset | Findings                                                                |
|---|-------------------------------------------------------------------------|
| **Claude Opus 4.8** (default) | finds most errors; no false positives; sightly cheaper than Sol |
| **GPT 5.6 Sol** | found all must-finds; low false positives; costly                       |
| **Claude Fable 5** | found all must-finds; low false positives; prohibitively costly (~3× Sol) |
| **GPT 5.6 Luna** | finds most errors; few false positives; fast; cheap (0.2× Sol)          |

Recommendations:
  - **Reasoning on** gives much better results (slower and more expensive).
  - **Pin a provider** (e.g. `anthropic`) if the default routing lands on providers that e.g. don't return the required JSON.

#### Prompt config

| Setting | Value |
|---------|-------|
| `siteDescription` | For better context |
| `extraPromptInstructions` | Allows adding of custom rules |
| `enableGenderInclusiveLanguage` | `1` |
| `genderInclusiveStyle` | E.g. "Nutzer/innen" |

Recommendations:
  - When using `extraPromptInstructions`, use strict language, e.g. "Korrigiere NIE Anführungszeichen". Permissive wording ("kann ignoriert werden") will get ignored by some models.

## Development

This is an exercise in vibe-coding. Fully developed by Claude Code (Opus 4.8 and Fable 5) - heavily guided and reviewed, but almost no code was handwritten.

Contributions are welcome, although some form of human involvement is much encouraged.

## License

MIT — see [LICENSE](LICENSE).
