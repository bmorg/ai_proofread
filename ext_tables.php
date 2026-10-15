<?php

defined('TYPO3') or die();

use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

// TYPO3 v11 has no Configuration/Backend/Modules.php — register the backend
// module the legacy way. v12/v13 use Modules.php and skip this block.
if ((new Typo3Version())->getMajorVersion() < 12) {
    ExtensionManagementUtility::addModule(
        'web',
        'aiproofread',
        'after:info',
        null,
        [
            'routeTarget' => \Bmorg\AiProofread\Controller\ReviewModuleController::class . '::handleRequest',
            // v11's ModuleLoader lists a module in the be_users module list only for
            // 'user' and in the be_groups list only for 'group' — so 'user' alone makes
            // the module assignable to users but not groups. v12/13's Modules.php
            // 'user' covers both (and 'user,group' isn't valid there); this block is v11-only.
            'access' => 'user,group',
            'name' => 'web_aiproofread',
            'iconIdentifier' => 'module-aiproofread',
            'labels' => 'LLL:EXT:ai_proofread/Resources/Private/Language/locallang_mod.xlf',
        ]
    );
}
