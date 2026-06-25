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

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ActivePreset $activePreset,
    ) {
    }

    /**
     * The configured value for $key: the active preset's value when it pins this
     * key, otherwise the base ext-config value (or null if unset/unreadable).
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
            return null;
        }
    }
}
