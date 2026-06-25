<?php

defined('TYPO3') or die();

use TYPO3\CMS\Core\Information\Typo3Version;

// TYPO3 v11 modifies the docheader via the legacy ButtonBar hook (the PSR-14
// ModifyButtonBarEvent used on v12/13 is registered in Services.yaml). Register
// the hook here so the "KI-Lektorat" button appears on v11.
if ((new Typo3Version())->getMajorVersion() < 12) {
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['Backend\\Template\\Components\\ButtonBar']['getButtonsHook'][]
        = \Bmorg\AiProofread\EventListener\AddProofreadButton::class . '->getButtons';
}
