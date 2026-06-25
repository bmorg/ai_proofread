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
 */
final class ExtensionSettings
{
    private const EXTENSION_KEY = 'ai_proofread';

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    /**
     * The configured value for $key, or null if unset/unreadable.
     */
    public function get(string $key): mixed
    {
        try {
            return $this->extensionConfiguration->get(self::EXTENSION_KEY, $key);
        } catch (\Throwable) {
            return null;
        }
    }
}
