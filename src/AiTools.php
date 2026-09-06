<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

use GlpiPlugin\Glpiai\Tool;
use Session;
use Ticket;

/**
 * Major incidents, offered to glpi-ai's assistant as tools.
 *
 * The question these answer is the cheapest one in the whole of support and the
 * easiest to forget to ask: *is this already a known outage?* A model that
 * cannot ask it will happily produce a diagnostic plan for the fourteenth
 * ticket about a file server that a commander declared down forty minutes ago —
 * fourteen technicians investigating one fault, which is the failure this
 * plugin exists to prevent.
 *
 * Two tools. `major_open_incidents` is the sweep: what is declared and still
 * open in the entities the user can see. `major_incident` is the read: one
 * incident's roles, its promised next update, and the updates already published
 * — which is what somebody answering an affected customer needs, and is also
 * what stops the assistant inventing a reassurance nobody agreed to.
 *
 * **Both are read-only, and the read stays read-only in a way worth naming.**
 * `Pir::forIncident()` creates a review row when there isn't one, so it is not
 * called here at all; `Portal::state()` writes a cache entry; `Affected::recount()`
 * writes the denormalised counter. None of them appear below.
 *
 * Gated on `plugin_glpimajor_declare` READ — the right every ticket reader is
 * installed with, and the one that shows the banner on a ticket. Entity scoping
 * is `Session::haveAccessToEntity($entity, $is_recursive)` per row, which is
 * what the mobile API uses: the recursive flag matters because an incident
 * declared at a parent and marked as covering sub-entities is visible to a
 * child, and one not so marked must not be.
 *
 * Registered unconditionally from setup.php: only glpi-ai reads that hook, so
 * an instance without it never loads this class.
 */
final class AiTools
{
    /** Customer-facing updates returned with an incident. Newest first. */
    private const MAX_UPDATES = 8;

    /** @return Tool[] */
    public static function all(): array
    {
        return [self::open(), self::detail()];
    }

    // ----------------------------------------------------------------- open

