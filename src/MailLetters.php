<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

use GlpiPlugin\Glpimail\Letter;

/**
 * What a major-incident notification looks like when glpi-mail is installed.
 *
 * Registered under `glpimail_letters`. Without it the notification is still a
 * proper one — {@see Notifications} seeds a template and GLPI's own event
 * machinery renders and delivers it — but the body is the plain `<ul>` this
 * plugin writes at install, and glpi-mail can only *wrap* that. Describing it
 * here means an outage announcement looks like an outage announcement: the
 * state as a pill, the promised next update where the eye lands, and a button
 * rather than a URL in a paragraph.
 *
 * One template, four events. The eyebrow is `##incident.action##`, which is the
 * event's own name — "Major incident declared", "Update overdue" — so one
 * description covers all four rather than four near-identical copies drifting
 * apart. That is the same argument {@see Notifications::template()} makes for
 * seeding one template instead of four.
 *
 * glpi-mail is optional; the `class_exists()` guard keeps a missing optional
 * dependency from becoming a fatal in anything that walks `$PLUGIN_HOOKS`.
 */
final class MailLetters
{
    /** @return array<int,array<string,mixed>> */
    public static function offers(): array
    {
        if (!class_exists(Letter::class)) {
            return [];
        }

        return [
            [
                'name'     => 'Major incident',
                'itemtype' => Incident::class,
                'summary'  => __('Declared, resolved, update published and update overdue — the '
                    . 'four things worth telling somebody about.', 'glpimajor'),
                'build'    => [self::class, 'incident'],
            ],
        ];
    }

    public static function incident(): Letter
    {
        return Letter::make()
            ->eyebrow('##incident.action##')
            ->title('##incident.title##')
            // Amber rather than red, deliberately. Every one of these is about
            // an outage, so a red pill on all four carries no information; what
            // the reader needs at a glance is *which* state, and the word does
            // that.
            ->pill('##incident.state##', Letter::WARN)
            ->button('Open the incident', '##incident.url##')
            ->row('##lang.incident.commander##', '##incident.commander##', when: 'incident.commander')
            ->row('##lang.incident.comms##', '##incident.comms##', when: 'incident.comms')
            ->row('##lang.incident.declared##', '##incident.declared##')
            ->row('##lang.incident.resolved##', '##incident.resolved##', when: 'incident.resolved')
            ->row('##lang.incident.next_update##', '##incident.next_update##', when: 'incident.next_update')
            ->row('##lang.incident.affected##', '##incident.affected##', when: 'incident.affected')
            ->row('##lang.incident.ticket##', '##incident.ticket##', when: 'incident.ticket')
            // The two long-form fields, each only when it has something in it.
            // An "Outcome" heading over an empty panel on a still-open incident
            // reads as somebody having deleted the outcome.
            ->when(
                'incident.latest_update',
                static fn(Letter $letter) => $letter->panel(
                    '##lang.incident.latest_update##',
                    '##incident.latest_update##'
                )
            )
            ->when(
                'incident.outcome',
                static fn(Letter $letter) => $letter->panel(
                    '##lang.incident.outcome##',
                    '##incident.outcome##'
                )
            )
            // Only on the overdue event, where it is the entire point of the
            // message and the reason it went to the comms owner alone.
            ->when(
                'incident.overdue_minutes',
                static fn(Letter $letter) => $letter->note('The promised update is '
                    . '##incident.overdue_minutes## minutes overdue.')
            );
    }
}
