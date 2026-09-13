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
 * Five tools. `major_open_incidents` is the sweep: what is declared and still
 * open in the entities the user can see. `major_incident` is the read: one
 * incident's roles, its promised next update, and the updates already published
 * — which is what somebody answering an affected requester needs, and is also
 * what stops the assistant inventing a reassurance nobody agreed to.
 *
 * The other three are the parts of an incident that outlive it.
 * `major_timeline` reconstructs what happened when — including the silences,
 * which is what a review is usually about. `major_actions` is what was agreed
 * afterwards and whether anybody did it, the half of post-incident review that
 * quietly rots. `major_maintenance` is the opposite question and the one asked
 * most often at the service desk: is this outage something the requester was
 * already told about?
 *
 * **All five are read-only, and the reads stay read-only in a way worth
 * naming.** `Pir::forIncident()` *creates* a review row when there isn't one,
 * so it is only ever called here with `$create = false`; `Portal::state()`
 * writes a cache entry; `Affected::recount()` writes the denormalised counter.
 * Neither of those two appears below at all. A tool that quietly created a
 * post-incident review by being asked whether one existed would put an empty
 * draft in a manager's queue for every question a model asked.
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
    /** Public updates returned with an incident. Newest first. */
    private const MAX_UPDATES = 8;

    /** @return Tool[] */
    public static function all(): array
    {
        return [
            self::open(),
            self::detail(),
            self::timeline(),
            self::actions(),
            self::maintenance(),
        ];
    }

    // ----------------------------------------------------------------- open

    private static function open(): Tool
    {
        return new Tool(
            name: 'major_open_incidents',
            description: 'Major incidents that are declared and not yet resolved. Check this '
                . 'first when a ticket describes something being down or slow for more than one '
                . 'person: if an incident is already running, the answer is to attach the ticket '
                . 'to it rather than to start diagnosing, and the public wording has '
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
                : 'The title is the public one, which is deliberately not the declaring '
                  . 'ticket\'s title. Use major_incident to read what has already been published '
                  . 'before publishing anything.',
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
                . 'anything publicly — what has already been said sets what can be said '
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
        $audience = in_array($audience, [Update::INTERNAL, Update::EXTERNAL], true) ? $audience : null;

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

    // ------------------------------------------------------------- timeline

    private static function timeline(): Tool
    {
        return new Tool(
            name: 'major_timeline',
            description: 'What actually happened during a major incident, in order: when it was '
                . 'declared, every update and who it went to, the state changes, and when it was '
                . 'resolved. Use it for a post-incident review, for "how long were people '
                . 'left without an update", and when writing anything about how an incident was '
                . 'handled — the gaps between the public lines are usually the finding.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'incident_id' => [
                        'type'        => 'integer',
                        'description' => 'The incident id, from major_open_incidents.',
                    ],
                ],
                'required'   => ['incident_id'],
            ],
            handler: [self::class, 'runTimeline'],
            right: 'plugin_glpimajor_declare',
            source: 'glpimajor',
            pinned: false
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function runTimeline(array $arguments = [], mixed $context = null): array
    {
        $incidents_id = (int) ($arguments['incident_id'] ?? 0);

        $incident = new Incident();
        if ($incidents_id <= 0 || !$incident->getFromDB($incidents_id) || !$incident->can($incidents_id, READ)) {
            return ['error' => sprintf('There is no major incident %d that you can see.', $incidents_id)];
        }

        $rows = [];
        foreach (Pir::timeline($incidents_id) as $entry) {
            $rows[] = array_filter([
                'at'     => (string) $entry['at'],
                'what'   => (string) $entry['label'],
                'kind'   => (string) $entry['kind'],
                'detail' => self::text($entry['detail'] ?? ''),
            ], static fn($v): bool => $v !== '');
        }

        return [
            'incident' => [
                'id'    => $incidents_id,
                'title' => (string) $incident->fields['name'],
                'state' => Incident::stateLabel((string) $incident->fields['state']),
            ],
            'timeline' => $rows,
            'gaps'     => self::commsGaps($incidents_id),
            'note'     => 'Oldest first. "Public update" is the only kind a reader '
                . 'ever saw — internal notes and state changes were invisible to them, however '
                . 'busy the log looks.',
        ];
    }

    /**
     * The longest stretch the public heard nothing.
     *
     * Computed rather than left to be counted off a list of timestamps: it is
     * the single number a review turns on, and a model asked to work it out
     * from twenty rows will occasionally take the gap between two internal
     * notes for a gap in the comms.
     *
     * @return array<string,mixed>|null
     */
    private static function commsGaps(int $incidents_id): ?array
    {
        $stamps = [];

        foreach (Update::forIncident($incidents_id, Update::EXTERNAL) as $row) {
            $at = strtotime((string) $row['date_creation']);
            if ($at > 0) {
                $stamps[] = $at;
            }
        }

        if ($stamps === []) {
            return ['longest_silence' => 'the whole incident — nothing was ever published'];
        }

        sort($stamps);

        $incident = new Incident();
        if ($incident->getFromDB($incidents_id)) {
            $declared = strtotime((string) ($incident->fields['date_declared'] ?? ''));
            if ($declared > 0) {
                array_unshift($stamps, $declared);
            }

            $resolved = strtotime((string) ($incident->fields['date_resolved'] ?? ''));
            $stamps[] = $resolved > 0 ? $resolved : time();
        }

        $worst = 0;
        for ($i = 1, $n = count($stamps); $i < $n; $i++) {
            $worst = max($worst, $stamps[$i] - $stamps[$i - 1]);
        }

        return [
            'customer_updates' => count($stamps) - 2 > 0 ? count($stamps) - 2 : 0,
            'longest_silence'  => sprintf('%d minutes', (int) round($worst / 60)),
        ];
    }

    // -------------------------------------------------------------- actions

    private static function actions(): Tool
    {
        return new Tool(
            name: 'major_actions',
            description: 'What was agreed after a major incident and whether anybody did it: the '
                . 'post-incident review\'s actions, their owners, due dates and status, with the '
                . 'review\'s own findings — what happened, the impact, the root cause. Use it '
                . 'when asked what came out of an incident, whether the follow-up work was ever '
                . 'done, and before promising anyone that something has been put right.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'incident_id' => [
                        'type'        => 'integer',
                        'description' => 'The incident id.',
                    ],
                    'open_only'   => [
                        'type'        => 'string',
                        'enum'        => ['yes', 'no'],
                        'description' => 'Only actions nobody has closed. Defaults to no.',
                    ],
                ],
                'required'   => ['incident_id'],
            ],
            handler: [self::class, 'runActions'],
            right: 'plugin_glpimajor_declare',
            source: 'glpimajor',
            pinned: false
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function runActions(array $arguments = [], mixed $context = null): array
    {
        $incidents_id = (int) ($arguments['incident_id'] ?? 0);

        $incident = new Incident();
        if ($incidents_id <= 0 || !$incident->getFromDB($incidents_id) || !$incident->can($incidents_id, READ)) {
            return ['error' => sprintf('There is no major incident %d that you can see.', $incidents_id)];
        }

        // `$create = false`, always. See the class docblock: the default
        // creates the review row, and a question must not leave a draft
        // behind.
        $pir = Pir::forIncident($incidents_id, (int) $incident->fields['entities_id'], false);

        if ($pir === null) {
            return [
                'incident' => ['id' => $incidents_id, 'title' => (string) $incident->fields['name']],
                'note'     => 'No post-incident review has been started for this incident, so '
                    . 'nothing has been agreed. That is itself the answer to "what came out of '
                    . 'it".',
            ];
        }

        $open_only = strtolower((string) ($arguments['open_only'] ?? 'no')) === 'yes';
        $actions   = [];
        $overdue   = 0;
        $today     = date('Y-m-d');

        foreach (PirAction::forPir((int) $pir['id']) as $row) {
            $status = (string) $row['status'];

            if ($open_only && $status !== PirAction::OPEN) {
                continue;
            }

            $due = (string) ($row['due_date'] ?? '');
            $due = $due === '' || str_starts_with($due, '0000') ? null : substr($due, 0, 10);

            if ($status === PirAction::OPEN && $due !== null && $due < $today) {
                $overdue++;
            }

            $actions[] = array_filter([
                'action'  => self::text($row['content'] ?? ''),
                'owner'   => self::userName((int) ($row['users_id_owner'] ?? 0)),
                'due'     => $due,
                'status'  => PirAction::statuses()[$status] ?? $status,
                'overdue' => $status === PirAction::OPEN && $due !== null && $due < $today ?: null,
                'raised_as_improvement' => (int) ($row['improve_candidates_id'] ?? 0) ?: null,
            ], static fn($v): bool => $v !== null && $v !== '');
        }

        return array_filter([
            'incident' => ['id' => $incidents_id, 'title' => (string) $incident->fields['name']],
            'review'   => array_filter([
                'status'        => (string) $pir['status'],
                'completed_on'  => (string) ($pir['date_completed'] ?? '') ?: null,
                'what_happened' => self::text($pir['what_happened'] ?? ''),
                'impact'        => self::text($pir['impact'] ?? ''),
                'root_cause'    => self::text($pir['root_cause'] ?? ''),
                'problem'       => (int) ($pir['problems_id'] ?? 0) ?: null,
            ], static fn($v): bool => $v !== null && $v !== ''),
            'actions'  => $actions,
            'note'     => $overdue > 0
                ? sprintf(
                    '%d agreed action(s) are open and past their due date. Say so — an incident '
                    . 'whose actions never happened is one that will happen again.',
                    $overdue
                )
                : ($actions === [] ? 'The review exists but nothing was agreed to do.' : null),
        ], static fn($v): bool => $v !== null && $v !== []);
    }

    // ---------------------------------------------------------- maintenance

    private static function maintenance(): Tool
    {
        return new Tool(
            name: 'major_maintenance',
            description: 'Planned maintenance already announced publicly: the '
                . 'windows announced on their status page, when each runs and whether it is '
                . 'under way right now. Check it before diagnosing anything that started at a '
                . 'suspiciously round time, and before telling anyone that something is '
                . 'unexpectedly down — announced work that a technician treats as an incident '
                . 'is a wasted afternoon and an unnecessary apology.',
            schema: [
                'type'       => 'object',
                'properties' => [
                    'entity' => [
                        'type'        => 'integer',
                        'description' => 'The entity. Omit for the conversation\'s own.',
                    ],
                ],
            ],
            handler: [self::class, 'runMaintenance'],
            right: 'plugin_glpimajor_declare',
            source: 'glpimajor',
            pinned: false
        );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public static function runMaintenance(array $arguments = [], mixed $context = null): array
    {
        $entity = array_key_exists('entity', $arguments)
            ? (int) $arguments['entity']
            : ($context instanceof \GlpiPlugin\Glpiai\ToolContext ? $context->entities_id : 0);

        if (!Session::haveAccessToEntity($entity)) {
            return ['error' => sprintf('Entity %d is not one you can see.', $entity)];
        }

        $now  = date('Y-m-d H:i:s');
        $rows = [];

        foreach (Maintenance::upcomingFor($entity) as $row) {
            // Every row again through the session, for the reason the incident
            // sweep does it: upcomingFor() resolves the tree from the entity
            // asked about, and the argument must never widen what may be seen.
            if (!Session::haveAccessToEntity((int) $row['entities_id'], (bool) $row['is_recursive'])) {
                continue;
            }

            $start = (string) $row['date_start'];
            $end   = (string) $row['date_end'];

            $rows[] = array_filter([
                'title'    => (string) $row['name'],
                'from'     => $start,
                'to'       => $end,
                'state'    => (string) $row['state'],
                'running_now' => $start <= $now && $end >= $now ?: null,
                'entity'   => (string) \Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']),
                'detail'   => self::text($row['content'] ?? ''),
            ], static fn($v): bool => $v !== null && $v !== '');
        }

        $running = array_filter($rows, static fn(array $r): bool => !empty($r['running_now']));

        return [
            'maintenance' => $rows,
            'note'        => $running !== []
                ? 'Maintenance is running right now. Anything reported as broken in this window '
                    . 'is announced work until proven otherwise — check before declaring an '
                    . 'incident.'
                : ($rows === []
                    ? 'Nothing is announced for this entity. Work nobody announced can still '
                        . 'be under way — this is the public calendar, not the change '
                        . 'schedule. Use change_calendar for that.'
                    : 'Announced and upcoming, soonest first.'),
        ];
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
