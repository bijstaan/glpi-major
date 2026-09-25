<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

use Glpi\Api\HL\Controller\AbstractController;
use Glpi\Api\HL\Route;
use Glpi\Api\HL\RouteVersion;
use Glpi\Http\JSONResponse;
use Glpi\Http\Request;
use Glpi\Http\Response;
use Session;
use Ticket;

/**
 * Major-incident mode for the technician app.
 *
 * The same authority map as the web UI, route for route: reading wants the
 * declare right's READ half (front/incident.php), acting on an incident wants
 * UPDATE on that incident (ajax/major.php), declaring wants CREATE plus UPDATE
 * on the ticket being promoted (front/incident.form.php). And like both the
 * banner and the ajax endpoint, everything here is central-interface only — a
 * requester's phone gets the status page, not the role assignments.
 */
#[Route(path: '/GlpiMajor', tags: ['GlpiMajor'])]
final class MobileController extends AbstractController
{
    protected static function getRawKnownSchemas(string $api_version = ''): array
    {
        return [];
    }

    /** Optional-parameter read: core's getParameter() warns on absent keys. */
    private static function param(Request $request, string $name, mixed $default = null): mixed
    {
        return $request->hasParameter($name) ? $request->getParameter($name) : $default;
    }

    /**
     * Open incidents the session can see.
     *
     * `Incident::allOpen()` filtered by the session's entity scope — the same
     * answer per row as `can(id, READ)`, without N getFromDB round trips.
     */
    #[Route(path: '/incidents', methods: ['GET'])]
    #[RouteVersion(introduced: '2.0')]
    public function listIncidents(Request $request): Response
    {
        $denied = self::requireCentral(Incident::$rightname, READ);
        if ($denied !== null) {
            return $denied;
        }

        $rows = [];
        foreach (Incident::allOpen() as $row) {
            if (!Session::haveAccessToEntity((int) $row['entities_id'], (bool) $row['is_recursive'])) {
                continue;
            }
            $rows[] = self::incidentRow($row);
        }

        return new JSONResponse(['incidents' => $rows], 200);
    }

    /** One incident, with its whole comms log. */
    #[Route(path: '/incidents/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[RouteVersion(introduced: '2.0')]
    public function getIncident(Request $request): Response
    {
        $denied = self::requireCentral(Incident::$rightname, READ);
        if ($denied !== null) {
            return $denied;
        }

        $incident = self::load((int) $request->getAttribute('id'));
        if ($incident === null) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }

