<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Tests\Unit\EventListener;

use Bmorg\AiProofread\Enum\ReviewStatus;
use Bmorg\AiProofread\EventListener\AddProofreadButton;
use Bmorg\AiProofread\Service\QueueRepository;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The state→decoration truth table of the state-aware docheader icon: which
 * robot icon variant + tooltip a page's proofreading state yields, including
 * the precedence rule (queue states beat the review status).
 */
final class AddProofreadButtonTest extends UnitTestCase
{
    private AddProofreadButton $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new AddProofreadButton();
    }

    /**
     * @return array<string, array{0: ?string, 1: ReviewStatus, 2: ?string, 3: string}>
     */
    public function decorationCases(): array
    {
        return [
            'unchecked page shows the plain robot' => [
                null, ReviewStatus::Unchecked, 'aiproofread-robot', 'KI-Lektorat',
            ],
            'report waiting for review shows info' => [
                null, ReviewStatus::ReportCreated, 'aiproofread-robot-info', 'KI-Lektorat – Report wartet auf Prüfung',
            ],
            'signed-off page shows the check' => [
                null, ReviewStatus::Proofed, 'aiproofread-robot-check', 'KI-Lektorat – geprüft',
            ],
            'pending job shows the clock' => [
                QueueRepository::PENDING, ReviewStatus::Unchecked, 'aiproofread-robot-clock', 'KI-Lektorat – Report wird erstellt …',
            ],
            'running job shows the clock' => [
                QueueRepository::RUNNING, ReviewStatus::ReportCreated, 'aiproofread-robot-clock', 'KI-Lektorat – Report wird erstellt …',
            ],
            'failed job shows the warning' => [
                QueueRepository::ERROR, ReviewStatus::Unchecked, 'aiproofread-robot-warning', 'KI-Lektorat – letzte Prüfung fehlgeschlagen',
            ],
            // Precedence: queue states beat the review status — a geprüft page
            // with a new run queued reads as "wird erstellt", and a geprüft page
            // whose re-run failed reads as failed.
            'running job on a proofed page still shows the clock' => [
                QueueRepository::RUNNING, ReviewStatus::Proofed, 'aiproofread-robot-clock', 'KI-Lektorat – Report wird erstellt …',
            ],
            'failed job on a proofed page still shows the warning' => [
                QueueRepository::ERROR, ReviewStatus::Proofed, 'aiproofread-robot-warning', 'KI-Lektorat – letzte Prüfung fehlgeschlagen',
            ],
            'unknown queue status falls back to the review status' => [
                'garbage', ReviewStatus::Proofed, 'aiproofread-robot-check', 'KI-Lektorat – geprüft',
            ],
        ];
    }

    /**
     * @dataProvider decorationCases
     */
    public function testDecorationFor(?string $queueStatus, ReviewStatus $status, string $expectedIcon, string $expectedTitle): void
    {
        $decoration = $this->listener->decorationFor($queueStatus, $status);

        self::assertSame($expectedIcon, $decoration['icon']);
        self::assertSame($expectedTitle, $decoration['title']);
    }
}
