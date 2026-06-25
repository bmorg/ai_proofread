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
            'access' => 'user,group',
            'name' => 'web_aiproofread',
            'iconIdentifier' => 'module-aiproofread',
            'labels' => 'LLL:EXT:ai_proofread/Resources/Private/Language/locallang_mod.xlf',
        ]
    );
}
