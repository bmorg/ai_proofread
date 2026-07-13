<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Enum;

/**
 * The proofreading categories, in a fixed display order (see sort()).
 * The sort order drives both the report layout and per-category filtering.
 */
enum Category: string
{
    case Spelling = 'spelling';
    case Grammar = 'grammar';
    case Punctuation = 'punctuation';
    case GenderInclusiveLanguage = 'gender-inclusive-language';

    // Note: there is no Style case. Style is never a localized finding — the
    // prompt routes all stylistic feedback to the free-text `other` bucket — so
    // it is not a structured category (no schema enum value, no report section).

    /**
     * Lower number = listed first (report sections and the Report-Verlauf columns).
     */
    public function sort(): int
    {
        return match ($this) {
            self::Spelling => 1,
            self::Punctuation => 2,
            self::Grammar => 3,
            self::GenderInclusiveLanguage => 4,
        };
    }

    /**
     * German label shown in the backend.
     */
    public function label(): string
    {
        return match ($this) {
            self::Spelling => 'Rechtschreibung',
            self::Grammar => 'Grammatik',
            self::Punctuation => 'Zeichensetzung',
            self::GenderInclusiveLanguage => 'Gendern',
        };
    }

    /**
     * Short label for compact tables (e.g. the Report-Verlauf columns).
     */
    public function abbreviation(): string
    {
        return match ($this) {
            self::Spelling => 'Rechtsch.',
            self::Grammar => 'Gramm.',
            self::Punctuation => 'Zeich.',
            self::GenderInclusiveLanguage => 'Gend.',
        };
    }

    /**
     * @return list<Category> all categories ordered by urgency
     */
    public static function ordered(): array
    {
        $cases = self::cases();
        usort($cases, static fn (Category $a, Category $b): int => $a->sort() <=> $b->sort());
        return $cases;
    }
}
