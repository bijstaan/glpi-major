<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

use CommonDBTM;
use GlpiPlugin\Glpipdf\Doc;
use Html;

/**
 * Major incidents as documents, for glpi-pdf.
 *
 * Two offers, and they are for two different audiences on two different days.
 *
 *  - **The incident record** — everything, including the internal updates. What
 *    the service owner reads the morning after, and what an auditor is shown.
 *  - **The post-incident review** — what happened, what it cost, why, and what
 *    is being done about it. This is the one that leaves the building: it goes
 *    to the customer whose service was down, and it is the document a major
 *    incident is ultimately judged on.
 *
 * The split matters because of one field. An update carries an `audience`, and
 * the internal ones say things like "failover is stuck, trying the manual
 * route" that are true, useful, and not for the customer. The PIR document
 * includes only the customer-facing updates; the incident record includes both
 * and marks which is which. Getting that wrong in the wrong direction is the
 * kind of mistake that ends up in a contract dispute, so it is decided here —
 * in the plugin that owns the field — rather than in a generic exporter that
 * would have to guess.
 */
final class PdfDocument
{
    /** @return array<int,array<string,mixed>> */
    public static function offers(): array
    {
        if (!class_exists(Doc::class)) {
            return [];
        }

        return [
            [
                'key'      => 'glpimajor.incident',
                'itemtype' => Incident::class,
                'label'    => __('Incident record', 'glpimajor'),
                'kind'     => 'document',
                'weight'   => 10,
                'parts'    => [
                    ['key' => 'mi.summary',  'label' => __('Summary and fields', 'glpimajor'), 'default' => true],
                    ['key' => 'mi.outcome',  'label' => __('Outcome', 'glpimajor'),            'default' => true],
                    ['key' => 'mi.updates',  'label' => __('Updates, internal and customer', 'glpimajor'), 'default' => true],
                    ['key' => 'mi.affected', 'label' => __('Affected tickets', 'glpimajor'),   'default' => true],
                ],
                'build'    => static fn(CommonDBTM $item, array $parts = []): ?Doc
                    => self::incident($item, $parts),
            ],
            [
                'key'      => 'glpimajor.pir',
                'itemtype' => Incident::class,
                'label'    => __('Post-incident review', 'glpimajor'),
                'kind'     => 'document',
                'weight'   => 20,
                'parts'    => [
                    ['key' => 'pir.summary', 'label' => __('Summary and duration', 'glpimajor'), 'default' => true],
                    ['key' => 'pir.review',  'label' => __('What happened, impact, root cause', 'glpimajor'), 'default' => true],
                    ['key' => 'pir.actions', 'label' => __('Actions', 'glpimajor'),               'default' => true],
                    ['key' => 'pir.updates', 'label' => __('What we told customers', 'glpimajor'), 'default' => true],
                ],
                'build'    => static fn(CommonDBTM $item, array $parts = []): ?Doc
                    => self::pir($item, $parts),
                // Most incidents never get a written review, and an empty PIR
                // row is created early by the workflow — so "has a PIR" is not
                // the question. "Has anybody written anything in it" is, and
                // asking it here keeps the link off the incidents where it
                // would only produce an error.
                'available' => static fn(CommonDBTM $item): bool => self::hasReview($item),
            ],
        ];
    }

    // ---------------------------------------------------- the full record

    /** @param string[] $parts */
    private static function incident(CommonDBTM $item, array $parts = []): ?Doc
    {
        if (!($item instanceof Incident)) {
            return null;
        }

        $incidents_id = (int) $item->getID();

        // An empty selection is what a caller with no opinion passes — a plain
        // export link, a bulk action — and it means everything.
        $want = static fn(string $key): bool => $parts === [] || in_array($key, $parts, true);

        $doc = Doc::make((string) $item->fields['name'])
            ->reference(sprintf(__('Major incident #%d', 'glpimajor'), $incidents_id))
            ->subtitle(__('Incident record', 'glpimajor'))
            ->meta($want('mi.summary') ? self::identity($item) : []);

        if ($want('mi.outcome') && trim((string) ($item->fields['outcome'] ?? '')) !== '') {
            $doc->section(__('Outcome', 'glpimajor'))->text((string) $item->fields['outcome']);
        }

        if ($want('mi.updates')) {
            self::updates($doc, $incidents_id, null);
        }
        if ($want('mi.affected')) {
            self::affected($doc, $incidents_id);
        }

        $doc->footnote(__('Contains internal updates. Not for distribution outside the '
            . 'service desk.', 'glpimajor'));

        return $doc;
    }

