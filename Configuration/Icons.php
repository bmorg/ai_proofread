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
    // State variants of the docheader robot (see AddProofreadButton::decorationFor):
    // a run is queued/running, the last run failed, a report awaits review, page
    // is signed off.
    'aiproofread-robot-clock' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:ai_proofread/Resources/Public/Icons/robot-clock.svg',
    ],
    'aiproofread-robot-warning' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:ai_proofread/Resources/Public/Icons/robot-warning.svg',
    ],
    'aiproofread-robot-info' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:ai_proofread/Resources/Public/Icons/robot-info.svg',
    ],
    'aiproofread-robot-check' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:ai_proofread/Resources/Public/Icons/robot-check.svg',
    ],
];
