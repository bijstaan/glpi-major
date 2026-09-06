<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

/**
 * The incident feed: one chronological story instead of three lists.
 *
 * The war room's old comms panel showed only the updates; the state changes
 * lived in the main form's history and the declaration lived nowhere visible
 * at all. Reading "we found the cause" directly above "Moved to Identified"
 * is the point of a feed — the sentence and the decision it accompanied are
 * one moment, and the page should say so.
 *
 * Three sources, one order:
 *  - the declaration, synthesised from the incident row itself
 *    (date_declared / users_id_declared / tickets_id — see the note on
 *    StateChange for why it is not a stored event);
 *  - the update log (glpi_plugin_glpimajor_updates), unchanged;
 *  - the recorded state transitions (glpi_plugin_glpimajor_statechanges),
 *    which exist from 0.1.2 onwards — an incident older than the migration
 *    simply has no transition entries before that date, and its updates
 *    still render. No backfill: inventing timestamps for transitions nobody
 *    recorded would be fiction presented as history.
 *
 * Promises are deliberately NOT in the feed. A promise is a living countdown
 * — the cockpit and the promise line show the one that matters, the next one
 * — and every promise that was kept is already visible as the update that
 * kept it. Interleaving "promised an update in 30 minutes" between every
 * entry would double the feed's length to say nothing the entries around it
 * do not. The audit trail keeps every promise for the PIR timeline.
 *
 * Entries are oldest-first: this is a story. The war room renders it
 * newest-first (the last thing said is the thing being asked for) by
 * reversing; the mobile payload carries it as told.
 */
final class Feed
{
    public const DECLARED = 'declared';
    public const UPDATE   = 'update';
    public const STATE    = 'state';

    /** @return array<int,array<string,mixed>> oldest first */
    public static function forIncident(Incident $incident): array
    {
        $incidents_id = (int) $incident->getID();

        $declaration = null;
        if ((string) ($incident->fields['date_declared'] ?? '') !== '') {
            $declaration = [
                'kind'       => self::DECLARED,
                'at'         => (string) $incident->fields['date_declared'],
                'id'         => 0,
                'users_id'   => (int) $incident->fields['users_id_declared'],
                'tickets_id' => (int) $incident->fields['tickets_id'],
                // Every declaration starts here — declareFor() writes it and
                // validate() refuses anything else on add.
                'state'      => Incident::INVESTIGATING,
            ];
        }

        $updates = [];
        foreach (Update::forIncident($incidents_id) as $row) {
            $updates[] = [
                'kind'     => self::UPDATE,
                'at'       => (string) $row['date_creation'],
                'id'       => (int) $row['id'],
                'users_id' => (int) $row['users_id'],
                'update'   => $row,
            ];
        }

        $changes = [];
        foreach (StateChange::forIncident($incidents_id) as $row) {
            $changes[] = [
                'kind'     => self::STATE,
                'at'       => (string) $row['date_creation'],
                'id'       => (int) $row['id'],
                'users_id' => (int) $row['users_id'],
                'from'     => (string) $row['state_from'],
                'to'       => (string) $row['state_to'],
                'outcome'  => (string) ($row['outcome'] ?? ''),
            ];
        }

        return self::merge($declaration, $updates, $changes);
    }

    /**
     * One order out of three sources. Pure, and tested as such.
     *
     * Oldest first by timestamp; within the same second, the declaration
     * precedes everything, and an update precedes a state change. That
     * tie-break is load-bearing: the composer's combined post publishes the
     * note and *then* moves the state, in the same second — the note was
     * written under the old state (`state_at_time` says so), so the story
     * must read note-then-transition, not the other way round. Same kind,
     * same second falls back to insertion id.
     *
     * @param array<string,mixed>|null            $declaration
     * @param array<int,array<string,mixed>>      $updates
     * @param array<int,array<string,mixed>>      $changes
     * @return array<int,array<string,mixed>>
     */
    public static function merge(?array $declaration, array $updates, array $changes): array
    {
        $rank = [self::DECLARED => 0, self::UPDATE => 1, self::STATE => 2];

        $all = array_merge($declaration !== null ? [$declaration] : [], $updates, $changes);

        usort($all, static function (array $a, array $b) use ($rank): int {
            return [(string) $a['at'], $rank[$a['kind']] ?? 9, (int) $a['id']]
               <=> [(string) $b['at'], $rank[$b['kind']] ?? 9, (int) $b['id']];
        });

        return $all;
    }
}
