<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

use CommonITILValidation;
use ITILSolution;
use Ticket;
use Ticket_Ticket;

/**
 * Tickets attached to an incident, and what happens to them when it ends.
 *
 * The core link is `SON_OF`, with the incident's own ticket as the parent and
 * every attached ticket as a son. `DUPLICATE_WITH` is the obvious choice and
 * the wrong one: core's CommonITILObject_CommonITILObject::manageLinksOnChange()
 * treats a duplicate link as an *instruction*, so solving the parent copies the
 * solution into every duplicate and a status change to solved is pushed onto
 * them directly. That is auto-closing by another name. `SON_OF` carries no
 * propagation at all — its only core behaviours are a warning on the solution
 * form when children are open, and the parent/child search options — so the
 * relationship is recorded and the decision stays ours.
 *
 * Attaching is always a click. Nothing here runs on a timer or a trigger.
 */
final class Affected
{
    public const TABLE = 'glpi_plugin_glpimajor_affected';

    /**
     * Attach a ticket to an incident.
     *
     * Returns true when the ticket is attached afterwards — including when it
     * already was. A second click on a slow page is not an error worth showing
     * anybody during an outage.
     */
    public static function attach(int $incidents_id, int $tickets_id, ?int $users_id = null): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $incident = new Incident();
        if (!$incident->getFromDB($incidents_id)) {
            return false;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return false;
        }

        // Fail closed on the tenant boundary. Everything above this has already
        // been checked by a caller; this is the check that has to be true even
        // when a caller forgot.
        //
        // The boundary is the incident's *subtree*, not its entity. An incident
        // declared at the firm and marked as covering its sub-entities may take
        // a ticket from the Manchester office; a non-recursive one may not, and
        // neither may ever take a ticket from a sibling firm — Tree::contains()
        // walks up from the ticket, and a sibling is in nobody's ancestor list.
        if (!self::inScope($incident, (int) $ticket->fields['entities_id'])) {
            return false;
        }

        if ((int) $incident->fields['tickets_id'] === $tickets_id) {
            return false;
        }

        if (self::isAttached($incidents_id, $tickets_id)) {
            return true;
        }

        $DB->insert(self::TABLE, [
            'plugin_glpimajor_incidents_id' => $incidents_id,
            'tickets_id'                    => $tickets_id,
            'entities_id'                   => (int) $ticket->fields['entities_id'],
            'users_id'                      => $users_id ?? (int) \Session::getLoginUserID(),
            'date_creation'                 => date('Y-m-d H:i:s'),
        ]);

        self::link($tickets_id, (int) $incident->fields['tickets_id']);
        self::recount($incidents_id);

        Events::record(
            $incidents_id,
            Events::ATTACHED,
            sprintf('ticket #%d', $tickets_id),
            (int) $incident->fields['entities_id']
        );

