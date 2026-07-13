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
     * Runtime fallbacks for keys whose default is NOT the type's zero value (here:
     * categories that are on by default). ExtensionConfiguration::get() does not
     * apply ext_conf_template.txt defaults at read time — they are persisted only on
     * install-sync or when the admin saves the Extension Configuration form — so a
     * key absent from the stored config throws a missing-key exception. Without a
     * fallback that collapses to null → (bool) false and silently disables the
     * category despite its template default of 1.
     *
     * Scope — this fires only for the narrow "key absent from stored config" edge:
     *   - the option was added to the template after the extension was already
     *     configured, and no sync/save has run since (stored array lacks the key); or
     *   - the extension is loaded but its config was never synced/saved at all.
     * It does NOT affect a fresh, properly-installed instance (install-sync writes the
     * template default, so get() returns that and never consults this), nor an install
     * that already persisted the key — including a stored 0: an explicit "off" wins,
     * because sync merges only missing keys and never overwrites an existing value. So
     * this is a safety net for the missing-key edge, not the general default mechanism
     * — the template is. A key whose template default IS the type's zero value (0, '',
     * false) does not belong here: its inline `?? 0` / `?? ''` fallback already
     * reproduces the template default, so there is nothing to mirror.
     *
     * Entries mirror the non-zero defaults in ext_conf_template.txt; keep the two in
     * sync (the template drives the admin form + install-sync, this drives the runtime
     * read of an absent key).
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
        'baseUrl' => 'https://openrouter.ai/api/v1',
        'enableGenderInclusiveLanguage' => true,
        'genderInclusiveStyle' => 'Doppelpunkt (z.B. Nutzer:innen)',
    ];

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ActivePreset $activePreset,
    ) {
    }

    /**
     * The configured value for $key: the active preset's value when it pins this
     * key, otherwise the base ext-config value. A key that is unset/unreadable
     * returns its {@see DEFAULTS} entry, or null when it has none (so callers can
     * apply their own default).
     */
    public function get(string $key): mixed
    {
        $presetSettings = $this->activePreset->effectiveSettings();
        if (\array_key_exists($key, $presetSettings)) {
            return $presetSettings[$key];
        }

        try {
            return $this->extensionConfiguration->get(self::EXTENSION_KEY, $key);
        } catch (\Throwable) {
            return self::DEFAULTS[$key] ?? null;
        }
    }
}
