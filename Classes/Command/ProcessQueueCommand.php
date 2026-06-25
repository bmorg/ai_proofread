<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Command;

use Bmorg\AiProofread\Service\ProofreadingService;
use Bmorg\AiProofread\Service\QueueRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Drain the async run queue: each "Report erstellen" click enqueues a job, this
 * command processes the pending ones. A run is N+1 LLM requests (one per content
 * element + one whole-page), too slow to do inline — so the module enqueues and
 * this command, registered as a recurring Scheduler task, does the work.
 *
 * On success the job row is deleted (the stored report run is the result); on
 * failure it is kept with status "error" + message so the module can surface it.
 *
 * Registered (with schedulable: true) via the console.command tag in
 * Configuration/Services.yaml; schedule via the TYPO3 Scheduler.
 */
final class ProcessQueueCommand extends Command
{
    public function __construct(
        private readonly QueueRepository $queue,
        private readonly ProofreadingService $proofreadingService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Process pending AI-proofread jobs from the queue.')
            ->addOption('max', 'm', InputOption::VALUE_REQUIRED, 'Maximum number of jobs to process this run (0 = all pending)', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Bootstrap::initializeBackendAuthentication();
        $GLOBALS['BE_USER'] ??= GeneralUtility::makeInstance(CommandLineUserAuthentication::class);

        $io = new SymfonyStyle($input, $output);
        $max = (int)$input->getOption('max');

        // Recover jobs left "running" by a worker that died mid-run, so a page is
        // never wedged on "wird erstellt …" forever.
        $reclaimed = $this->queue->reclaimStaleRunning();
        if ($reclaimed > 0) {
            $io->writeln(sprintf('<comment>Reclaimed %d stale running job(s).</comment>', $reclaimed));
        }

        $processed = 0;
        $failed = 0;
        while (($job = $this->queue->claimNext()) !== null) {
            $uid = (int)$job['uid'];
            $pageUid = (int)$job['page_uid'];
            $languageUid = (int)$job['language_uid'];
            $beUserId = (int)$job['be_user'];

            try {
                $this->proofreadingService->proofreadPage($pageUid, $beUserId, $languageUid);
                $this->queue->delete($uid);
                $processed++;
                $io->writeln(sprintf('<info>Page %d proofread.</info>', $pageUid));
            } catch (\Throwable $e) {
                // The per-call audit log already captured backend failures; keep
                // the job as "error" so the editor sees it and can re-run.
                $this->queue->markError($uid, $e->getMessage());
                $failed++;
                $io->writeln(sprintf('<error>Page %d failed: %s</error>', $pageUid, $e->getMessage()));
            }

            if ($max > 0 && ($processed + $failed) >= $max) {
                break;
            }
        }

        $io->success(sprintf('Processed %d job(s), %d failed.', $processed, $failed));
        return Command::SUCCESS;
    }
}
