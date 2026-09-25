<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

use CommonDBTM;
use CronTask;

/**
 * The audit trail: who did what to an incident, when, and what came of it.
 *
 * One table with an `action` column rather than a table per concern, because
 * the question asked of it is always "what happened to this incident, in
 * order" — and answering that across four tables means a UNION nobody writes.
 *
 * Extends CommonDBTM only so the cron registry has an itemtype to hang the
 * prune job on. These rows are never edited and have no form.
 */
class Events extends CommonDBTM
{
    public static string $rightname = 'plugin_glpimajor_declare';

    public const DECLARED    = 'declared';
    public const STATE       = 'state';
    public const ROLE        = 'role';
    public const TITLE       = 'title';
    public const ATTACHED    = 'attached';
    public const DETACHED    = 'detached';
    public const PROPOSED    = 'proposed';
    public const UPDATE      = 'update';
    public const REVIEW      = 'review';
    public const PROMISE     = 'promise';
    public const NAGGED      = 'nagged';
    public const RESOLVED    = 'resolved';
    public const PIR_SAVED   = 'pir_saved';
    public const PIR_DONE    = 'pir_complete';
    public const PIR_UNLOCK  = 'pir_unlock';
    public const PIR_RELOCK  = 'pir_relock';
    public const PM_SAVED    = 'pm_saved';
    public const PM_PUBLISHED = 'pm_published';
    public const PM_RETRACTED = 'pm_retracted';
    public const PUBLISHED   = 'published';
    public const TOKEN       = 'token';
    public const PUSHED      = 'pushed';
    public const SOP         = 'sop';

    public static function getTypeName($nb = 0)
    {
        return _n('Incident event', 'Incident events', $nb, 'glpimajor');
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_glpimajor_events';
    }

    /**
     * Record something that happened.
     *
     * Never throws and never blocks: an audit row that fails to write must not
     * take down the action it was describing. A missing row is a gap somebody
     * can notice; a 500 on "attach this ticket" is an outage inside an outage.
     */
    public static function record(
        int $incidents_id,
        string $action,
        string $detail = '',
        int $entities_id = 0,
        ?int $users_id = null
    ): void {
        /** @var \DBmysql $DB */
        global $DB;

        try {
            $DB->insert(self::getTable(), [
                'entities_id'                   => $entities_id,
                'plugin_glpimajor_incidents_id' => $incidents_id,
                'action'                        => mb_substr($action, 0, 32),
                'users_id'                      => $users_id ?? (int) \Session::getLoginUserID(),
                'detail'                        => mb_substr($detail, 0, 4000),
                'date_creation'                 => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            trigger_error('glpimajor: could not record event: ' . $e->getMessage(), E_USER_WARNING);
        }
    }

    /** @return array<int,array<string,mixed>> oldest first — this is a story */
    public static function forIncident(int $incidents_id, int $limit = 500): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'FROM'   => self::getTable(),
                'WHERE'  => ['plugin_glpimajor_incidents_id' => $incidents_id],
                'ORDER'  => ['date_creation ASC', 'id ASC'],
                'LIMIT'  => $limit,
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
    }

    /** Human labels. Kept here so the PIR timeline and the audit list agree. */
    public static function label(string $action): string
    {
        return match ($action) {
            self::DECLARED   => __('Declared a major incident', 'glpimajor'),
            self::STATE      => __('State changed', 'glpimajor'),
            self::ROLE       => __('Roles changed', 'glpimajor'),
            self::TITLE      => __('Public title changed', 'glpimajor'),
            self::ATTACHED   => __('Ticket attached as affected', 'glpimajor'),
            self::DETACHED   => __('Ticket detached', 'glpimajor'),
            self::PROPOSED   => __('Resolution proposed to affected tickets', 'glpimajor'),
            self::UPDATE     => __('Update published', 'glpimajor'),
            self::REVIEW     => __('Update reviewed before publishing', 'glpimajor'),
            self::PROMISE    => __('Next update promised', 'glpimajor'),
            self::NAGGED     => __('Comms owner reminded', 'glpimajor'),
            self::RESOLVED   => __('Resolved', 'glpimajor'),
            self::PIR_SAVED  => __('Review saved', 'glpimajor'),
            self::PIR_DONE   => __('Review completed', 'glpimajor'),
            self::PIR_UNLOCK => __('Review unlocked for editing', 'glpimajor'),
            self::PIR_RELOCK => __('Review locked again', 'glpimajor'),
            self::PM_SAVED     => __('Post-mortem draft saved', 'glpimajor'),
            self::PM_PUBLISHED => __('Post-mortem published to the status page', 'glpimajor'),
            self::PM_RETRACTED => __('Post-mortem retracted from the status page', 'glpimajor'),
            self::PUBLISHED  => __('Status page published', 'glpimajor'),
            self::TOKEN      => __('Status page address changed', 'glpimajor'),
            self::PUSHED     => __('Action sent to the improvement register', 'glpimajor'),
            self::SOP        => __('Post-incident procedure attached', 'glpimajor'),
            default          => $action,
        };
    }

    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'prune' => ['description' => __('Drop major-incident audit rows past retention', 'glpimajor')],
            default => [],
        };
    }

    public static function cronPrune(CronTask $task): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $days   = (int) Settings::get('events_retention_days');
        $cutoff = date('Y-m-d H:i:s', time() - ($days * DAY_TIMESTAMP));

        $DB->delete(self::getTable(), ['date_creation' => ['<', $cutoff]]);

        $removed = (int) $DB->affectedRows();
        $task->setVolume($removed);

        return $removed > 0 ? 1 : 0;
    }
}
