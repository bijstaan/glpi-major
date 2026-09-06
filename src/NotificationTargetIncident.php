<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

use Notification;
use NotificationTarget;

/**
 * Who hears about a major incident, and what the template can say.
 *
 * The class name is not a style choice: NotificationTarget::getInstanceClass()
 * derives `GlpiPlugin\Glpimajor\NotificationTargetIncident` from
 * `GlpiPlugin\Glpimajor\Incident` by string surgery on the namespace. Rename it
 * and the events silently stop resolving — GLPI returns false rather than
 * raising anything, so the first symptom is nobody being told about an outage.
 *
 * Four events, and the interesting one is `overdue`: it is the only automated
 * message this plugin sends, and it goes to the comms owner rather than to the
 * whole distribution list. A reminder that copies in the room is a reminder
 * people stop reading.
 */
class NotificationTargetIncident extends NotificationTarget
{
    /**
     * Target ids above core's own.
     *
     * Core's Notification constants run to the low twenties; starting at 101
     * leaves room for it to grow without a collision that would silently
     * retarget somebody's notification.
     */
    public const COMMANDER = 101;
    public const COMMS     = 102;
    public const DECLARER  = 103;

    public function getEvents()
    {
        return [
            'declared'  => __('Major incident declared', 'glpimajor'),
            'resolved'  => __('Major incident resolved', 'glpimajor'),
            'published' => __('Customer update published', 'glpimajor'),
            'overdue'   => __('Promised update is overdue', 'glpimajor'),
        ];
    }

    public function getEventsToSendImmediately(): array
    {
        // All of them. Every one of these is about something happening now; a
        // digest of "your customer has been down for an hour" arriving tomorrow
        // is not a notification, it is a report.
        return ['declared', 'resolved', 'published', 'overdue'];
    }

    public function addNotificationTargets($entity)
    {
        parent::addNotificationTargets($entity);

        $this->addTarget(self::COMMANDER, __('Incident commander', 'glpimajor'));
        $this->addTarget(self::COMMS, __('Comms owner', 'glpimajor'));
        $this->addTarget(self::DECLARER, __('Whoever declared it', 'glpimajor'));
    }

    public function addSpecificTargets($data, $options)
    {
        $field = match ((int) $data['items_id']) {
            self::COMMANDER => 'users_id_commander',
            self::COMMS     => 'users_id_comms',
            self::DECLARER  => 'users_id_declared',
            default         => null,
        };

        if ($field === null) {
            return;
        }

        $this->addUserByField($field);
    }

    public function addDataForTemplate($event, $options = [])
    {
        $events = $this->getAllEvents();

        $incident = $this->obj;
        $fields   = is_object($incident) && isset($incident->fields) ? $incident->fields : [];

        $state = (string) ($fields['state'] ?? '');

        $this->data['##incident.action##']  = $events[$event] ?? $event;
        $this->data['##incident.title##']   = (string) ($fields['name'] ?? '');
        $this->data['##incident.state##']   = Incident::stateLabel($state);
        $this->data['##incident.declared##'] = \Html::convDateTime($fields['date_declared'] ?? null);
        $this->data['##incident.resolved##'] = \Html::convDateTime($fields['date_resolved'] ?? null);
        $this->data['##incident.next_update##'] = \Html::convDateTime($fields['next_update_at'] ?? null);
        $this->data['##incident.affected##'] = (int) ($fields['affected_count'] ?? 0);
        $this->data['##incident.outcome##']  = (string) ($fields['outcome'] ?? '');

        $this->data['##incident.commander##'] = self::userName((int) ($fields['users_id_commander'] ?? 0));
        $this->data['##incident.comms##']     = self::userName((int) ($fields['users_id_comms'] ?? 0));

        $incidents_id = (int) ($fields['id'] ?? 0);

        // The internal link, to the incident. Not the status page: a
        // notification is internal, and putting the customer's public address
        // into every mail is how it ends up forwarded outside the estate.
        $this->data['##incident.url##'] = $incidents_id > 0
            ? Url::absolute('front/incident.form.php?id=' . $incidents_id)
            : '';

        $this->data['##incident.ticket##'] = (int) ($fields['tickets_id'] ?? 0);

        $latest = $incidents_id > 0 ? Update::latestCustomer($incidents_id) : null;
        $this->data['##incident.latest_update##'] = $latest !== null
            ? (string) $latest['content']
            : '';

        if ($event === 'overdue') {
            $this->data['##incident.overdue_minutes##'] = Nag::overdueMinutes(
                $fields['next_update_at'] ?? null,
                time()
            );
        } else {
            $this->data['##incident.overdue_minutes##'] = 0;
        }

        $this->getTags();
        foreach ($this->tag_descriptions[NotificationTarget::TAG_LANGUAGE] as $tag => $values) {
            if (!isset($this->data[$tag])) {
                $this->data[$tag] = $values['label'];
            }
        }
    }

    public function getTags()
    {
        $tags = [
            'incident.action'          => __('Event', 'glpimajor'),
            'incident.title'           => __('Customer-visible title', 'glpimajor'),
            'incident.state'           => __('State', 'glpimajor'),
            'incident.declared'        => __('Declared', 'glpimajor'),
            'incident.resolved'        => __('Resolved', 'glpimajor'),
            'incident.next_update'     => __('Next update promised', 'glpimajor'),
            'incident.overdue_minutes' => __('Minutes overdue', 'glpimajor'),
            'incident.affected'        => __('Affected tickets', 'glpimajor'),
            'incident.commander'       => __('Commander', 'glpimajor'),
            'incident.comms'           => __('Comms owner', 'glpimajor'),
            'incident.outcome'         => __('Outcome summary', 'glpimajor'),
            'incident.latest_update'   => __('Latest customer update', 'glpimajor'),
            'incident.ticket'          => __('Ticket number', 'glpimajor'),
            'incident.url'             => __('URL'),
        ];

        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }

        asort($this->tag_descriptions);
    }

    private static function userName(int $users_id): string
    {
        if ($users_id <= 0) {
            // Said plainly. A blank where a name should be reads as a bug; this
            // reads as the fact it is — nobody has picked the job up.
            return __('nobody yet', 'glpimajor');
        }

        $user = new \User();

        return $user->getFromDB($users_id) ? $user->getFriendlyName() : (string) $users_id;
    }
}
