<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service;

/**
 * Outcome of trying to locate a finding's quote in its content element — the
 * render-time pre-check that decides whether the review-and-fix UI offers a
 * one-click "Übernehmen" (apply) for a finding, and supplies the surrounding
 * context shown with it.
 *
 * `$applicable` is true only when the quote is uniquely and safely locatable
 * (so an apply would succeed); otherwise `$reason` carries why not (one of the
 * SuggestionApplier status constants), and the UI falls back to the deep-link.
 */
final class LocateResult
{
    public function __construct(
        public readonly bool $applicable,
        public readonly string $reason,
        public readonly string $field,
        public readonly string $contextBefore,
        public readonly string $contextAfter,
    ) {
    }
}