    private static function open(): Tool
    {
        return new Tool(
            name: 'major_open_incidents',
            description: 'Major incidents that are declared and not yet resolved. Check this '
                . 'first when a ticket describes something being down or slow for more than one '
                . 'person: if an incident is already running, the answer is to attach the ticket '
                . 'to it rather than to start diagnosing, and the customer-facing wording has '
                . 'already been agreed. Returns the state, who is commanding it, how many tickets '
                . 'are attached and when the next update was promised.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'entity' => [
                        'type'        => 'integer',
                        'description' => 'Restrict to the incidents visible from one GLPI entity '
                            . 'id, including any declared at a parent that cover sub-entities. '
                            . 'Omit for everything the signed-in user can see.',
                    ],
                ],
            ],
            handler: [self::class, 'runOpen'],
            right: 'plugin_glpimajor_declare',
            source: 'glpimajor'
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function runOpen(array $arguments = [], mixed $context = null): array
    {
        $entity = array_key_exists('entity', $arguments) ? (int) $arguments['entity'] : null;

        if ($entity !== null) {
            // openFor() resolves the entity's own incidents plus the ancestors'
            // recursive ones. The session check below still applies to every
            // row: this argument narrows what is asked for, it never widens
            // what may be seen.
            $rows = Incident::openFor($entity);
        } else {
            $rows = Incident::allOpen();
        }

        $out = [];
        foreach ($rows as $row) {
            if (!Session::haveAccessToEntity((int) $row['entities_id'], (bool) $row['is_recursive'])) {
                continue;
            }

            $out[] = self::project($row);
        }

        return [
            'incidents' => $out,
            'note'      => $out === []
                ? 'No major incident is open. A widespread fault may still be one nobody has '
                  . 'declared yet.'
                : 'The title is the customer-visible one, which is deliberately not the declaring '
                  . 'ticket\'s title. Use major_incident to read what has already been published '
                  . 'before telling a customer anything.',
        ];
    }

    // --------------------------------------------------------------- detail

    private static function detail(): Tool
    {
        return new Tool(
            name: 'major_incident',
            description: 'One major incident in full: its state, commander and communications '
                . 'owner, the outcome if it is resolved, and the updates published about it so '
                . 'far. Call this with a ticket id to find out whether that ticket *is* a major '
                . 'incident or is attached to one. Read the published updates before drafting '
                . 'anything for a customer — what has already been said sets what can be said '
                . 'next, and contradicting it is worse than saying nothing.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'incident_id' => [
                        'type'        => 'integer',
                        'description' => 'The incident id, from major_open_incidents.',
                    ],
                    'tickets_id' => [
                        'type'        => 'integer',
                        'description' => 'A ticket id instead: finds the incident this ticket is, '
                            . 'or is attached to. Omit both to use the ticket the conversation is '
                            . 'about.',
                    ],
                    'audience' => [
                        'type'        => 'string',
                        'description' => 'Which updates to return: "customer" for what has been '
                            . 'published externally, "internal" for the war-room log, or omit for '
                            . 'both.',
                    ],
                ],
            ],
            handler: [self::class, 'runDetail'],
            right: 'plugin_glpimajor_declare',
            source: 'glpimajor'
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function runDetail(array $arguments = [], mixed $context = null): array
    {
        $incidents_id = (int) ($arguments['incident_id'] ?? 0);
        $tickets_id   = (int) ($arguments['tickets_id'] ?? 0);

        if ($incidents_id <= 0 && $tickets_id <= 0 && $context instanceof \GlpiPlugin\Glpiai\ToolContext && $context->isAbout('Ticket')) {
            $tickets_id = (int) $context->items_id;
        }

        $relation = null;

        if ($incidents_id <= 0 && $tickets_id > 0) {
            $ticket = new Ticket();
            if (!$ticket->getFromDB($tickets_id) || !$ticket->canViewItem()) {
                return ['error' => sprintf('There is no ticket %d that you can see.', $tickets_id)];
            }

            $own = Incident::forTicket($tickets_id);
            if ($own !== null) {
                $incidents_id = (int) $own['id'];
                $relation     = 'This ticket is the major incident.';
            } else {
                // incidentFor() answers with the *attachment* row, not the
                // incident — the incident id is a column on it.
                $attached = Affected::incidentFor($tickets_id);
                if ($attached !== null) {
                    $incidents_id = (int) $attached['plugin_glpimajor_incidents_id'];
                    $relation     = 'This ticket is attached to the major incident below.';
                }
            }

            if ($incidents_id <= 0) {
                return [
                    'incident' => null,
                    'note'     => 'This ticket is not a major incident and is not attached to one. '
                        . 'Use major_open_incidents to see whether one is running that it should '
                        . 'be attached to.',
                ];
            }
        }

        if ($incidents_id <= 0) {
            return ['error' => 'Name an incident id or a ticket id.'];
        }

        $incident = new Incident();
        if (!$incident->getFromDB($incidents_id) || !$incident->can($incidents_id, READ)) {
            return ['error' => sprintf('There is no major incident %d that you can see.', $incidents_id)];
        }

        $audience = trim((string) ($arguments['audience'] ?? ''));
        $audience = in_array($audience, [Update::INTERNAL, Update::CUSTOMER], true) ? $audience : null;

        $updates = [];
        foreach (array_slice(Update::forIncident($incidents_id, $audience), 0, self::MAX_UPDATES) as $row) {
            $updates[] = [
                'audience'  => (string) $row['audience'],
                'published' => (string) $row['date_creation'],
                'state_then' => (string) ($row['state_at_time'] ?? ''),
                'content'   => self::text($row['content'] ?? ''),
            ];
        }

        $detail = self::project($incident->fields) + [
            'comms_owner' => self::userName((int) $incident->fields['users_id_comms']),
            'outcome'     => self::text($incident->fields['outcome'] ?? ''),
            'resolved_at' => (string) ($incident->fields['date_resolved'] ?? ''),
            'updates'     => $updates,
        ];

        return array_filter([
            'relation' => $relation,
            'incident' => $detail,
            'note'     => $updates === []
                ? 'Nothing has been published about this incident yet — there is no agreed '
                  . 'wording to reuse.'
                : 'Updates are newest first.',
        ], static fn($v): bool => $v !== null);
    }

    // --------------------------------------------------------------- shared

    /**
     * The fields common to both tools.
     *
     * @param array<string,mixed> $row an incidents row
     * @return array<string,mixed>
     */
    private static function project(array $row): array
    {
        $state = (string) $row['state'];

        return [
            'id'             => (int) $row['id'],
            'title'          => (string) $row['name'],
            'state'          => $state,
            'state_label'    => Incident::stateLabel($state),
            'is_open'        => Incident::isOpenState($state),
            'tickets_id'     => (int) $row['tickets_id'],
            'entities_id'    => (int) $row['entities_id'],
            'covers_sub_entities' => (bool) $row['is_recursive'],
            'commander'      => self::userName((int) $row['users_id_commander']),
            'declared_at'    => (string) ($row['date_declared'] ?? ''),
            'next_update_at' => (string) ($row['next_update_at'] ?? ''),
            // Includes the declaring ticket, so an incident nobody else has
            // reported reads as 1 rather than 0.
            'affected_count' => (int) ($row['affected_count'] ?? 0),
        ];
    }

    private static function userName(int $users_id): string
    {
        return $users_id > 0 ? (string) getUserName($users_id) : '';
    }

    /** Rich text as plain text, capped. */
    private static function text(mixed $value): string
    {
        $plain = trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plain = (string) preg_replace('/\s+/u', ' ', $plain);

        return mb_strlen($plain) > 1000 ? mb_substr($plain, 0, 999) . '…' : $plain;
    }
}
