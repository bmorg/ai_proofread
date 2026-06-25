<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\EventListener;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Adds a "KI-Lektorat" link button to the docheader of the **Page** module
 * (`web_layout`), pointing at the proofreading module for the current page — so
 * editors reach it without a context menu.
 *
 * Two entry points, one per TYPO3 era, with a version guard so they never both
 * add the button:
 *  - v12/13: PSR-14 ModifyButtonBarEvent (__invoke), registered in Services.yaml.
 *  - v11:    legacy ButtonBar getButtonsHook (getButtons), registered in ext_localconf.php.
 *
 * Shown only on the Page module when a page is selected (`id` present).
 */
final class AddProofreadButton
{
    /**
     * PSR-14 event listener (TYPO3 v12/13).
     */
    public function __invoke(ModifyButtonBarEvent $event): void
    {
        if ((new Typo3Version())->getMajorVersion() < 12) {
            return; // v11 goes through the getButtonsHook below instead.
        }
        $button = $this->buildButton($event->getButtonBar());
        if ($button === null) {
            return;
        }
        $buttons = $event->getButtons();
        $buttons[ButtonBar::BUTTON_POSITION_LEFT][5][] = $button;
        $event->setButtons($buttons);
    }

    /**
     * Legacy ButtonBar hook (TYPO3 v11).
     *
     * @param array{buttons: array<string, array<int, array<int, mixed>>>} $params
     * @return array<string, array<int, array<int, mixed>>>
     */
    public function getButtons(array $params, ButtonBar $buttonBar): array
    {
        $buttons = $params['buttons'] ?? [];
        $button = $this->buildButton($buttonBar);
        if ($button !== null) {
            $buttons[ButtonBar::BUTTON_POSITION_LEFT][5][] = $button;
        }
        return $buttons;
    }

    private function buildButton(ButtonBar $buttonBar): ?LinkButton
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return null;
        }
        // Only on the Page module (web_layout) with a page selected.
        $pageId = (int)($request->getQueryParams()['id'] ?? 0);
        if ($pageId <= 0 || $this->currentModuleIdentifier($request) !== 'web_layout') {
            return null;
        }

        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);

        return $buttonBar->makeLinkButton()
            ->setHref((string)$uriBuilder->buildUriFromRoute('web_aiproofread', ['id' => $pageId]))
            ->setTitle('KI-Lektorat')
            ->setIcon($iconFactory->getIcon('aiproofread-robot', Icon::SIZE_SMALL));
    }

    private function currentModuleIdentifier(ServerRequestInterface $request): string
    {
        // v12/13 expose a Module object; v11 exposes a Route.
        $module = $request->getAttribute('module');
        if (\is_object($module) && method_exists($module, 'getIdentifier')) {
            return (string)$module->getIdentifier();
        }
        $route = $request->getAttribute('route');
        if (\is_object($route) && method_exists($route, 'getOption')) {
            return (string)($route->getOption('_identifier') ?? '');
        }
        return '';
    }
}
