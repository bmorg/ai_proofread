<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Enum;

/**
 * A shipped, curated set of model presets — a named bundle of the settings that
 * change together when you swap models (model id, reasoning, token budget,
 * structured-output mode, provider routing).
 *
 * A preset is the single source of truth for those params: the matching keys were
 * removed from ext_conf_template.txt, and {@see \Bmorg\AiProofread\Service\ExtensionSettings}
 * answers them from the active preset's {@see settings()}, falling back to the
 * remaining base config (baseUrl/apiKey/timeout/…) only for keys a preset does
 * not name. The globally-active preset is held in {@see \Bmorg\AiProofread\Service\ActivePreset}
 * and switched from the module's admin-only "Einstellungen" view.
 *
 * Adding a shipped model = adding a case here (curated set). The slugs are OpenRouter
 * model ids — verify they are ones the provider actually serves before relying on a
 * new one (dead slugs 404 when fallbacks are pinned off; see CLAUDE.md → "OpenRouter
 * can route to a provider that ignores response_format").
 *
 * The {@see Custom} case is the one exception to "curated only": it pins nothing here,
 * and its effective params are the admin-entered values held in
 * {@see \Bmorg\AiProofread\Service\ActivePreset} (Registry), edited in the same view.
 *
 * Roster last validated 2026-07 against the measured comparisons in
 * model-experiments/experiments/ (dev-only, not shipped): Opus 4.8 =
 * zero-false-positive default; GPT-5.6 Sol = deepest affordable recall;
 * Fable 5 = Sol-level recall at near-Opus cleanliness, ~3x the price;
 * GPT-5.6 Luna = budget tier (~$0.13/check at solid recall — earned the slot
 * in the 2026-07 imperative-extras experiment). Dropped on evidence: Sonnet
 * 4.6 (silent near-empty reports), GLM 5.2 (unparseable output under free
 * routing), Qwen 3.7 Plus (weak recall, non-verbatim quotes), Gemini 3.5
 * Flash (never cheap in practice — reasoning cost dominates — and dominated
 * by better models at equal cost). Removed cases resolve to the default via
 * {@see fromKey()}.
 */
enum ModelPreset: string
{
    case Opus48 = 'opus-4.8';
    case GPT56Sol = 'gpt-5.6-sol';
    case Fable5 = 'fable-5';
    case GPT56Luna = 'gpt-5.6-luna';
    case Custom = 'custom';

    /**
     * German label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Opus48 => 'Claude Opus 4.8 · Reasoning (Anthropic)',
            self::GPT56Sol => 'GPT 5.6 Sol · Reasoning (OpenAI)',
            self::Fable5 => 'Claude Fable 5 · Reasoning (Anthropic)',
            self::GPT56Luna => 'GPT 5.6 Luna · Reasoning (OpenAI)',
            self::Custom => 'Benutzerdefiniert',
        };
    }

    /**
     * The settings this preset pins, keyed by ext-config key. Anything not
     * listed here falls through to the base ext config.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return match ($this) {
            self::Opus48 => [
                'model' => 'anthropic/claude-opus-4.8',
                'reasoning' => true,
                'maxTokens' => 0, // auto
                'structuredOutput' => 'json_schema',
                'pinProvider' => 'anthropic',
            ],
            self::GPT56Sol => [
                'model' => 'openai/gpt-5.6-sol',
                'reasoning' => true,
                'maxTokens' => 0, // auto
                'structuredOutput' => 'json_schema',
                'pinProvider' => '',
            ],
            self::Fable5 => [
                'model' => 'anthropic/claude-fable-5',
                'reasoning' => true,
                'maxTokens' => 0, // auto
                'structuredOutput' => 'json_schema',
                'pinProvider' => 'anthropic',
            ],
            self::GPT56Luna => [
                'model' => 'openai/gpt-5.6-luna',
                'reasoning' => true,
                'maxTokens' => 0, // auto
                'structuredOutput' => 'json_schema',
                'pinProvider' => '',
            ],
            // Pins nothing: the effective values are the admin-entered ones held in
            // ActivePreset (Registry), resolved via ActivePreset::effectiveSettings().
            self::Custom => [],
        };
    }

    /**
     * The preset used when nothing is selected — preserves the out-of-the-box
     * behaviour (Opus 4.8 with reasoning).
     */
    public static function default(): self
    {
        return self::Opus48;
    }

    /**
     * Resolve a stored/submitted key to a case, tolerating null or an unknown
     * value (e.g. a preset removed in a later release) by falling back to the
     * default — the active model must never be undefined.
     */
    public static function fromKey(?string $key): self
    {
        return ($key !== null ? self::tryFrom($key) : null) ?? self::default();
    }
}
