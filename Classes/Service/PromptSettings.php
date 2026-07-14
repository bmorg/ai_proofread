<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service;

use TYPO3\CMS\Core\Registry;

/**
 * The site-wide prompt/content settings — the four keys that shape *what* the
 * model is asked to do (site description, site-specific rules, gender-inclusive
 * policy), as opposed to the install/infrastructure keys (API key, endpoint,
 * timeouts, mock) in ext config.
 *
 * Held in the TYPO3 registry (sys_registry), mutable at runtime with no cache
 * flush, and edited by the module's admin-only "Einstellungen" view — the same
 * store and view as {@see ActivePreset}, so a content admin manages them where they
 * pick the model rather than in the Install Tool.
 *
 * This store is authoritative for its four keys: {@see values()} always returns all
 * of them — the saved values, or the shipped {@see DEFAULTS} when nothing has been
 * saved — and {@see ExtensionSettings::get()} answers these keys from here without
 * consulting ext config.
 */
final class PromptSettings
{
    private const NAMESPACE = 'ai_proofread';
    private const KEY = 'promptSettings';

    /**
     * The four keys and their shipped defaults. Keys match the camelCase the
     * consumers already read via {@see ExtensionSettings::get()}, so nothing
     * downstream changes.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'siteDescription' => '',
        'extraPromptInstructions' => '',
        'enableGenderInclusiveLanguage' => true,
        'genderInclusiveStyle' => 'Doppelpunkt (z.B. Nutzer:innen)',
    ];

    public function __construct(
        private readonly Registry $registry,
    ) {
    }

    /**
     * The effective prompt/content settings: the saved values, or the shipped
     * {@see DEFAULTS} where nothing has been saved. Always returns all four keys,
     * normalized — {@see ExtensionSettings} overlays this ahead of ext config.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $stored = $this->registry->get(self::NAMESPACE, self::KEY);

        return $this->normalize(\is_array($stored) ? $stored : []);
    }

    /**
     * @param array<string, mixed> $raw
     */
    public function save(array $raw): void
    {
        $this->registry->set(self::NAMESPACE, self::KEY, $this->normalize($raw));
    }

    /**
     * Coerce the four keys to their expected types, filling any absent key from
     * {@see DEFAULTS} — so a never-saved store, a partial write or legacy data all
     * resolve to a complete, well-typed set (an absent gender flag defaults to on,
     * not silently off).
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalize(array $raw): array
    {
        $v = array_merge(self::DEFAULTS, array_intersect_key($raw, self::DEFAULTS));

        return [
            'siteDescription' => trim((string)$v['siteDescription']),
            'extraPromptInstructions' => trim((string)$v['extraPromptInstructions']),
            'enableGenderInclusiveLanguage' => (bool)$v['enableGenderInclusiveLanguage'],
            'genderInclusiveStyle' => trim((string)$v['genderInclusiveStyle']),
        ];
    }
}
