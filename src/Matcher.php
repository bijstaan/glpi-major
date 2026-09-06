<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

/**
 * Does this ticket look like it belongs to an incident already open?
 *
 * Pure: rows in, verdicts out. No database, no session, no clock of its own —
 * `now` is passed in. That is not decoration; it is what lets the whole
 * matching policy be tested without a GLPI, and what stops "the banner appeared
 * on the wrong ticket" from being a bug you can only reproduce in production.
 *
 * The output is an *offer*, never an action. Attaching is always a click.
 */
final class Matcher
{
    /**
     * @param array<string,mixed>            $ticket    id, entities_id, itilcategories_id, locations_id, name,
     *                                                  and `ancestors` — the ids of the entities this one sits
     *                                                  under, which is how a recursive incident reaches it
     * @param array<int,array<string,mixed>> $incidents candidate MI rows
     * @param array<string,mixed>            $rules     see Settings::matchRules()
     * @param int|null                       $now       unix time; null means time()
     *
     * @return array<int,array{incident:array<string,mixed>,reasons:string[],score:int}>
     *         best first
     */
    public static function match(array $ticket, array $incidents, array $rules, ?int $now = null): array
    {
        if (empty($rules['enabled'])) {
            return [];
        }

        $now          = $now ?? time();
        $entities_id  = (int) ($ticket['entities_id'] ?? -1);
        $ancestors    = array_map('intval', (array) ($ticket['ancestors'] ?? []));
        $window       = max(1, (int) ($rules['window_hours'] ?? 72)) * 3600;
        $ticket_words = self::words((string) ($ticket['name'] ?? ''), (int) ($rules['min_word'] ?? 5));

        $out = [];

        foreach ($incidents as $incident) {
            // Fail closed on the tenant boundary. This is the one condition
            // that is not a heuristic: offering a customer's outage as an
            // explanation for another customer's ticket leaks the fact of it.
            //
            // Two ways through, and no third. The incident is in this ticket's
            // own entity; or it was declared at one of this ticket's
            // *ancestors* and marked as covering its sub-entities. The ancestor
            // list is passed in rather than looked up, so this stays a pure
            // function — and, more usefully, so the sibling case can be written
            // as a test that fails loudly rather than a fact about the entity
            // table that happens to hold today.
            if (!self::reaches($incident, $entities_id, $ancestors)) {
                continue;
            }

            if ((string) ($incident['state'] ?? '') === Incident::RESOLVED) {
                continue;
            }

            // An outage from last month is not what this ticket is about.
            $declared = self::stamp($incident['date_declared'] ?? null);
            if ($declared === null || ($now - $declared) > $window || $declared > ($now + 300)) {
                continue;
            }

            $reasons = [];
            $score   = 0;

            if (!empty($rules['same_category'])) {
                $a = (int) ($ticket['itilcategories_id'] ?? 0);
                $b = (int) ($incident['itilcategories_id'] ?? 0);
                // Two tickets with no category are not "the same category".
                // Treating 0 as a value would match every uncategorised ticket
                // in the entity to every uncategorised incident.
                if ($a > 0 && $a === $b) {
                    $reasons[] = 'category';
                    $score    += 10;
                }
            }

            if (!empty($rules['same_location'])) {
                $a = (int) ($ticket['locations_id'] ?? 0);
                $b = (int) ($incident['locations_id'] ?? 0);
                if ($a > 0 && $a === $b) {
                    $reasons[] = 'location';
                    $score    += 6;
                }
            }

            if (!empty($rules['keywords']) && $ticket_words !== []) {
                $shared = array_intersect(
                    $ticket_words,
                    self::words((string) ($incident['name'] ?? ''), (int) ($rules['min_word'] ?? 5))
                );
                if ($shared !== []) {
                    $reasons[] = 'keywords';
                    // Two shared words is a coincidence; five is a subject.
                    $score += min(9, count($shared) * 3);
                }
            }

            if ($reasons === []) {
                continue;
            }

            // A newer incident wins a tie: if two outages share a category, the
            // one declared twenty minutes ago is the one this caller is ringing
            // about.
            $score += $declared > 0 ? 1 : 0;

            $out[] = [
                'incident' => $incident,
                'reasons'  => $reasons,
                'score'    => $score,
                'declared' => $declared,
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score']
                ?: $b['declared'] <=> $a['declared']
                ?: (int) $a['incident']['id'] <=> (int) $b['incident']['id'];
        });

        return $out;
    }

    /**
     * May this incident be offered for a ticket in this entity?
     *
     * Down the tree or not at all. A sibling entity appears in neither the
     * ticket's ancestor list nor as its own entity, so there is no argument to
     * this function that lets one through — the boundary is the shape of the
     * check, not a filter applied after it.
     */
    private static function reaches(array $incident, int $entities_id, array $ancestors): bool
    {
        $declared_in = (int) ($incident['entities_id'] ?? -2);

        if ($declared_in === $entities_id) {
            return true;
        }

        return ((int) ($incident['is_recursive'] ?? 0)) === 1
            && in_array($declared_in, $ancestors, true);
    }

    /** The single best offer, or null. The banner shows one thing or nothing. */
    public static function best(array $ticket, array $incidents, array $rules, ?int $now = null): ?array
    {
        $all = self::match($ticket, $incidents, $rules, $now);

        return $all[0] ?? null;
    }

    /** Why we are offering this, in words a technician can disagree with. */
    public static function explain(array $reasons): string
    {
        $parts = [];
        foreach ($reasons as $reason) {
            $parts[] = match ($reason) {
                'category' => __('same category', 'glpimajor'),
                'location' => __('same location', 'glpimajor'),
                'keywords' => __('similar wording', 'glpimajor'),
                default    => $reason,
            };
        }

        return implode(', ', $parts);
    }

    /**
     * Comparable words from a title.
     *
     * Lowercased, punctuation-split, deduplicated, and filtered to words of at
     * least `min` characters. The length floor is the whole defence against a
     * keyword rule that matches everything: without it, "the" and "issue" tie
     * every ticket in the instance to every incident in it.
     *
     * @return string[]
     */
    public static function words(string $text, int $min): array
    {
        $min   = max(3, $min);
        $text  = mb_strtolower($text);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $out = [];
        foreach ($parts as $word) {
            if (mb_strlen($word) >= $min) {
                $out[$word] = true;
            }
        }

        return array_keys($out);
    }

    /** A DATETIME string as unix time, or null for anything unusable. */
    private static function stamp(mixed $value): ?int
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000')) {
            return null;
        }

        $at = strtotime($value);

        return $at === false ? null : $at;
    }
}
