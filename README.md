# AI Proof Reader for Typo3 (`ai_proofread`)

*An exercise in vibe-coding. Fully developed by Claude Code / Opus 4.8 - heavily guided and reviewed, but almost no code was handwritten.*

AI-assisted proofreading for TYPO3 page content. Detects issues
like spelling, punctuation, grammar, gender-inclusive language, style.
Results are shown in a dedicated report.

> **Status: 0.1.0 alpha.** Works and is being used in production, but has **not** been widely tested.
> See "Limitations" below.
> If you are using this, please report any issues!

## Limitations

This is an early exploratory version with serious limitations:

- **German content only.** The A.I. prompt was optimized over multiple iterations for German language content only!
- **Single backend: OpenRouter** The general idea is to support generic OpenAI-compatible backends, but I have not been able to test this with anything other than OpenRouter yet. There can be serious limitations with availability and strictness of the required JSON output format that need to be tested.
- **Single page language only.** So far, only l=0 can be checked.
- Only **tested** on Typo3 11.

`## How it works

- Access the new module **"KI-Lektorat"** from any page:
  ![KI-Lektorat module in document header](.github/images/docheader.png)
- Checks need to be scheduled ("Report erstellen"). Depending on settings the check can take multiple minutes.
  ![Schedule check](.github/images/schedule-check.png)
- When ready, the report is automatically shown:
  ![Generated report view](.github/images/report.png)

## Installation

- **TYPO3:** 11.5 / 12.4 / 13.4 · **PHP:** 8.2+

```bash
composer require bmorg/ai_proofread
```

Or simply download via "Releases" here on Github.

In the TYPO3 backend:

1. **Database migration** Via Admin Tools → Maintenance → Analyze Database Structure
2. Extension configuration (see below).
3. **Scheduler task*** Register the command **`aiproofread:process-queue`** as a recurring Scheduler task (every 60s).

## Configuration

Extension configuration (Admin Tools → Settings → Extension Configuration →
`ai_proofread`):

Fill in at least the provider settings. Recommended

| Setting | Value                                                                                   |
|---|-----------------------------------------------------------------------------------------------|
| `baseUrl` | `https://openrouter.ai/api/v1`                                                        |
| `apiKey` | `sk-or-…`                                                                              |
| `model` | `anthropic/claude-opus-4.8`                                                             |
| `providerOrder` | `anthropic` - when routed to Google, I've had problems with the structured JSON |
| `allowFallbacks` | `0` - pins strictly to `providerOrder`                                         |
| `reasoning` | `1` - more expensive, but far better results                                        |

## License

MIT — see [LICENSE](LICENSE).
