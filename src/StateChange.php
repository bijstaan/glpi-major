<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

/**
 * A state transition, recorded for the incident feed.
 *
 * Deliberately not the audit table, although Events already writes a `state`
 * row for every transition. The audit trail is pruned on a retention cron and
 * its detail is a debug string ("identified -> monitoring") — fine for "who
 * did what", wrong for a feed that has to sit next to the permanent update
 * log and still be there in a year, rendered from typed columns rather than a
 * parsed sentence. So the feed's copy lives here, permanent like the updates
 * it interleaves with, and Events keeps its own row for the PIR timeline and
 * the retention policy. Two writes, two owners, no shared fate.
 *
 * `outcome` is a snapshot, not a join. The incident's own outcome column can
 * be rewritten after the fact (the form allows it; re-resolving allows it),
 * and the feed's resolve entry has to say what was written *at the moment of
 * resolving* — the same argument as `state_at_time` on the updates table.
 *
 * The declaration is deliberately NOT a row here. The incident row already
 * carries who declared it, when, and from which ticket; the feed synthesises
 * its declaration entry from those columns (see Feed), so a second copy could
 * only ever agree with them or drift from them — and it gives every incident
 * declared before this table existed a declaration entry for free.
 */
final class StateChange
{
    public const TABLE = 'glpi_plugin_glpimajor_statechanges';

    /**
     * Record a transition.
     *
     * Never throws and never blocks, for the same reason Events::record()
     * does not: a feed row that fails to write must not take down the state
     * change it was describing. A gap in the feed is noticeable; a 500 on
     * "resolve the outage" is an outage inside an outage.
     */
    public static function record(Incident $incident, string $from, string $to, string $outcome = ''): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        try {
            $DB->insert(self::TABLE, [
                'plugin_glpimajor_incidents_id' => (int) $incident->getID(),
                'entities_id'                   => (int) $incident->fields['entities_id'],
                'users_id'                      => (int) \Session::getLoginUserID(),
                'state_from'                    => mb_substr($from, 0, 16),
                'state_to'                      => mb_substr($to, 0, 16),
                'outcome'                       => $outcome !== '' ? $outcome : null,
                'date_creation'                 => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            trigger_error('glpimajor: could not record state change: ' . $e->getMessage(), E_USER_WARNING);
        }
    }

    /**
     * Every recorded transition for an incident, oldest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forIncident(int $incidents_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['plugin_glpimajor_incidents_id' => $incidents_id],
                'ORDER' => ['date_creation ASC', 'id ASC'],
                'LIMIT' => 500,
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
    }
}
