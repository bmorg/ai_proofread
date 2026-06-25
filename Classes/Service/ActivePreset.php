<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service;

use Bmorg\AiProofread\Enum\ModelPreset;
use TYPO3\CMS\Core\Registry;

/**
 * The globally-active model preset, plus the values backing the "Benutzerdefiniert"
 * (custom) preset.
 *
 * Both are single site-wide values held in the TYPO3 registry (sys_registry) —
 * mutable at runtime, no cache flush, no new table or TCA. The selection and the
 * custom values are read on every run (overlaid by {@see ExtensionSettings} via
 * {@see effectiveSettings()}) and written by the module's admin-only "Einstellungen"
 * view.
 */
final class ActivePreset
{
    private const NAMESPACE = 'ai_proofread';
    private const KEY = 'activePreset';
    private const CUSTOM_KEY = 'customSettings';

    public function __construct(
        private readonly Registry $registry,
    ) {
    }

    /**
     * The active preset, or the default when none has been selected (or the
     * stored one no longer exists).
     */
    public function current(): ModelPreset
    {
        $stored = $this->registry->get(self::NAMESPACE, self::KEY);

        return ModelPreset::fromKey(\is_string($stored) ? $stored : null);
    }

    public function set(ModelPreset $preset): void
    {
        $this->registry->set(self::NAMESPACE, self::KEY, $preset->value);
    }

    /**
     * The settings the active preset effectively pins — the shipped preset's own
     * {@see ModelPreset::settings()}, or the admin-entered custom values when the
     * active preset is {@see ModelPreset::Custom}. This is what {@see ExtensionSettings}
     * overlays onto the base ext config.
     *
     * @return array<string, mixed>
     */
    public function effectiveSettings(): array
    {
        return $this->current() === ModelPreset::Custom
            ? $this->customSettings()
            : $this->current()->settings();
    }

    /**
     * The stored custom model settings (all five model-shaping keys), normalized.
     * Defaults to the default preset's settings when nothing has been saved yet, so
     * an un-edited Custom behaves like the shipped default rather than firing with an
     * empty model, and the edit form is pre-filled with a sane template.
     *
     * @return array<string, mixed>
     */
    public function customSettings(): array
    {
        $stored = $this->registry->get(self::NAMESPACE, self::CUSTOM_KEY);

        return $this->normalize(\is_array($stored) ? $stored : ModelPreset::default()->settings());
    }

    /**
     * @param array<string, mixed> $raw
     */
    public function setCustom(array $raw): void
    {
        $this->registry->set(self::NAMESPACE, self::CUSTOM_KEY, $this->normalize($raw));
    }

    /**
     * Coerce the five model-shaping keys to their expected types/whitelist, so both
     * writes and reads of partial or legacy data are safe.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalize(array $raw): array
    {
        $structuredOutput = (string)($raw['structuredOutput'] ?? '');
        if (!\in_array($structuredOutput, ['json_schema', 'json_object', 'prompt'], true)) {
            $structuredOutput = 'json_schema';
        }

        return [
            'model' => trim((string)($raw['model'] ?? '')),
            'reasoning' => (bool)($raw['reasoning'] ?? false),
            'maxTokens' => max(0, (int)($raw['maxTokens'] ?? 0)),
            'structuredOutput' => $structuredOutput,
            'pinProvider' => trim((string)($raw['pinProvider'] ?? '')),
        ];
    }
}
