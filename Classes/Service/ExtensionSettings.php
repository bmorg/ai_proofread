<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Thin accessor for this extension's configuration (ext_conf_template.txt).
 *
 * Centralizes the one bit of shared logic the consumers need: read a key and
 * swallow the "key not set" exception, returning null so callers can apply their
 * own defaults.
 *
 * It is also the single chokepoint where the active model preset is applied: the
 * provider/model params (model, reasoning, …) live in {@see \Bmorg\AiProofread\Enum\ModelPreset}
 * rather than ext config, so a key the active preset pins is answered from the
 * preset and everything else falls through to the base ext config. Because every
 * consumer already reads through here, none of them needs to know about presets.
 */
final class ExtensionSettings
{
    private const EXTENSION_KEY = 'ai_proofread';

    /**
     * Runtime fallback for an ext-config key whose default is NOT the type's zero
     * value — currently just `baseUrl`. ExtensionConfiguration::get() does not apply
     * ext_conf_template.txt defaults at read time (they are persisted only on
     * install-sync or when the admin saves the Extension Configuration form), so a
     * key absent from the stored config throws a missing-key exception; without this
     * fallback `baseUrl` would collapse to null instead of its template default.
     *
     * Scope — fires only for the narrow "key absent from stored config" edge (the
     * template gained the key after the extension was configured with no sync since,
     * or the config was never synced at all). A fresh, synced install returns the
     * persisted template default and never consults this; an explicit stored value
     * (including a zero) wins, since sync merges only missing keys. A key whose
     * template default IS the type's zero value needs no entry — the inline `?? 0` /
     * `?? ''` reproduces it. Keep this in sync with ext_conf_template.txt.
     *
     * (The prompt/content keys are not here — they are authoritative in
     * {@see PromptSettings}, which carries their shipped defaults.)
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        'baseUrl' => 'https://openrouter.ai/api/v1',
    ];

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ActivePreset $activePreset,
        private readonly PromptSettings $promptSettings,
    ) {
    }

    /**
     * The configured value for $key, resolved through the overlay stack: the active
     * model preset first (it pins the model-shaping keys), then the prompt/content
     * settings admins manage in-module ({@see PromptSettings}), then the base ext
     * config. A key that is unset/unreadable returns its {@see DEFAULTS} entry, or
     * null when it has none (so callers can apply their own default).
     */
    public function get(string $key): mixed
    {
        $presetSettings = $this->activePreset->effectiveSettings();
        if (\array_key_exists($key, $presetSettings)) {
            return $presetSettings[$key];
        }

        // Prompt/content settings are authoritative for their four keys ({@see
        // PromptSettings::DEFAULTS}), answered here without touching ext config.
        $promptSettings = $this->promptSettings->values();
        if (\array_key_exists($key, $promptSettings)) {
            return $promptSettings[$key];
        }

        try {
            return $this->extensionConfiguration->get(self::EXTENSION_KEY, $key);
        } catch (\Throwable) {
            return self::DEFAULTS[$key] ?? null;
        }
    }
}
