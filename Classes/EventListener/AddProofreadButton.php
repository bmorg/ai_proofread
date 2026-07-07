<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\EventListener;

use Bmorg\AiProofread\Enum\ReviewStatus;
use Bmorg\AiProofread\Service\QueueRepository;
use Bmorg\AiProofread\Service\ReportRepository;
use Bmorg\AiProofread\Service\ReviewRepository;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Adds a "KI-Lektorat" link button to the docheader of the **Page** module
 * (`web_layout`), pointing at the proofreading module for the current page — so
 * editors reach it without a context menu.
 *
 * The icon is **state-aware**: a dedicated robot variant per state plus a
 * matching tooltip tell the editor at a glance whether the page needs anything
 * — see {@see decorationFor()} for the state model.
 * A failed run is otherwise invisible outside the module, and a just-started
 * run gets its "still working / now ready" feedback right in the Page module.
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

        // Dependencies are resolved here, not constructor-injected: the v11
        // getButtonsHook instantiates this class bare (no DI), so the class must
        // stay constructor-less. All three repositories only need ConnectionPool.
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queue = new QueueRepository($pool);
        $reports = new ReportRepository($pool);
        $reviews = new ReviewRepository($pool);

        $job = $queue->findForPage($pageId);
        $status = $reviews->deriveStatus($reports->findLatestByPage($pageId), $reviews->findByPage($pageId));
        $decoration = $this->decorationFor($job !== null ? (string)$job['status'] : null, $status);

        return $buttonBar->makeLinkButton()
            ->setHref((string)$uriBuilder->buildUriFromRoute('web_aiproofread', ['id' => $pageId]))
            ->setTitle($decoration['title'])
            ->setIcon($iconFactory->getIcon($decoration['icon'], Icon::SIZE_SMALL));
    }

    /**
     * The icon variant + tooltip for a page's proofreading state, so an editor
     * sees "does this page need anything from me?" without opening the module.
     * The variants are dedicated robot SVGs (Configuration/Icons.php) — a badge
     * baked into the icon reads better at docheader size than a core overlay.
     *
     * Queue states take precedence over the review status (a geprüft page can
     * have a new run queued): in progress (clock) beats failed (warning) beats
     * the review status — geprüft (check), report waiting for review (info),
     * never checked (plain robot).
     *
     * @internal public only so the state→decoration truth table is unit-testable;
     *   the entry points are __invoke()/getButtons().
     *
     * @param string|null $queueStatus the page's queue row status, or null without one
     * @return array{icon: string, title: string}
     */
    public function decorationFor(?string $queueStatus, ReviewStatus $status): array
    {
        if ($queueStatus === QueueRepository::PENDING || $queueStatus === QueueRepository::RUNNING) {
            return ['icon' => 'aiproofread-robot-clock', 'title' => 'KI-Lektorat – Report wird erstellt …'];
        }
        if ($queueStatus === QueueRepository::ERROR) {
            return ['icon' => 'aiproofread-robot-warning', 'title' => 'KI-Lektorat – letzte Prüfung fehlgeschlagen'];
        }

        return match ($status) {
            ReviewStatus::Proofed => ['icon' => 'aiproofread-robot-check', 'title' => 'KI-Lektorat – geprüft'],
            ReviewStatus::ReportCreated => ['icon' => 'aiproofread-robot-info', 'title' => 'KI-Lektorat – Report wartet auf Prüfung'],
            ReviewStatus::Unchecked => ['icon' => 'aiproofread-robot', 'title' => 'KI-Lektorat'],
        };
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
