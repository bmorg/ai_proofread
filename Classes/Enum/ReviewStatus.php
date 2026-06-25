<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Enum;

/**
 * The derived, editor-facing status of a page. Nothing is stored as a status
 * string; it is computed from timestamps — whether a report run exists and
 * whether the editor signed off at/after the latest run. See
 * ReviewRepository::deriveStatus().
 */
enum ReviewStatus: string
{
    /** No report run exists yet. */
    case Unchecked = 'unchecked';

    /** The latest report run has not been signed off (or a newer run exists). */
    case ReportCreated = 'report_created';

    /** Editor signed off at/after the latest report run. */
    case Proofed = 'proofed';

    public function label(): string
    {
        return match ($this) {
            self::Unchecked => 'Ungeprüft',
            self::ReportCreated => 'Report erstellt',
            self::Proofed => 'Geprüft',
        };
    }
}
