<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Builder;

/**
 * Search behaviour for the database fallback.
 *
 * The configured synonyms, aliases and typo tolerance used to live only in
 * Meilisearch. Wherever the engine was unreachable the portal quietly dropped
 * to a plain LIKE, so an administrator's Gujarati alias returned nothing and a
 * transposed filename ("QAReveiwImage") found nothing either, with no sign that
 * the configured behaviour had been skipped. This applies the same settings
 * against the database.
 */
class SearchFallbackService
{
    /** How many candidate rows the typo pass will examine. */
    private const FUZZY_CANDIDATE_LIMIT = 5000;

    public function settings(): array
    {
        return SystemSetting::valueFor('search.settings', []);
    }

    /**
     * The search term together with every alias and synonym configured for it.
     * Matching is case-insensitive, so "ગુરુપૂર્ણિમા" reaches "Gurupurnima"
     * exactly as it does through the engine.
     */
    public function expandTerms(string $term, ?array $settings = null): array
    {
        $settings ??= $this->settings();
        $term = trim($term);
        if ($term === '') return [];

        $terms = [$term];
        $groups = [];
        if ($settings['synonyms'] ?? true) $groups = array_merge($groups, $settings['synonym_groups'] ?? []);
        if ($settings['aliases'] ?? true)  $groups = array_merge($groups, $settings['alias_groups'] ?? []);

        foreach ($groups as $group) {
            $words = array_values(array_filter(array_map('trim', (array) $group), fn ($w) => $w !== ''));
            foreach ($words as $word) {
                if (mb_strtolower($word) === mb_strtolower($term)) {
                    $terms = array_merge($terms, $words);
                    break;
                }
            }
        }

        $unique = [];
        foreach ($terms as $candidate) {
            $key = mb_strtolower($candidate);
            if (!isset($unique[$key])) $unique[$key] = $candidate;
        }
        return array_values($unique);
    }

    public function fuzzyEnabled(?array $settings = null): bool
    {
        return (bool) (($settings ?? $this->settings())['fuzzy'] ?? true);
    }

    /**
     * Edit distance allowed for a word, matching Meilisearch's own defaults so
     * the fallback does not silently behave differently from the engine.
     */
    public function typoBudget(string $word): int
    {
        $length = mb_strlen($word);
        if ($length >= 9) return 2;
        if ($length >= 5) return 1;
        return 0;
    }

    /**
     * Ids of assets whose name is within the typo budget of one of the terms.
     * Runs against an already visibility-scoped query, so it can never widen
     * what the signed-in user is allowed to see.
     */
    public function fuzzyIds(Builder $scopedQuery, array $terms): array
    {
        $terms = array_values(array_filter($terms, fn ($t) => $this->typoBudget((string) $t) > 0));
        if (!$terms) return [];

        $rows = $scopedQuery->clone()
            ->reorder()
            ->limit(self::FUZZY_CANDIDATE_LIMIT)
            ->pluck('media_files.name', 'media_files.id');

        $matches = [];
        foreach ($rows as $id => $name) {
            if ($this->isNear((string) $name, $terms)) $matches[] = (int) $id;
        }
        return $matches;
    }

    /** True when any word of the haystack is within a term's typo budget. */
    public function isNear(string $haystack, array $terms): bool
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $haystack, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words[] = $haystack;

        foreach ($terms as $term) {
            $budget = $this->typoBudget((string) $term);
            if ($budget < 1) continue;
            $needle = mb_strtolower((string) $term);
            foreach ($words as $word) {
                $word = mb_strtolower($word);
                // levenshtein() counts bytes, so a multi-byte word would be
                // measured wrongly; those fall back to a containment test.
                if (strlen($word) !== mb_strlen($word) || strlen($needle) !== mb_strlen($needle)) {
                    if (str_contains($word, $needle)) return true;
                    continue;
                }
                if (abs(strlen($word) - strlen($needle)) > $budget) continue;
                if (levenshtein($word, $needle) <= $budget) return true;
            }
        }
        return false;
    }
}
