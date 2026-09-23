<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lead search: fast path + guaranteed-correct fallback.
 *
 * Background (PHASE2B-REMEDIATION-REPORT.md §5): the original
 * `WHERE name LIKE '%t%' OR phone LIKE '%t%' OR email LIKE '%t%'` takes
 * ~3.5s on a 591k-row tenant because a leading wildcard can't use an index.
 * Measured alternatives (FULLTEXT ngram: unreliable — false positives/
 * negatives and can hang on a common term; naive prefix indexes: silently
 * incomplete on phone-number and email-domain searches) ruled out a
 * one-size-fits-all fast rewrite. This applies the fast, index-backed path
 * only where it's provably safe, and keeps the original full-scan
 * behavior — byte-for-byte — everywhere else:
 *
 *  - Any term containing a digit, '@', or '.' (phone- or email-shaped)
 *    ALWAYS uses the full substring scan. Measured: prefix-only phone
 *    matching silently undercounts (e.g. '790' found 305 of the true
 *    3,025 matches — the rest occur elsewhere in the number), so there is
 *    no safe fast path for these terms; correctness wins over speed here.
 *  - A purely alphabetic term (name search) tries the fast path first:
 *    a FULLTEXT word/prefix match on `name` OR a prefix match on `email`.
 *    If that finds nothing, it falls back to the full scan, so a
 *    mid-word fragment (e.g. "ohan" inside "Rohan") still finds its match
 *    — just slower, not silently empty.
 *
 * Only usable on MySQL (FULLTEXT). On any other driver (SQLite in tests)
 * this always takes the full-scan path, which is the correct, unchanged
 * behavior the whole class is measured against.
 *
 * Phase 3 Workstream 04 finding (CRITICAL): the fast path used to answer
 * "does this term match anything, and if so what?" by collecting every
 * matching row's id via Eloquent's id-plucking helper — once for the
 * name-FULLTEXT match, once for the email-prefix match — merging both
 * lists into a single PHP array, then applying it to every target via
 * whereIn('id', $ids). A common single letter (or any short fragment
 * matching a large fraction of names) made that array unbounded —
 * 163,655 IDs for the single letter "a" against a 651k-row tenant —
 * which either exhausted PHP's memory outright materializing it, or blew
 * MySQL's ~65,535-placeholder limit on the subsequent whereIn(), surfacing
 * as an HTTP 500 after ~25s either way. Remediated below: the SAME match
 * condition (FULLTEXT OR email-prefix) is now applied directly to each
 * target query as an ordinary WHERE clause — identical in effect to
 * whereIn('id', $ids) for a query that already filters by id, but the
 * match is evaluated entirely inside MySQL's own LIMIT/OFFSET/COUNT
 * machinery, so no per-request PHP array of matching IDs is ever
 * constructed regardless of how many rows match. The "is the fast path
 * even viable for this term" probe (previously the emptiness of the
 * merged ID array) is now a single ->exists() check, which — unlike
 * collecting every matching id — lets MySQL stop at the first matching
 * row instead of reading every one.
 */
class LeadSearch
{
    /**
     * Apply a search term to one or more target query builders that all
     * share the same prior WHERE conditions (e.g. a listing query and its
     * paired count query).
     *
     * $scopeBasis must be a plain, undecorated builder carrying just those
     * shared conditions (no with()/withCount()) — it's only ever cloned to
     * probe fast-path viability, never executed (beyond that probe) or
     * mutated directly. The viability check runs at most once regardless
     * of target count.
     */
    public static function apply(string $term, Builder $scopeBasis, Builder ...$targets): void
    {
        $condition = static::fastPathCondition($term, $scopeBasis);

        foreach ($targets as $query) {
            $query->where($condition ?? static::fullScanClosure($term));
        }
    }

    /**
     * Returns a closure applying the fast-path match condition (FULLTEXT
     * name match OR email-prefix match) directly as a WHERE clause, or
     * null if the fast path isn't safe/applicable for this term or finds
     * nothing at all (caller should use the full scan either way).
     *
     * Deliberately never materializes matching rows/IDs into PHP: the
     * returned closure is applied straight to the real listing/count
     * queries, so MySQL evaluates the match, the tenant/status/etc.
     * filters, and the LIMIT/OFFSET/COUNT all in one query — the only
     * thing pulled into PHP is however many rows the caller's own
     * pagination already bounds it to.
     */
    private static function fastPathCondition(string $term, Builder $scopedQuery): ?\Closure
    {
        if (DB::connection($scopedQuery->getModel()->getConnectionName())->getDriverName() !== 'mysql') {
            return null;
        }

        // Digits, '@', or '.' => phone- or email-shaped => always the full scan (see class docblock).
        if (preg_match('/[0-9@.]/u', $term) === 1) {
            return null;
        }

        $safe = str_replace(['+', '-', '>', '<', '(', ')', '~', '*', '"'], '', $term);
        if (trim($safe) === '') {
            return null;
        }

        $booleanTerm = '+' . $safe . '*';
        $prefixLike = $term . '%';

        $condition = function (Builder $q) use ($booleanTerm, $prefixLike) {
            $q->whereRaw('MATCH(name) AGAINST(? IN BOOLEAN MODE)', [$booleanTerm])
                ->orWhere('email', 'like', $prefixLike);
        };

        // Empty is untrustworthy here (could be a real zero, or a mid-word/
        // mid-email fragment the fast path can't see) — fall back to the
        // full scan rather than risk a false "no results". ->exists() lets
        // MySQL stop at the first match instead of reading every row that
        // qualifies, unlike the prior id-collecting approach this replaces.
        return (clone $scopedQuery)->where($condition)->exists() ? $condition : null;
    }

    private static function fullScanClosure(string $term): \Closure
    {
        return function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        };
    }
}
