<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

/**
 * The entity tree, in the two directions this plugin is allowed to look.
 *
 * A recursive incident travels **down** the declaring entity's subtree and
 * nowhere else. That is the whole of the tenant argument: "Law Company" has
 * Manchester and London, and an outage that closes both offices is one
 * customer's outage told once. A sibling of Law Company is a different
 * customer, and nothing here can reach one — `descendants()` walks sons and
 * `ancestors()` walks parents, and neither has any way to arrive at a sibling.
 *
 * Both directions are core's own cached walkers, not queries of our own.
 * `getSonsOf()` reads the `sons_cache` column GLPI maintains on every entity
 * write; `getAncestorsOf()` reads `ancestors_cache` the same way. Writing our
 * own recursive CTE would be a second implementation of the tree that could
 * disagree with GLPI's about what a customer's offices are, and the one place
 * this plugin cannot afford a second opinion is the tenant boundary.
 *
 * The per-request memo on top is because the portal banner and the ticket
 * banner both ask these questions on pages people load all day, and core's
 * cache still costs a serialiser and a cache round trip each time.
 */
final class Tree
{
    /** @var array<int,int[]> */
    private static array $ancestors = [];

    /** @var array<int,int[]> */
    private static array $descendants = [];

    /**
     * The entity's ancestors, nearest last, **excluding itself**.
     *
     * Root (0) is in the list, because root is a genuine ancestor: a recursive
     * incident declared at root is an outage of the MSP's own, and every
     * customer is downstream of it. That is not a leak — it is the one case
     * where "everybody" is the right answer.
     *
     * @return int[]
     */
    public static function ancestors(int $entities_id): array
    {
        if ($entities_id <= 0) {
            return [];
        }

        if (!isset(self::$ancestors[$entities_id])) {
            $ids = [];
            foreach (getAncestorsOf('glpi_entities', $entities_id) as $one) {
                $one = (int) $one;
                if ($one !== $entities_id) {
                    $ids[] = $one;
                }
            }
            sort($ids);
            self::$ancestors[$entities_id] = $ids;
        }

        return self::$ancestors[$entities_id];
    }

    /**
     * The entity's descendants, **excluding itself**.
     *
     * @return int[]
     */
    public static function descendants(int $entities_id): array
    {
        if (!isset(self::$descendants[$entities_id])) {
            $ids = [];
            foreach (getSonsOf('glpi_entities', $entities_id) as $one) {
                $one = (int) $one;
                if ($one !== $entities_id) {
                    $ids[] = $one;
                }
            }
            sort($ids);
            self::$descendants[$entities_id] = $ids;
        }

        return self::$descendants[$entities_id];
    }

    /**
     * The entity and everything under it.
     *
     * @return int[]
     */
    public static function subtree(int $entities_id): array
    {
        return array_merge([$entities_id], self::descendants($entities_id));
    }

    /**
     * The entity and every ancestor of it — the set an incident may reach it
     * from.
     *
     * @return int[]
     */
    public static function selfAndAncestors(int $entities_id): array
    {
        return array_merge([$entities_id], self::ancestors($entities_id));
    }

    /** Does this entity have any sub-entity? The declare checkbox asks. */
    public static function hasChildren(int $entities_id): bool
    {
        return self::descendants($entities_id) !== [];
    }

    /**
     * Is `$maybe_child` inside `$ancestor`'s subtree (itself included)?
     *
     * Asked from the direction of the *child*, on purpose. Reading it as "is
     * this ticket's entity underneath that incident's" makes the sibling case
     * impossible to write by accident: a sibling's id is in neither the
     * child's ancestors nor the ancestor's sons.
     */
    public static function contains(int $ancestor, int $maybe_child): bool
    {
        if ($ancestor === $maybe_child) {
            return true;
        }

        return in_array($ancestor, self::ancestors($maybe_child), true);
    }

    /**
     * The two ways a row may belong to an entity, as query criteria.
     *
     * Its own, or an ancestor's marked as covering sub-entities. Written once
     * and used by the status page, the maintenance list, the duplicate offer
     * and the portal, so those four surfaces cannot drift apart — which is the
     * failure mode where a child's page carries the parent's outage but its
     * technicians are never offered the attach.
     *
     * Written as an explicit OR of two fenced clauses rather than one
     * `entities_id IN (self + ancestors)`, because the second clause has to
     * carry `is_recursive` with it: an ancestor's *non*-recursive incident is
     * that entity's own business and must never reach a child. Reading up the
     * tree is also the only direction that cannot produce a sibling — a sibling
     * appears in nobody's ancestor list.
     *
     * @return array<int,array<string,mixed>> OR-clauses for a WHERE
     */
    public static function visibleFrom(int $entities_id): array
    {
        $clauses   = [['entities_id' => $entities_id]];
        $ancestors = self::ancestors($entities_id);

        if ($ancestors !== []) {
            $clauses[] = ['entities_id' => $ancestors, 'is_recursive' => 1];
        }

        return $clauses;
    }

    /** Names for a list of entity ids, for the settings page and the log. */
    public static function names(array $entities): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $entities = array_values(array_unique(array_map('intval', $entities)));
        if ($entities === []) {
            return [];
        }

        $out = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'completename', 'name'],
                'FROM'   => 'glpi_entities',
                'WHERE'  => ['id' => $entities],
            ]) as $row
        ) {
            $out[(int) $row['id']] = (string) ($row['completename'] ?: $row['name']);
        }

        return $out;
    }

    /** Only used by the tests, which create entities mid-run. */
    public static function forget(): void
    {
        self::$ancestors   = [];
        self::$descendants = [];
    }
}
