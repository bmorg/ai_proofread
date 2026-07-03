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
            // Match the v12/13 Modules.php value ('user'); 'user,group' is legacy and
            // not a valid access keyword in the v12/13 module format.
            'access' => 'user',
            'name' => 'web_aiproofread',
            'iconIdentifier' => 'module-aiproofread',
            'labels' => 'LLL:EXT:ai_proofread/Resources/Private/Language/locallang_mod.xlf',
        ]
    );
}
