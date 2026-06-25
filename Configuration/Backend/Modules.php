<?php

use Bmorg\AiProofread\Controller\ReviewModuleController;

/**
 * Backend module registration for TYPO3 v12/v13.
 * On v11 the module is registered in ext_tables.php (no Modules.php support).
 *
 * A single module with an in-module view switcher
 * (Aktueller Report, Report-Verlauf, API-Verlauf).
 */
return [
    'web_aiproofread' => [
        'parent' => 'web',
        'position' => ['after' => 'web_info'],
        'access' => 'user',
        'iconIdentifier' => 'module-aiproofread',
        'path' => '/module/web/aiproofread',
        'labels' => 'LLL:EXT:ai_proofread/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => ReviewModuleController::class . '::handleRequest',
            ],
        ],
    ],
];
