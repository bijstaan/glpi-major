<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

/**
 * The seam to an improvement register (glpi-improve).
 *
 * That plugin does not exist yet, which is exactly why this file is written the
 * way it is: one documented method name, checked with class_exists and
 * method_exists, and a payload whose shape is written down in the README so the
 * other side has something to implement against. Trying three names in turn
 * would be a guess wearing a contract's clothes, and an interface would need a
 * class that is not there to implement it.
 *
 * Nothing in this plugin depends on the call succeeding. A PIR action that was
 * never pushed is a PIR action, and the register is a nice-to-have on top.
 *
 * The contract:
 *
 *   GlpiPlugin\Glpiimprove\Candidate::fromPlugin(array $payload): int|false
 *
 *   $payload = [
 *     'source'        => 'glpimajor',        // always
 *     'source_key'    => 'piraction:<id>',   // stable, for idempotent re-push
 *     'entities_id'   => int,
 *     'title'         => string,             // the action, one line
 *     'description'   => string,             // context: what happened, and the impact
 *     'users_id'      => int,                // proposed owner, 0 if none
 *     'due_date'      => string|null,        // 'Y-m-d'
 *     'itemtype'      => 'GlpiPlugin\Glpimajor\Incident',
 *     'items_id'      => int,                // the incident it came from
 *   ]
 *
 * Returning the candidate's id lets us record it and stop offering the same
 * action twice; returning false is taken as "not accepted", not as an error.
 */
final class Improve
{
    private const CANDIDATE = 'GlpiPlugin\\Glpiimprove\\Candidate';
    private const METHOD    = 'fromPlugin';

    /** Is there anything on the other end of this seam? */
    public static function available(): bool
    {
        return class_exists(self::CANDIDATE) && method_exists(self::CANDIDATE, self::METHOD);
    }

    /**
     * Offer one PIR action to the register.
     *
     * @return int|null the candidate id, or null when the register is absent,
     *                  switched off, or declined it
     */
    public static function offer(array $action, int $entities_id): ?int
    {
        if (!Settings::flag('improve_push') || !self::available()) {
            return null;
        }

        $pir = Pir::byId((int) ($action['plugin_glpimajor_pirs_id'] ?? 0));
        if ($pir === null) {
            return null;
        }

        $incidents_id = (int) $pir['plugin_glpimajor_incidents_id'];

        $incident = new Incident();
        $title    = $incident->getFromDB($incidents_id) ? (string) $incident->fields['name'] : '';

        $payload = [
            'source'      => 'glpimajor',
            'source_key'  => 'piraction:' . (int) $action['id'],
            'entities_id' => $entities_id,
            'title'       => mb_substr(trim((string) $action['content']), 0, 255),
            'description' => self::context($pir, $title),
            'users_id'    => (int) ($action['users_id_owner'] ?? 0),
            'due_date'    => $action['due_date'] ?? null,
            'itemtype'    => Incident::class,
            'items_id'    => $incidents_id,
        ];

        try {
            /** @var callable $call */
            $call   = [self::CANDIDATE, self::METHOD];
            $result = $call($payload);
        } catch (\Throwable $e) {
            // A neighbouring plugin throwing is its problem, not a reason for
            // this one's page to break.
            trigger_error(
                'glpimajor: the improvement register refused a candidate: ' . $e->getMessage(),
                E_USER_WARNING
            );

            return null;
        }

        if (!is_int($result) && !ctype_digit((string) $result)) {
            return null;
        }

        $candidates_id = (int) $result;
        if ($candidates_id <= 0) {
            return null;
        }

        PirAction::recordPush((int) $action['id'], $candidates_id);

        Events::record(
            $incidents_id,
            Events::PUSHED,
            sprintf('action #%d -> candidate #%d', (int) $action['id'], $candidates_id),
            $entities_id
        );

        return $candidates_id;
    }

    /**
     * What the register needs to know that the one-line action does not say.
     *
     * An action read six months later out of a list has no context at all;
     * carrying the incident's title and its impact across is the difference
     * between "add monitoring to the UPS" and a decision somebody can weigh.
     */
    private static function context(array $pir, string $incident_title): string
    {
        $parts = [];

        if ($incident_title !== '') {
            $parts[] = sprintf(__('From the major incident: %s', 'glpimajor'), $incident_title);
        }

        foreach (
            [
                'what_happened' => __('What happened', 'glpimajor'),
                'impact'        => __('Impact', 'glpimajor'),
                'root_cause'    => __('Root cause', 'glpimajor'),
            ] as $field => $label
        ) {
            $value = trim((string) ($pir[$field] ?? ''));
            if ($value !== '') {
                $parts[] = $label . ": " . $value;
            }
        }

        return implode("\n\n", $parts);
    }
}
