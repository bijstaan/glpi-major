<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

/**
 * The post-incident review.
 *
 * Its timeline is assembled rather than typed. Everything that happened to the
 * incident is already recorded — the update log says what we told people and
 * when, the audit rows say what we did — so asking somebody to retype it a week
 * later produces a worse version of a record we already have, and a shorter one
 * every time it is done under time pressure.
 *
 * Completion locks it, and unlocking is recorded. glpi-sop's reasoning, and it
 * holds here for the same reason: a record nobody can correct grows wrong, and
 * a record anybody can silently rewrite was never a record. The lock is a speed
 * bump with a key, not a permission.
 */
final class Pir
{
    public const TABLE = 'glpi_plugin_glpimajor_pirs';

    public const DRAFT    = 'draft';
    public const COMPLETE = 'complete';

    /**
     * The review for an incident, created empty on first look.
     *
     * `$create` exists because this is called while *rendering* a page. A
     * read-only visitor opening an incident must not cause a row to be written
     * — a GET that writes is a GET that shows up in the history of a record
     * somebody only looked at.
     */
    public static function forIncident(int $incidents_id, int $entities_id = 0, bool $create = true): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['plugin_glpimajor_incidents_id' => $incidents_id],
                'LIMIT' => 1,
            ]) as $row
        ) {
            return $row;
        }

        if (!$create) {
            return null;
        }

        if ($entities_id <= 0 && $incidents_id > 0) {
            $incident = new Incident();
            if ($incident->getFromDB($incidents_id)) {
                $entities_id = (int) $incident->fields['entities_id'];
            }
        }

        $now = date('Y-m-d H:i:s');

        $DB->insert(self::TABLE, [
            'plugin_glpimajor_incidents_id' => $incidents_id,
            'entities_id'                   => $entities_id,
            'status'                        => self::DRAFT,
            'date_creation'                 => $now,
            'date_mod'                      => $now,
        ]);

        // `DBmysql::insert()` returns *true*, not the new id — it throws on
        // failure rather than reporting one. Reading its return as an id gives
        // `(int) true`, which is 1, so this used to hand back review #1: the
        // first review ever written on the instance, belonging to somebody
        // else's incident, or null once that row had been deleted. The id
        // comes from insertId().
        $id = (int) $DB->insertId();

        return $id > 0 ? self::byId($id) : null;
    }

    public static function byId(int $id): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $row
        ) {
            return $row;
        }

        return null;
    }

    public static function isLocked(array $pir): bool
    {
        return Settings::flag('pir_lock_on_complete')
            && (string) $pir['status'] === self::COMPLETE
            && (int) ($pir['is_unlocked'] ?? 0) !== 1;
    }

    public static function isEditable(array $pir): bool
    {
        return \Session::haveRight(Incident::$rightname, UPDATE) && !self::isLocked($pir);
    }

    public static function save(int $id, array $input): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $pir = self::byId($id);
        if ($pir === null || !self::isEditable($pir)) {
            return false;
        }

        $ok = $DB->update(self::TABLE, [
            'what_happened' => trim((string) ($input['what_happened'] ?? '')),
            'impact'        => trim((string) ($input['impact'] ?? '')),
            'root_cause'    => trim((string) ($input['root_cause'] ?? '')),
            'problems_id'   => max(0, (int) ($input['problems_id'] ?? 0)),
            'date_mod'      => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        if ($ok) {
            Events::record(
                (int) $pir['plugin_glpimajor_incidents_id'],
                Events::PIR_SAVED,
                '',
                (int) $pir['entities_id']
            );
        }

        return (bool) $ok;
    }

    /**
     * Mark it done.
     *
     * Refused while the first question is unanswered. A review that says nothing
     * about what happened is a row that makes a report look complete, which is
     * worse than an obviously missing one.
     */
    public static function complete(int $id): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $pir = self::byId($id);
        if ($pir === null || !self::isEditable($pir)) {
            return false;
        }

        if (trim((string) ($pir['what_happened'] ?? '')) === '') {
            \Session::addMessageAfterRedirect(
                __s('Say what happened before completing the review.', 'glpimajor'),
                false,
                ERROR
            );

            return false;
        }

        $ok = $DB->update(self::TABLE, [
            'status'            => self::COMPLETE,
            // Completing re-arms the lock. A review that was unlocked, edited
            // and completed again is a finished review like any other; leaving
            // it unlocked because somebody opened it an hour ago would make the
            // lock depend on history nobody can see.
            'is_unlocked'       => 0,
            'date_completed'    => date('Y-m-d H:i:s'),
            'users_id_complete' => (int) \Session::getLoginUserID(),
            'date_mod'          => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        if ($ok) {
            Events::record(
                (int) $pir['plugin_glpimajor_incidents_id'],
                Events::PIR_DONE,
                '',
                (int) $pir['entities_id']
            );
        }

        return (bool) $ok;
    }

    /**
     * Turn the lock.
     *
     * Deliberately does not go through isEditable(): a locked review is not
     * editable, so if unlocking had to pass that gate the lock would have no
     * key. It still takes the right that editing takes.
     */
    public static function setUnlocked(int $id, bool $unlocked): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $pir = self::byId($id);
        if ($pir === null || !\Session::haveRight(Incident::$rightname, UPDATE)) {
            return false;
        }

        $ok = $DB->update(self::TABLE, [
            'is_unlocked' => $unlocked ? 1 : 0,
            'date_mod'    => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        if ($ok) {
            Events::record(
                (int) $pir['plugin_glpimajor_incidents_id'],
                $unlocked ? Events::PIR_UNLOCK : Events::PIR_RELOCK,
                '',
                (int) $pir['entities_id']
            );
        }

        return (bool) $ok;
    }

    /**
     * The timeline, assembled.
     *
     * Two sources merged and sorted: what we told people (the update log, with
     * its audience, because "we said nothing to the customer for four hours" is
     * the finding that matters most often) and what we did (the audit rows,
     * filtered to the moments worth a line).
     *
     * @return array<int,array{at:string,kind:string,label:string,detail:string}>
     */
    public static function timeline(int $incidents_id): array
    {
        $rows = [];

        $incident = new Incident();
        if ($incident->getFromDB($incidents_id)) {
            $declared = (string) ($incident->fields['date_declared'] ?? '');
            if ($declared !== '') {
                $rows[] = [
                    'at'     => $declared,
                    'kind'   => 'event',
                    'label'  => __('Declared a major incident', 'glpimajor'),
                    'detail' => '',
                ];
            }
        }

        // Oldest first. The update log reads newest-first and PHP's sort is
        // stable, so merging it as it comes leaves two updates written in the
        // same second in the log's order — which tells that second of the story
        // backwards, and the second in which somebody wrote an internal note
        // and then told the customer is exactly the second a review is about.
        foreach (array_reverse(Update::forIncident($incidents_id)) as $update) {
            $rows[] = [
                'at'    => (string) $update['date_creation'],
                'kind'  => 'update',
                'label' => (string) $update['audience'] === Update::CUSTOMER
                    ? __('Update to the customer', 'glpimajor')
                    : __('Internal note', 'glpimajor'),
                'detail' => mb_substr(trim((string) $update['content']), 0, 300),
            ];
        }

        $notable = [
            Events::STATE, Events::ATTACHED, Events::RESOLVED,
            Events::NAGGED, Events::PROPOSED, Events::ROLE,
        ];

        foreach (Events::forIncident($incidents_id) as $event) {
            if (!in_array((string) $event['action'], $notable, true)) {
                continue;
            }
            $rows[] = [
                'at'     => (string) $event['date_creation'],
                'kind'   => 'event',
                'label'  => Events::label((string) $event['action']),
                'detail' => (string) ($event['detail'] ?? ''),
            ];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp($a['at'], $b['at']));

        return $rows;
    }
}