    /** @return array<string,string> */
    private static function identity(Incident $item): array
    {
        $declared = self::when($item->fields['date_declared'] ?? null);
        $resolved = self::when($item->fields['date_resolved'] ?? null);

        return [
            __('State', 'glpimajor')     => Incident::stateLabel((string) $item->fields['state']),
            __('Entity')                 => self::dropdown('glpi_entities', (int) $item->fields['entities_id']),
            __('Declared', 'glpimajor')  => $declared,
            __('Declared by', 'glpimajor') => self::dropdown('glpi_users', (int) $item->fields['users_id_declared']),
            __('Incident commander', 'glpimajor') => self::dropdown('glpi_users', (int) $item->fields['users_id_commander']),
            __('Communications lead', 'glpimajor') => self::dropdown('glpi_users', (int) $item->fields['users_id_comms']),
            __('Resolved', 'glpimajor')  => $resolved,
            __('Duration', 'glpimajor')  => self::duration(
                (string) ($item->fields['date_declared'] ?? ''),
                (string) ($item->fields['date_resolved'] ?? '')
            ),
            __('Tickets affected', 'glpimajor') => (string) (int) $item->fields['affected_count'],
            __('Originating ticket', 'glpimajor') => (int) $item->fields['tickets_id'] > 0
                ? sprintf('#%d', (int) $item->fields['tickets_id'])
                : '',
        ];
    }

    /**
     * The update stream.
     *
     * `$audience` null means every update, each labelled. A customer-facing
     * document passes Update::CUSTOMER and gets only those — see the class
     * comment for why that decision lives here.
     */
    private static function updates(Doc $doc, int $incidents_id, ?string $audience): void
    {
        $updates = Update::forIncident($incidents_id, $audience);

        if ($updates === []) {
            return;
        }

        $entries = [];
        foreach ($updates as $update) {
            $kind = Incident::stateLabel((string) $update['state_at_time']);

            if ($audience === null) {
                $kind .= '  ·  ' . ((string) $update['audience'] === Update::CUSTOMER
                    ? __('customer', 'glpimajor')
                    : __('internal', 'glpimajor'));
            }

            $entries[] = [
                'when' => self::when($update['date_creation'] ?? null),
                'who'  => self::dropdown('glpi_users', (int) ($update['users_id'] ?? 0)),
                'kind' => $kind,
                'body' => (string) ($update['content'] ?? ''),
            ];
        }

        $doc->section($audience === Update::CUSTOMER
            ? __('What we told customers', 'glpimajor')
            : __('Updates', 'glpimajor'))
            ->timeline($entries);
    }

    /**
     * The tickets that were folded into this incident.
     *
     * Affected::forIncident() already joins the ticket, so its rows carry the
     * title and status — re-reading each Ticket here would be one query per
     * affected ticket on an incident that can have hundreds, for columns
     * already in hand.
     */
    private static function affected(Doc $doc, int $incidents_id): void
    {
        $rows = [];

        foreach (Affected::forIncident($incidents_id) as $row) {
            $tickets_id = (int) ($row['tickets_id'] ?? 0);

            // A ticket purged after being attached leaves the link row behind
            // with nothing joined to it.
            if ($tickets_id <= 0 || ($row['ticket_name'] ?? null) === null) {
                continue;
            }

            $rows[] = [
                (string) $tickets_id,
                (string) $row['ticket_name'],
                \Ticket::getStatus((int) $row['ticket_status']),
                self::when($row['ticket_date'] ?? null),
            ];
        }

        if ($rows === []) {
            return;
        }

        $doc->section(__('Affected tickets', 'glpimajor'))->table(
            [__('ID'), __('Title'), __('Status'), __('Opened')],
            $rows,
            [10, 54, 16, 20]
        );
    }

    // ------------------------------------------------------------ the PIR

