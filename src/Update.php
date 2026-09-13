<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

/**
 * The comms log: what we said, to whom, and what we believed at the time.
 *
 * Deliberately not the ticket timeline. A public update has an audience, it
 * is versioned against the state we believed when we wrote it, and it is the
 * source for a published artefact — a followup is none of those. Putting these
 * in the timeline would also mean "any joy with the switch?" sat in the same
 * list as the thing a reader is refreshing every ninety seconds.
 *
 * `state_at_time` is not redundant with the incident's current state. Somebody
 * reading the history six months later needs to know what we believed *then*,
 * or the sequence reads as though we knew the cause from the first minute.
 */
final class Update
{
    public const TABLE = 'glpi_plugin_glpimajor_updates';

    public const INTERNAL = 'internal';

    /**
     * The published audience. The stored value is still `customer`: it is what
     * every existing row in `audience` holds, and renaming a constant is not
     * worth a migration.
     */
    public const EXTERNAL = 'customer';

    /** @return array<string,string> */
    public static function audiences(): array
    {
        return [
            self::INTERNAL => __('Internal only', 'glpimajor'),
            self::EXTERNAL => __('Public', 'glpimajor'),
        ];
    }

    /**
     * Publish an update.
     *
     * @param array<string,mixed>|null $review the findings shown to the author before they
     *                                         pressed publish, or null if none were
     */
    public static function publish(
        Incident $incident,
        string $audience,
        string $content,
        ?array $review = null,
        ?string $reviewed_text = null
    ): int|false {
        /** @var \DBmysql $DB */
        global $DB;

        $content = trim($content);
        if ($content === '') {
            return false;
        }

        $audience = $audience === self::EXTERNAL ? self::EXTERNAL : self::INTERNAL;

        // "Heeded" means the author changed what they were going to say after
        // reading the findings. It is the only observable that distinguishes a
        // review somebody read from a review somebody clicked past — and it is
        // only meaningful next to the text that was actually published, which
        // is why it lives on this row rather than in a log of its own.
        $heeded = 0;
        if ($review !== null && $reviewed_text !== null) {
            $heeded = trim($reviewed_text) !== $content ? 1 : 0;
        }

        $DB->insert(self::TABLE, [
            'plugin_glpimajor_incidents_id' => (int) $incident->getID(),
            'entities_id'                   => (int) $incident->fields['entities_id'],
            'audience'                      => $audience,
            'content'                       => $content,
            'users_id'                      => (int) \Session::getLoginUserID(),
            'state_at_time'                 => (string) $incident->fields['state'],
            'next_update_at'                => $incident->fields['next_update_at'] ?? null,
            'ai_reviewed'                   => $review !== null ? 1 : 0,
            'ai_findings'                   => $review !== null
                ? json_encode($review, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'ai_heeded'                     => $heeded,
            'date_reviewed'                 => $review !== null ? date('Y-m-d H:i:s') : null,
            'date_creation'                 => date('Y-m-d H:i:s'),
        ]);

        // insert() returns true, not the id; insertId() is where the id is.
        $id = (int) $DB->insertId();
        if ($id <= 0) {
            return false;
        }

        Events::record(
            (int) $incident->getID(),
            Events::UPDATE,
            $audience,
            (int) $incident->fields['entities_id']
        );

        if ($review !== null) {
            Events::record(
                (int) $incident->getID(),
                Events::REVIEW,
                $heeded === 1 ? 'findings acted on' : 'findings shown, text unchanged',
                (int) $incident->fields['entities_id']
            );
        }

        if ($audience === self::EXTERNAL) {
            Notifications::raise('published', (int) $incident->getID());
            Publisher::onChange((int) $incident->fields['entities_id']);
        }

        return $id;
    }

    /**
     * The whole log for an incident, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forIncident(int $incidents_id, ?string $audience = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $where = ['plugin_glpimajor_incidents_id' => $incidents_id];
        if ($audience !== null) {
            $where['audience'] = $audience;
        }

        $out = [];
        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => $where,
                'ORDER' => ['date_creation DESC', 'id DESC'],
                'LIMIT' => 500,
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
    }

    /** The most recent public update, for the incident list. */
    public static function latestCustomer(int $incidents_id): ?array
    {
        return self::forIncident($incidents_id, self::EXTERNAL)[0] ?? null;
    }
}
