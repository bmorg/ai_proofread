<?php

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

/**
 * Icon registration (auto-loaded from TYPO3 v11.4+).
 */
return [
    // Full colourful badge — used for the backend module entries.
    'module-aiproofread' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:ai_proofread/Resources/Public/Icons/module-aiproofread.svg',
    ],
    // Robot only (no background) — used for the docheader button in other modules.
    'aiproofread-robot' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:ai_proofread/Resources/Public/Icons/robot.svg',
    ],
];