        return new JSONResponse(self::incidentDetail($incident), 200);
    }

    /**
     * The major-incident picture from a ticket's point of view: the incident
     * it *is* (if declared on it), the incident it is *attached* to, and — for
     * an ordinary ticket — the one attach offer Banner's matcher would make.
     */
    #[Route(path: '/ticket/{tickets_id}', methods: ['GET'], requirements: ['tickets_id' => '\d+'])]
    #[RouteVersion(introduced: '2.0')]
    public function forTicket(Request $request): Response
    {
        $denied = self::requireCentral(Incident::$rightname, READ);
        if ($denied !== null) {
            return $denied;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB((int) $request->getAttribute('tickets_id')) || !$ticket->canViewItem()) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }

        $tickets_id = (int) $ticket->getID();
        $out        = ['incident' => null, 'attached_to' => null, 'offer_attach' => []];

        $own = Incident::forTicket($tickets_id);
        if ($own !== null) {
            $out['incident'] = self::incidentRow($own);

            return new JSONResponse($out, 200);
        }

        $attached = Affected::incidentFor($tickets_id);
        if ($attached !== null) {
            $incident = self::load((int) $attached['plugin_glpimajor_incidents_id']);
            if ($incident !== null) {
                $out['attached_to'] = self::incidentRow($incident->fields);
            }

            return new JSONResponse($out, 200);
        }

        $out['offer_attach'] = self::offer($ticket);

        return new JSONResponse($out, 200);
    }

    /**
     * Declare: promote a ticket. Body `{tickets_id, title, commander, comms}`.
     *
     * front/incident.form.php's declare branch, exactly: the CREATE right, the
     * ticket read back and UPDATE-checked, and everything about the incident
     * (entity, default title) taken from the ticket rather than the caller.
     */
    #[Route(path: '/incidents', methods: ['POST'])]
    #[RouteVersion(introduced: '2.0')]
    public function declare(Request $request): Response
    {
        $denied = self::requireCentral(Incident::$rightname, CREATE);
        if ($denied !== null) {
            return $denied;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB((int) self::param($request, 'tickets_id', 0))) {
            return new JSONResponse(['error' => 'ticket_not_found'], 404);
        }
        if (!$ticket->can($ticket->getID(), UPDATE)) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }

        $id = Incident::declareFor($ticket, [
            'name'               => (string) self::param($request, 'title', ''),
            'users_id_commander' => (int) self::param($request, 'commander', 0),
            'users_id_comms'     => (int) self::param($request, 'comms', 0),
            'is_recursive'       => !empty(self::param($request, 'is_recursive')) ? 1 : 0,
        ]);

        if ($id === false) {
            // declareFor() refuses exactly one thing besides a failed insert:
            // the ticket is already a major incident.
            return new JSONResponse(['error' => 'already_declared_or_failed'], 409);
        }

        $incident = self::load($id);

        return new JSONResponse($incident !== null ? self::incidentDetail($incident) : ['id' => $id], 201);
    }

    /** Publish an update. Body `{audience, content}` — ajax/major.php's `publish`. */
    #[Route(path: '/incidents/{id}/updates', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[RouteVersion(introduced: '2.0')]
    public function publishUpdate(Request $request): Response
    {
        $denied = self::requireCentral(Incident::$rightname, READ);
        if ($denied !== null) {
            return $denied;
        }

        $incident = self::load((int) $request->getAttribute('id'));
        if ($incident === null) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }
        if (!$incident->can((int) $incident->getID(), UPDATE)) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }

        $id = Update::publish(
            $incident,
            (string) self::param($request, 'audience', Update::INTERNAL),
            (string) self::param($request, 'content', '')
        );

        if ($id === false) {
            return new JSONResponse(['error' => 'empty_content'], 400);
        }

        foreach (Update::forIncident((int) $incident->getID()) as $row) {
            if ((int) $row['id'] === $id) {
                return new JSONResponse(self::updateRow($row), 201);
            }
        }

        return new JSONResponse(['id' => $id], 201);
    }

    /**
     * Attach a ticket to an incident. Body `{tickets_id}`.
     *
     * ajax/major.php's `attach` action, exactly: the declare right's UPDATE
     * half, UPDATE on the ticket being attached, READ on the incident — and
     * then Affected::attach(), which owns the tenant boundary and the refusal
     * of the incident's own declaring ticket. The two refusals a caller can
     * trip are named rather than folded into one boolean, because the app has
     * to tell the technician *why* nothing happened.
     */
    #[Route(path: '/incidents/{id}/tickets', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[RouteVersion(introduced: '2.0')]
    public function attachTicket(Request $request): Response
    {
        $denied = self::requireCentral(Incident::$rightname, UPDATE);
        if ($denied !== null) {
            return $denied;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB((int) self::param($request, 'tickets_id', 0))) {
            return new JSONResponse(['error' => 'ticket_not_found'], 404);
        }
        if (!$ticket->can($ticket->getID(), UPDATE)) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }

        $incident = self::load((int) $request->getAttribute('id'));
        if ($incident === null) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }

        $incidents_id = (int) $incident->getID();
        $tickets_id   = (int) $ticket->getID();

        // The declaring ticket is already the incident — it is counted and
        // shown without a row in the attach table, and a row for it would
        // propose the incident's resolution back onto its own ticket.
        if ((int) $incident->fields['tickets_id'] === $tickets_id) {
            return new JSONResponse(['error' => 'declaring_ticket'], 400);
        }

        // The tenant boundary, named. attach() re-checks this on the way in —
        // this pre-check exists so the refusal is a word, not a false.
        if (!Affected::inScope($incident, (int) $ticket->fields['entities_id'])) {
            return new JSONResponse(['error' => 'out_of_scope'], 409);
        }

        // A ticket that is already attached comes back true, which is a 200
        // here for the same reason it is a success in the web UI: a second tap
        // on a slow connection is not an error worth showing anybody.
        if (!Affected::attach($incidents_id, $tickets_id)) {
            return new JSONResponse(['error' => 'attach_refused'], 409);
        }

        $incident->getFromDB($incidents_id);

        return new JSONResponse(self::incidentDetail($incident), 200);
    }

    /**
     * Edit the mode: any of `{state, next_update_at, title}` (plus `outcome`,
     * which resolving may require). Each goes through the same method the web
     * form uses — setState(), promise(), retitle() — never a raw column write.
     */
    #[Route(path: '/incidents/{id}', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[RouteVersion(introduced: '2.0')]
    public function patchIncident(Request $request): Response
    {
        $denied = self::requireCentral(Incident::$rightname, READ);
        if ($denied !== null) {
            return $denied;
        }

        $incident = self::load((int) $request->getAttribute('id'));
        if ($incident === null) {
            return new JSONResponse(['error' => 'not_found'], 404);
        }
        if (!$incident->can((int) $incident->getID(), UPDATE)) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }

        $touched = false;

        $title = self::param($request, 'title');
        if ($title !== null) {
            if (!$incident->retitle((string) $title)) {
                return new JSONResponse(['error' => 'empty_title'], 400);
            }
            $touched = true;
        }

        // Distinguish "absent" from "null": sending null clears the promise,
        // exactly as ajax/major.php's minutes=0 does.
        if ($request->hasParameter('next_update_at')) {
            $raw  = $request->getParameter('next_update_at');
            $when = null;
            if ($raw !== null && $raw !== '') {
                $stamp = strtotime((string) $raw);
                if ($stamp === false) {
                    return new JSONResponse(['error' => 'bad_next_update_at'], 400);
                }
                $when = date('Y-m-d H:i:s', $stamp);
            }
            if (!$incident->promise($when)) {
                return new JSONResponse(['error' => 'promise_failed'], 422);
            }
            $touched = true;
        }

        $state = self::param($request, 'state');
        if ($state !== null) {
            $ok = $incident->setState(
                (string) $state,
                (string) (self::param($request, 'outcome') ?? (string) $incident->fields['outcome'])
            );
            if (!$ok) {
                // Either an unknown state, or resolving without the outcome the
                // instance's settings insist on.
                return new JSONResponse(['error' => 'state_refused'], 422);
            }
            $touched = true;
        }

        if (!$touched) {
            return new JSONResponse(['error' => 'no_fields'], 400);
        }

        $incident->getFromDB((int) $incident->getID());

        return new JSONResponse(self::incidentDetail($incident), 200);
    }

    // --- Helpers ---

    /** 401/403 unless a central-interface session holding the right. */
    private static function requireCentral(string $right, int $level): ?Response
    {
        if ((int) Session::getLoginUserID() <= 0) {
            return new JSONResponse(['error' => 'unauthenticated'], 401);
        }
        // Banner and ajax/major.php both stop at the interface line: the
        // requester-facing surface of this plugin is the status page, only.
        if (Session::getCurrentInterface() !== 'central') {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }
        if (!Session::haveRight($right, $level)) {
            return new JSONResponse(['error' => 'forbidden'], 403);
        }

        return null;
    }

    /** The incident, entity-checked; null reads as 404 upstream. */
    private static function load(int $id): ?Incident
    {
        $incident = new Incident();
        if ($id <= 0 || !$incident->getFromDB($id) || !$incident->can($id, READ)) {
            return null;
        }

        return $incident;
    }

    /**
     * The one attach offer Banner would draw, as `[{id, title}]` or `[]`.
     *
     * Same gates in the same order: the setting, the rights, the ticket still
     * being live, and then the matcher picking one incident or none.
     *
     * @return array<int,array{id:int,title:string}>
     */
    private static function offer(Ticket $ticket): array
    {
        if (!Settings::flag('match_enabled')) {
            return [];
        }
        if (!Session::haveRight(Incident::$rightname, UPDATE) || !$ticket->canUpdateItem()) {
            return [];
        }
        if (
            in_array((int) $ticket->fields['status'], $ticket->getSolvedStatusArray(), true)
            || in_array((int) $ticket->fields['status'], $ticket->getClosedStatusArray(), true)
        ) {
            return [];
        }

        $entities_id = (int) $ticket->fields['entities_id'];
        $open        = Incident::openFor($entities_id);
        if ($open === []) {
            return [];
        }

        $best = Matcher::best(
            [
                'id'                => (int) $ticket->getID(),
                'entities_id'       => $entities_id,
                'ancestors'         => Tree::ancestors($entities_id),
                'itilcategories_id' => (int) $ticket->fields['itilcategories_id'],
                'locations_id'      => (int) ($ticket->fields['locations_id'] ?? 0),
                'name'              => (string) $ticket->fields['name'],
            ],
            $open,
            Settings::matchRules()
        );

        if ($best === null) {
            return [];
        }

        return [[
            'id'    => (int) $best['incident']['id'],
            'title' => (string) $best['incident']['name'],
        ]];
    }

    /**
     * The list row.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function incidentRow(array $row): array
    {
        $commander = (int) $row['users_id_commander'];

        return [
            'id'             => (int) $row['id'],
            'title'          => (string) $row['name'],
            'state'          => (string) $row['state'],
            'tickets_id'     => (int) $row['tickets_id'],
            'is_recursive'   => (bool) $row['is_recursive'],
            'entity'         => [
                'id'   => (int) $row['entities_id'],
                'name' => \Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']),
            ],
            'commander'      => [
                'id'   => $commander,
                'name' => $commander > 0 ? \getUserName($commander) : '',
            ],
            'declared_at'    => (string) $row['date_declared'],
            'next_update_at' => $row['next_update_at'] !== null ? (string) $row['next_update_at'] : null,
        ];
    }

    /** The row plus everything the incident screen shows. */
    private static function incidentDetail(Incident $incident): array
    {
        $f     = $incident->fields;
        $comms = (int) $f['users_id_comms'];

        $out                   = self::incidentRow($f);
        $out['comms']          = ['id' => $comms, 'name' => $comms > 0 ? \getUserName($comms) : ''];
        $out['outcome']        = (string) ($f['outcome'] ?? '');
        $out['resolved_at']    = $f['date_resolved'] !== null ? (string) $f['date_resolved'] : null;
        $out['affected_count'] = (int) $f['affected_count'];
        $out['updates']        = array_map(
            static fn(array $row): array => self::updateRow($row),
            Update::forIncident((int) $incident->getID())
        );
        // Additive (0.1.2): the unified feed the war room shows — updates,
        // recorded state transitions and the declaration, oldest first. The
        // `updates` array above keeps its exact shape and order; a client
        // that has not learned `timeline` loses nothing, and one that has can
        // drop its own interleaving.
        $out['timeline']       = array_map(
            static fn(array $entry): array => self::timelineRow($entry),
            Feed::forIncident($incident)
        );
        // Additive (0.1.3): the public post-mortem. Null when none has been
        // written. Drafts are included, marked `is_published: false` — every
        // caller here holds the same READ right the war room needs, and the
        // app showing "there is a draft" is the war-room panel's parity, not
        // a leak; what the *public* sees is decided by Publisher, which
        // supplies only published text. `published_at` is null for a draft.
        $out['postmortem']     = self::postmortemRow((int) $incident->getID());

        return $out;
    }

    /** The post-mortem in the app's vocabulary, or null when none exists. */
    private static function postmortemRow(int $incidents_id): ?array
    {
        $pm = Postmortem::forIncident($incidents_id);
        if ($pm === null || trim((string) ($pm['content'] ?? '')) === '') {
            return null;
        }

        $author = (int) ($pm['users_id_author'] ?? 0);

        return [
            'content'      => (string) $pm['content'],
            'is_published' => (int) ($pm['is_published'] ?? 0) === 1,
            'published_at' => $pm['published_at'] !== null ? (string) $pm['published_at'] : null,
            'ai_drafted'   => (int) ($pm['ai_drafted'] ?? 0) === 1,
            'author'       => ['id' => $author, 'name' => $author > 0 ? \getUserName($author) : ''],
        ];
    }

    /**
     * One feed entry, in the app's vocabulary.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private static function timelineRow(array $entry): array
    {
        $author = (int) $entry['users_id'];
        $out    = [
            'kind'   => (string) $entry['kind'],
            'at'     => (string) $entry['at'],
            'author' => ['id' => $author, 'name' => $author > 0 ? \getUserName($author) : ''],
        ];

        switch ($entry['kind']) {
            case Feed::UPDATE:
                $out['update'] = self::updateRow($entry['update']);
                break;
            case Feed::STATE:
                $out['from']    = (string) $entry['from'];
                $out['to']      = (string) $entry['to'];
                $out['outcome'] = (string) ($entry['outcome'] ?? '');
                break;
            case Feed::DECLARED:
                $out['tickets_id'] = (int) $entry['tickets_id'];
                $out['state']      = (string) $entry['state'];
                break;
        }

        return $out;
    }

    /**
     * One comms-log entry.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function updateRow(array $row): array
    {
        $author = (int) $row['users_id'];

        return [
            'id'         => (int) $row['id'],
            'audience'   => (string) $row['audience'],
            'content'    => (string) $row['content'],
            'author'     => ['id' => $author, 'name' => $author > 0 ? \getUserName($author) : ''],
            'created_at' => (string) $row['date_creation'],
        ];
    }
}
