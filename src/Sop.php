<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

use Plugin;

/**
 * The seam to glpi-sop: attach a post-incident-review procedure on resolution.
 *
 * Optional in both directions. If glpi-sop is not installed, the setting is
 * hidden and this does nothing; if it is installed but the configured procedure
 * has been deleted, that is recorded as an event rather than raised as an
 * error — resolving an incident must not fail because a checklist went missing.
 *
 * `Run::ORIGIN_RULE` is the origin used deliberately. glpi-sop's origins are
 * labels shown to whoever reads the run later, and its own comments are clear
 * that ORIGIN_AI means "a person accepted a suggestion". This is neither a
 * person choosing nor a model suggesting: it is a configured consequence of an
 * incident being resolved, which is what a rule is.
 */
final class Sop
{
    private const SOP_CLASS = 'GlpiPlugin\\Glpisop\\Sop';
    private const RUN_CLASS = 'GlpiPlugin\\Glpisop\\Run';

    public static function available(): bool
    {
        return Plugin::isPluginActive('glpisop')
            && class_exists(self::SOP_CLASS)
            && class_exists(self::RUN_CLASS)
            && method_exists(self::RUN_CLASS, 'attach');
    }

    /** Every SOP an administrator could choose, for the settings dropdown. */
    public static function choices(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!self::available()) {
            return [];
        }

        $out = [];
        foreach (
            $DB->request([
                'FROM'  => 'glpi_plugin_glpisop_sops',
                'WHERE' => ['is_active' => 1],
                'ORDER' => ['name ASC'],
                'LIMIT' => 200,
            ]) as $row
        ) {
            $out[(int) $row['id']] = (string) $row['name'];
        }

        return $out;
    }

    public static function nameOf(int $sops_id): ?string
    {
        if ($sops_id <= 0 || !self::available()) {
            return null;
        }

        $sop = new (self::SOP_CLASS)();

        return $sop->getFromDB($sops_id) ? (string) $sop->fields['name'] : null;
    }

    /**
     * Put the configured review procedure on the incident's ticket.
     *
     * Returns the run id, or null for every kind of absence — no plugin, no
     * configured procedure, a procedure that no longer exists, or a ticket that
     * has been purged.
     */
    public static function attachReview(Incident $incident): ?int
    {
        $sops_id = (int) Settings::get('pir_sops_id');
        if ($sops_id <= 0 || !self::available()) {
            return null;
        }

        $ticket = $incident->ticket();
        if ($ticket === null) {
            return null;
        }

        $sop = new (self::SOP_CLASS)();
        if (!$sop->getFromDB($sops_id)) {
            Events::record(
                (int) $incident->getID(),
                Events::SOP,
                'configured procedure no longer exists',
                (int) $incident->fields['entities_id']
            );

            return null;
        }

        try {
            $runs_id = (self::RUN_CLASS)::attach($sop, $ticket, 'rule');
        } catch (\Throwable $e) {
            trigger_error(
                'glpimajor: could not attach the post-incident procedure: ' . $e->getMessage(),
                E_USER_WARNING
            );

            return null;
        }

        if ($runs_id === null) {
            return null;
        }

        Events::record(
            (int) $incident->getID(),
            Events::SOP,
            sprintf('%s (run #%d)', (string) $sop->fields['name'], (int) $runs_id),
            (int) $incident->fields['entities_id']
        );

        return (int) $runs_id;
    }
}
