<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

use Notification;
use NotificationEvent;
use NotificationTemplate;

/**
 * The four things worth telling somebody about, and how they get told.
 *
 * GLPI notifications rather than mail sent from here. Whoever runs the instance
 * has already decided how they want to be told things — which transport, which
 * templates, which language, whose address — and a second notification system
 * is a second thing to configure and a second thing to be silently broken.
 *
 * Install seeds a template and an enabled notification per event, targeted at
 * the roles that own the incident. Seeded rather than left for an administrator
 * to build, because an unconfigured notification system is indistinguishable
 * from a working one right up until an outage.
 */
final class Notifications
{
    /** @return array<string,string> event => English subject line */
    public static function events(): array
    {
        return [
            'declared'  => 'Major incident declared: ##incident.title##',
            'resolved'  => 'Major incident resolved: ##incident.title##',
            'published' => 'Update published: ##incident.title##',
            'overdue'   => 'Update overdue: ##incident.title##',
        ];
    }

    /**
     * Raise one.
     *
     * Swallows its own failures. A notification that cannot be sent must not
     * take down the declaration, the resolution or the update that was being
     * recorded — the record is the thing that matters, and the mail is a copy
     * of it.
     */
    public static function raise(string $event, int $incidents_id): void
    {
        if (!array_key_exists($event, self::events())) {
            return;
        }

        $incident = new Incident();
        if (!$incident->getFromDB($incidents_id)) {
            return;
        }

        try {
            NotificationEvent::raiseEvent($event, $incident, [
                'entities_id' => (int) $incident->fields['entities_id'],
            ]);
        } catch (\Throwable $e) {
            trigger_error(
                'glpimajor: could not raise the ' . $event . ' notification: ' . $e->getMessage(),
                E_USER_WARNING
            );
        }
    }

    // ------------------------------------------------------------- install

    public static function install(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        // The tables exist in every supported GLPI, but an install hook that
        // assumes and is wrong aborts the whole installation.
        foreach (
            [
                'glpi_notificationtemplates',
                'glpi_notificationtemplatetranslations',
                'glpi_notifications',
                'glpi_notifications_notificationtemplates',
                'glpi_notificationtargets',
            ] as $table
        ) {
            if (!$DB->tableExists($table)) {
                return;
            }
        }

        $templates_id = self::template();
        if ($templates_id <= 0) {
            return;
        }

        foreach (self::events() as $event => $subject) {
            self::notification($event, $templates_id);
        }
    }

    public static function uninstall(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!$DB->tableExists('glpi_notifications')) {
            return;
        }

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_notifications',
                'WHERE'  => ['itemtype' => Incident::class],
            ]) as $row
        ) {
            $ids[] = (int) $row['id'];
        }

        if ($ids !== []) {
            $DB->delete('glpi_notifications_notificationtemplates', ['notifications_id' => $ids]);
            $DB->delete('glpi_notificationtargets', ['notifications_id' => $ids]);
            $DB->delete('glpi_notifications', ['id' => $ids]);
        }

        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_notificationtemplates',
                'WHERE'  => ['itemtype' => Incident::class],
            ]) as $row
        ) {
            $DB->delete('glpi_notificationtemplatetranslations', [
                'notificationtemplates_id' => (int) $row['id'],
            ]);
            $DB->delete('glpi_notificationtemplates', ['id' => (int) $row['id']]);
        }
    }

    /**
     * One template for all four events.
     *
     * The body is built from tags that are meaningful for every one of them,
     * with the event's own name at the top — four near-identical templates is
     * four places to edit when the wording is wrong, and the difference between
     * them is one line.
     */
    private static function template(): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_notificationtemplates',
                'WHERE'  => ['itemtype' => Incident::class],
                'LIMIT'  => 1,
            ]) as $row
        ) {
            return (int) $row['id'];
        }

        $template = new NotificationTemplate();
        $id       = $template->add([
            'name'     => 'Major incident',
            'itemtype' => Incident::class,
            'comment'  => 'Installed by the glpimajor plugin. Safe to edit; it is not overwritten.',
            'css'      => '',
        ]);

        if ($id === false) {
            return 0;
        }

        $text = "##incident.action##\n\n"
              . "##lang.incident.title##: ##incident.title##\n"
              . "##lang.incident.state##: ##incident.state##\n"
              . "##lang.incident.commander##: ##incident.commander##\n"
              . "##lang.incident.comms##: ##incident.comms##\n"
              . "##lang.incident.declared##: ##incident.declared##\n"
              . "##lang.incident.next_update##: ##incident.next_update##\n"
              . "##lang.incident.affected##: ##incident.affected##\n\n"
              . "##lang.incident.latest_update##:\n##incident.latest_update##\n\n"
              . "##lang.incident.outcome##:\n##incident.outcome##\n\n"
              . "##incident.url##\n";

        $html = '<p><strong>##incident.action##</strong></p>'
              . '<p><strong>##incident.title##</strong> &mdash; ##incident.state##</p>'
              . '<ul>'
              . '<li>##lang.incident.commander##: ##incident.commander##</li>'
              . '<li>##lang.incident.comms##: ##incident.comms##</li>'
              . '<li>##lang.incident.declared##: ##incident.declared##</li>'
              . '<li>##lang.incident.next_update##: ##incident.next_update##</li>'
              . '<li>##lang.incident.affected##: ##incident.affected##</li>'
              . '</ul>'
              . '<p><strong>##lang.incident.latest_update##</strong><br />##incident.latest_update##</p>'
              . '<p><strong>##lang.incident.outcome##</strong><br />##incident.outcome##</p>'
              . '<p><a href="##incident.url##">##incident.url##</a></p>';

        $DB->insert('glpi_notificationtemplatetranslations', [
            'notificationtemplates_id' => (int) $id,
            // The empty language is GLPI's "any language" fallback, which is
            // what a plugin that ships one translation should install.
            'language'                 => '',
            'subject'                  => '##incident.action##: ##incident.title##',
            'content_text'             => $text,
            'content_html'             => $html,
        ]);

        return (int) $id;
    }

    private static function notification(string $event, int $templates_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_notifications',
                'WHERE'  => ['itemtype' => Incident::class, 'event' => $event],
                'LIMIT'  => 1,
            ]) as $_
        ) {
            // Already there, from a previous install. Leaving it alone is the
            // point: an administrator who retargeted or disabled it meant to.
            return;
        }

        $notification = new Notification();
        $id           = $notification->add([
            'name'         => 'Major incident: ' . $event,
            'entities_id'  => 0,
            'is_recursive' => 1,
            'itemtype'     => Incident::class,
            'event'        => $event,
            'is_active'    => 1,
            'comment'      => 'Installed by the glpimajor plugin.',
        ]);

        if ($id === false) {
            return;
        }

        $DB->insert('glpi_notifications_notificationtemplates', [
            'notifications_id'         => (int) $id,
            'mode'                     => 'mailing',
            'notificationtemplates_id' => $templates_id,
        ]);

        // The overdue reminder goes to the one person whose job it is.
        // Copying in the room turns a reminder into an alert, and an alert that
        // fires during every outage is an alert people filter.
        $targets = $event === 'overdue'
            ? [NotificationTargetIncident::COMMS]
            : [
                NotificationTargetIncident::COMMANDER,
                NotificationTargetIncident::COMMS,
                NotificationTargetIncident::DECLARER,
            ];

        foreach ($targets as $target) {
            $DB->insert('glpi_notificationtargets', [
                'items_id'         => $target,
                'type'             => Notification::USER_TYPE,
                'notifications_id' => (int) $id,
            ]);
        }
    }
}
