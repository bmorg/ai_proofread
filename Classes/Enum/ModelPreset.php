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
 */
enum ModelPreset: string
{
    case Opus48 = 'opus-4.8';
    case GPT55 = 'gpt-5.5';
    case Qwen37Plus = 'qwen-3.7-plus';
    case GLM52 = 'glm-5.2';
    case Gemini35Flash = 'gemini-3.5-flash';
    case Opus48NoReasoning = 'opus';
    case Sonnet46 = 'sonnet-4.6';
    case Custom = 'custom';

    /**
     * German label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Opus48 => 'Claude Opus 4.8 · Reasoning (Anthropic)',
            self::GPT55 => 'GPT 5.5 · Reasoning (OpenAI)',
            self::Qwen37Plus => 'Qwen 3.7 Plus (Alibaba)',
            self::GLM52 => 'GLM 5.2 (Z.ai)',
            self::Gemini35Flash => 'Gemini 3.5 Flash (Google)',
            self::Opus48NoReasoning => 'Claude Opus 4.8 · No Reasoning (Anthropic)',
            self::Sonnet46 => 'Claude Sonnet 4.6 · Reasoning (Anthropic)',
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
            self::GPT55 => [
                'model' => 'openai/gpt-5.5',
                'reasoning' => true,
                'maxTokens' => 0, // auto
                'structuredOutput' => 'json_schema',
                'pinProvider' => '',
            ],
            self::Qwen37Plus => [
                'model' => 'qwen/qwen3.7-plus',
                'reasoning' => true,
                'maxTokens' => 0, // auto
                'structuredOutput' => 'json_schema',
                'pinProvider' => '',
            ],
            self::GLM52 => [
                'model' => 'z-ai/glm-5.2',
                'reasoning' => true,
                'maxTokens' => 0, // auto
                'structuredOutput' => 'json_schema',
                'pinProvider' => '',
            ],
            self::Gemini35Flash => [
                'model' => 'google/gemini-3.5-flash',
                'reasoning' => true,
                'maxTokens' => 0, // auto
                'structuredOutput' => 'json_schema',
                'pinProvider' => '',
            ],
            self::Opus48NoReasoning => [
                'model' => 'anthropic/claude-opus-4.8',
                'reasoning' => false,
                'maxTokens' => 0, // auto
                'structuredOutput' => 'json_schema',
                'pinProvider' => 'anthropic',
            ],
            self::Sonnet46 => [
                'model' => 'anthropic/claude-sonnet-4.6',
                'reasoning' => true,
                // Sonnet is a verbose reasoner that has exhausted lower budgets
                // and silently truncated the report (see CLAUDE.md); give it room.
                'maxTokens' => 48000,
                'structuredOutput' => 'json_schema',
                'pinProvider' => 'anthropic',
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
