<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Bmorg\AiProofread\Service\ContentExtractor;
use Bmorg\AiProofread\Service\PageTreeService;
use Bmorg\AiProofread\Service\ProofreadingService;
use Bmorg\AiProofread\Service\ReportRepository;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Proofread every content element under a page-tree subtree, in batches.
 *
 * LLM calls are slow and cost money, so bulk runs belong here (queued via the
 * TYPO3 Scheduler or run from the CLI) rather than synchronously in the module.
 * Unchanged pages that already carry a current report are skipped (content
 * hash), so a re-run only spends tokens on what actually changed.
 *
 * Registered as a console command in Configuration/Services.yaml.
 *
 * Known limitation: a page whose run throws (one failed LLM pass) aborts the
 * whole command — the remaining pages are never processed and the summary never
 * prints. Should be a per-page catch-report-continue, like ProcessQueueCommand
 * does per job. Note also that this command is entirely untested: no automated
 * coverage, and unlike the queue path it has not been exercised in production.
 */
final class ProofreadSubtreeCommand extends Command
{
    public function __construct(
        private readonly ProofreadingService $proofreadingService,
        private readonly ContentExtractor $extractor,
        private readonly ReportRepository $reports,
        private readonly PageTreeService $pageTree,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('AI-proofread all content elements under a page-tree subtree.')
            ->addArgument('rootPage', InputArgument::REQUIRED, 'Root page uid of the subtree')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Re-check elements even if their report is still current');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Bootstrap::initializeBackendAuthentication();
        $beUser = $GLOBALS['BE_USER'] ?? GeneralUtility::makeInstance(CommandLineUserAuthentication::class);
        $beUserId = (int)($beUser->user['uid'] ?? 0);

        $io = new SymfonyStyle($input, $output);
        $rootPage = (int)$input->getArgument('rootPage');
        $force = (bool)$input->getOption('force');

        // Single-language scope (0.1): the read/UI paths are not language-aware, so
        // the whole extension operates on the default language only. See README.
        $language = 0;

        $checked = 0;
        $skipped = 0;
        foreach ($this->pageTree->descendantPageUids($rootPage) as $pageUid) {
            $text = $this->extractor->extractPageText($pageUid, $language);
            if ($text === '') {
                continue;
            }
            if (!$force && $this->isCurrent($pageUid, $this->extractor->hash($text))) {
                $skipped++;
                continue;
            }
            $this->proofreadingService->proofreadPage($pageUid, $beUserId, $language);
            $checked++;
        }

        $io->success(sprintf('Proofread %d page(s), skipped %d unchanged.', $checked, $skipped));
        return Command::SUCCESS;
    }

    private function isCurrent(int $pageUid, string $hash): bool
    {
        $latest = $this->reports->findLatestByPage($pageUid);
        return $latest !== null && (string)($latest['content_hash'] ?? '') === $hash;
    }
}