        return true;
    }

    /**
     * Is a ticket in this entity inside the incident's reach?
     *
     * Public because it is the sentence the whole tenant argument reduces to,
     * and a test that asserts the boundary should be able to ask it directly
     * rather than through a write.
     */
    public static function inScope(Incident $incident, int $entities_id): bool
    {
        $declared_in = (int) $incident->fields['entities_id'];

        if ($declared_in === $entities_id) {
            return true;
        }

        return $incident->coversSubEntities() && Tree::contains($declared_in, $entities_id);
    }

    public static function detach(int $incidents_id, int $tickets_id): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->delete(self::TABLE, [
            'plugin_glpimajor_incidents_id' => $incidents_id,
            'tickets_id'                    => $tickets_id,
        ]);

        self::recount($incidents_id);

        // The core link is deliberately left in place. Somebody may have
        // explained it in a followup, and silently unpicking a relationship a
        // technician can see is worse than leaving a stale one they can remove.
        Events::record($incidents_id, Events::DETACHED, sprintf('ticket #%d', $tickets_id));

        return true;
    }

    public static function isAttached(int $incidents_id, int $tickets_id): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => [
                    'plugin_glpimajor_incidents_id' => $incidents_id,
                    'tickets_id'                    => $tickets_id,
                ],
                'LIMIT' => 1,
            ]) as $_
        ) {
            return true;
        }

        return false;
    }

    /** The incident a ticket is attached to, or null. */
    public static function incidentFor(int $tickets_id): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['tickets_id' => $tickets_id],
                'ORDER' => ['id DESC'],
                'LIMIT' => 1,
            ]) as $row
        ) {
            return $row;
        }

        return null;
    }

    /**
     * The attached tickets, with enough of each to render a row.
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
                'SELECT' => [
                    'a.*',
                    't.name AS ticket_name',
                    't.status AS ticket_status',
                    't.date AS ticket_date',
                ],
                'FROM'      => self::TABLE . ' AS a',
                'LEFT JOIN' => [
                    'glpi_tickets AS t' => ['ON' => ['a' => 'tickets_id', 't' => 'id']],
                ],
                'WHERE' => ['a.plugin_glpimajor_incidents_id' => $incidents_id],
                'ORDER' => ['a.date_creation ASC'],
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * On resolution, propose the outcome as a solution on every attached ticket.
     *
     * `CommonITILValidation::WAITING` is the point. It is a real GLPI state —
     * "waiting for approval" — that puts the solution in front of the person who
     * raised the ticket without closing anything. Twelve people who each filed a
     * ticket get an answer they can accept or argue with, which is what "never
     * auto-closed" has to mean in practice: not silence, and not a closure they
     * were not asked about.
     *
     * The status is forced rather than left to core, because core decides
     * between ACCEPTED and WAITING from the entity's autoclose delay — so on an
     * instance with autoclose off, the "proposal" would be an approval nobody
     * made.
     */
    public static function proposeResolution(Incident $incident): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $outcome = trim((string) ($incident->fields['outcome'] ?? ''));
        if ($outcome === '') {
            return 0;
        }

        $incidents_id = (int) $incident->getID();
        $proposed     = 0;

        foreach (self::forIncident($incidents_id) as $row) {
            if ((int) $row['itilsolutions_id'] > 0) {
                continue;
            }

            $ticket = new Ticket();
            if (!$ticket->getFromDB((int) $row['tickets_id'])) {
                continue;
            }

            // A ticket somebody already solved separately does not need our
            // answer, and core refuses the add anyway — better to skip it than
            // to log a failure that is really a success.
            if (in_array((int) $ticket->fields['status'], $ticket->getSolvedStatusArray(), true)) {
                continue;
            }
            if (in_array((int) $ticket->fields['status'], $ticket->getClosedStatusArray(), true)) {
                continue;
            }

            $solution = new ITILSolution();
            $id       = $solution->add([
                'itemtype' => Ticket::class,
                'items_id' => (int) $row['tickets_id'],
                'content'  => self::solutionText($incident, $outcome),
                'status'   => CommonITILValidation::WAITING,

                // Three flags, each disabling a different thing core does to a
                // ticket the moment a solution lands on it. All three are
                // needed and none of them substitutes for another.
                //
                // `_do_not_compute_status` stops prepareInputForAdd() choosing
                // the solution's own status from the entity's autoclose delay —
                // on an instance with autoclose off it picks ACCEPTED, and a
                // "proposal" nobody approved is exactly the thing we said we
                // would not do.
                //
                // `_linked_ticket` stops post_addItem() setting the *ticket*
                // to solved or closed. This is core's own flag for the same
                // situation: it is what manageLinksOnChange() passes when it
                // copies a solution onto a duplicate. Without it every attached
                // ticket is auto-solved, whatever the solution's status says.
                //
                // `_disable_auto_assign` stops the commander being assigned to
                // twelve tickets they have never read, which is what
                // `glpiset_solution_tech` would otherwise do.
                '_do_not_compute_status' => true,
                '_linked_ticket'         => true,
                '_disable_auto_assign'   => true,
            ]);

            if ($id === false) {
                continue;
            }

            // Belt and braces. The flags above are the mechanism; this is the
            // guarantee. If a future core release changes how any of them work,
            // the worst outcome is a solution row that says "waiting for
            // approval" — not an approval nobody granted.
            $DB->update(
                'glpi_itilsolutions',
                ['status' => CommonITILValidation::WAITING],
                ['id' => (int) $id]
            );

            $DB->update(self::TABLE, [
                'itilsolutions_id' => (int) $id,
                'date_proposed'    => date('Y-m-d H:i:s'),
            ], ['id' => (int) $row['id']]);

            $proposed++;
        }

        if ($proposed > 0) {
            Events::record(
                $incidents_id,
                Events::PROPOSED,
                sprintf('%d ticket(s)', $proposed),
                (int) $incident->fields['entities_id']
            );
        }

        return $proposed;
    }

    /**
     * What the requester of an attached ticket reads.
     *
     * The public title, not the internal one: this text lands in
     * somebody's inbox, and the whole reason the incident has a second title is
     * that the first one is not for them.
     */
    private static function solutionText(Incident $incident, string $outcome): string
    {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        return '<p>' . sprintf(
            __s('This was part of a wider issue: %s', 'glpimajor'),
            '<strong>' . $e($incident->fields['name']) . '</strong>'
        ) . '</p><p>' . nl2br($e($outcome), false) . '</p>';
    }

    /**
     * Recompute the denormalised count on the incident.
     *
     * The column is a cache and this is the only thing that writes it; the rows
     * remain the truth. It exists because a dispatcher wants to sort the
     * incident list by how many people are affected, and GLPI's search engine
     * sorts on columns.
     *
     * The stored number is *tickets impacted, including the one the incident
     * was declared from* — attached rows plus one. The declaring ticket is the
     * outage's first affected ticket; a freshly declared incident reading
     * "0 affected tickets" was a lie in the safe direction, but still a lie,
     * and a dispatcher sorting on this column wants people waiting, not rows
     * in the attach table. The declaring ticket deliberately never becomes a
     * row in that table: attach() refuses it (it is the SON_OF parent, not a
     * son), and proposeResolution() iterates the table on resolve — a row for
     * the incident's own ticket would propose its resolution back onto itself.
     */
    public static function recount(int $incidents_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $count = 0;
        foreach (
            $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => self::TABLE,
                'WHERE' => ['plugin_glpimajor_incidents_id' => $incidents_id],
            ]) as $row
        ) {
            $count = (int) $row['cpt'];
        }

        $DB->update(
            Incident::getTable(),
            ['affected_count' => $count + 1],
            ['id' => $incidents_id]
        );
    }

    /** Called when a ticket changes: keep whichever incident holds it honest. */
    public static function refreshFor(int $tickets_id): void
    {
        $row = self::incidentFor($tickets_id);
        if ($row !== null) {
            self::recount((int) $row['plugin_glpimajor_incidents_id']);
        }
    }

    public static function forgetTicket(int $tickets_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::incidentFor($tickets_id);
        $DB->delete(self::TABLE, ['tickets_id' => $tickets_id]);

        if ($row !== null) {
            self::recount((int) $row['plugin_glpimajor_incidents_id']);
        }
    }

    /**
     * The core parent/child link.
     *
     * `tickets_id_1 SON_OF tickets_id_2` reads "1 is a son of 2", so the
     * affected ticket is 1 and the incident's ticket is 2 — verified against
     * core's countLinksByStatus(), which counts children as `items_id_1` when
     * asked for a parent's open children.
     *
     * Core refuses to add a second link between the same pair, which is a
     * success from here: the tickets are related either way.
     */
    private static function link(int $affected_tickets_id, int $incident_tickets_id): void
    {
        if ($incident_tickets_id <= 0 || $affected_tickets_id === $incident_tickets_id) {
            return;
        }

        $link = new Ticket_Ticket();
        $link->add([
            'tickets_id_1' => $affected_tickets_id,
            'tickets_id_2' => $incident_tickets_id,
            'link'         => Ticket_Ticket::SON_OF,
        ]);
    }
}