    /**
     * The review, as the customer receives it.
     *
     * Returns null when there is no review yet rather than an empty template.
     * A PIR with three blank headings is worse than no PIR: it looks like the
     * review happened and said nothing.
     */
    /** Has anybody written any of the three sections? */
    private static function hasReview(CommonDBTM $item): bool
    {
        if (!($item instanceof Incident)) {
            return false;
        }

        $pir = Pir::forIncident(
            (int) $item->getID(),
            (int) $item->fields['entities_id'],
            false
        );

        if ($pir === null) {
            return false;
        }

        foreach (['what_happened', 'impact', 'root_cause'] as $field) {
            if (trim((string) ($pir[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /** @param string[] $parts */
    private static function pir(CommonDBTM $item, array $parts = []): ?Doc
    {
        if (!($item instanceof Incident)) {
            return null;
        }

        $incidents_id = (int) $item->getID();
        $pir          = Pir::forIncident($incidents_id, (int) $item->fields['entities_id'], false);

        if ($pir === null) {
            return null;
        }

        $written = array_filter([
            (string) ($pir['what_happened'] ?? ''),
            (string) ($pir['impact'] ?? ''),
            (string) ($pir['root_cause'] ?? ''),
        ], static fn(string $s): bool => trim($s) !== '');

        if ($written === []) {
            return null;
        }

        $complete = (string) ($pir['status'] ?? 'draft') === 'complete';
        $want     = static fn(string $key): bool => $parts === [] || in_array($key, $parts, true);

        $doc = Doc::make((string) $item->fields['name'])
            ->reference(sprintf(__('Major incident #%d', 'glpimajor'), $incidents_id))
            ->subtitle(__('Post-incident review', 'glpimajor'))
            ->meta($want('pir.summary') ? [
                __('Entity')                => self::dropdown('glpi_entities', (int) $item->fields['entities_id']),
                __('Declared', 'glpimajor') => self::when($item->fields['date_declared'] ?? null),
                __('Resolved', 'glpimajor') => self::when($item->fields['date_resolved'] ?? null),
                __('Duration', 'glpimajor') => self::duration(
                    (string) ($item->fields['date_declared'] ?? ''),
                    (string) ($item->fields['date_resolved'] ?? '')
                ),
                __('Tickets affected', 'glpimajor') => (string) (int) $item->fields['affected_count'],
                __('Review completed', 'glpimajor') => self::when($pir['date_completed'] ?? null),
            ] : []);

        // A draft says so on every page it can. A review circulated before it
        // was finished, without that word on it, is one somebody will quote
        // back later as though it were the finding.
        if (!$complete) {
            $doc->note(
                __('This review is a draft. Its findings are not final.', 'glpimajor'),
                Doc::WARN,
                __('Draft', 'glpimajor')
            );
        }

        if ($want('pir.review')) {
            foreach (
                [
                    'what_happened' => __('What happened', 'glpimajor'),
                    'impact'        => __('Impact', 'glpimajor'),
                    'root_cause'    => __('Root cause', 'glpimajor'),
                ] as $field => $heading
            ) {
                $body = trim((string) ($pir[$field] ?? ''));
                if ($body !== '') {
                    $doc->section($heading)->text($body);
                }
            }
        }

        if ($want('pir.actions')) {
            self::actions($doc, (int) $pir['id']);
        }
        if ($want('pir.updates')) {
            self::updates($doc, $incidents_id, Update::CUSTOMER);
        }

        return $doc;
    }

    private static function actions(Doc $doc, int $pirs_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];

        foreach (
            $DB->request([
                'FROM'  => 'glpi_plugin_glpimajor_piractions',
                'WHERE' => ['plugin_glpimajor_pirs_id' => $pirs_id],
                'ORDER' => ['due_date', 'id'],
            ]) as $row
        ) {
            $rows[] = [
                (string) ($row['content'] ?? ''),
                self::dropdown('glpi_users', (int) ($row['users_id_owner'] ?? 0)),
                self::when($row['due_date'] ?? null, false),
                (string) ($row['status'] ?? ''),
            ];
        }

        if ($rows === []) {
            return;
        }

        $doc->section(__('Actions', 'glpimajor'))->table(
            [__('Action', 'glpimajor'), __('Owner', 'glpimajor'), __('Due'), __('Status')],
            $rows,
            [52, 20, 14, 14]
        );
    }

    // ------------------------------------------------------------ plumbing

    /**
     * How long it ran, in words.
     *
     * "3h 42m" rather than a pair of timestamps the reader has to subtract. On
     * a review that goes to a customer this is the number they remember, and it
     * is the one that should not be left as an exercise.
     */
    private static function duration(string $from, string $to): string
    {
        if ($from === '' || $to === '' || str_starts_with($from, '0000') || str_starts_with($to, '0000')) {
            return '';
        }

        $seconds = strtotime($to) - strtotime($from);
        if ($seconds <= 0) {
            return '';
        }

        $hours   = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0
            ? sprintf(__('%1$dh %2$dm', 'glpimajor'), $hours, $minutes)
            : sprintf(__('%dm', 'glpimajor'), $minutes);
    }

    private static function dropdown(string $table, int $id): string
    {
        if ($id <= 0) {
            return '';
        }

        $name = \Dropdown::getDropdownName($table, $id);

        return $name === '&nbsp;' ? '' : (string) $name;
    }

    private static function when(mixed $stamp, bool $withTime = true): string
    {
        $stamp = (string) ($stamp ?? '');

        if ($stamp === '' || str_starts_with($stamp, '0000')) {
            return '';
        }

        return $withTime ? (string) Html::convDateTime($stamp) : (string) Html::convDate($stamp);
    }
}
