<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

use Config;

/**
 * Plugin settings, with defaults.
 *
 * Defaults permit nothing that reaches the public: publishing is off, the AI
 * review is off, and no entity has a status page until somebody mints a token
 * for it. Everything that is on by default is internal-facing — matching offers
 * a banner, the nag reminds a colleague.
 */
final class Settings
{
    public const DEFAULTS = [
        // --- duplicate matching -------------------------------------------
        // Offer the "possible duplicate" banner at all.
        'match_enabled'       => 1,
        // Signals. At least one must agree, on top of entity and window.
        'match_same_category' => 1,
        'match_same_location' => 0,
        'match_keywords'      => 0,
        // Only incidents declared this recently are offered. An outage from
        // last month is not what this ticket is about.
        'match_window_hours'  => 72,
        // Words shorter than this never count as a keyword match, or "the" and
        // "server" match everything the estate has ever run.
        'match_min_word'      => 5,

        // --- how far a declaration reaches --------------------------------
        // The default state of the declare control's "Also covers sub-entities"
        // checkbox, which is only offered when the entity has any. Off: putting
        // an outage on an entity's status page is a publication, and the safe
        // direction for a default is the one that publishes less. Ticking a box
        // costs a click; un-publishing an outage from three offices' pages
        // costs an explanation.
        'recursive_default' => 0,

        // --- comms discipline ---------------------------------------------
        'nag_enabled'        => 1,
        // Nothing is late the instant it is due; people are mid-sentence.
        'nag_grace_minutes'  => 5,
        // How often to say it again while the promise stays unmet. A reminder
        // that repeats every run is an alert people mute.
        'nag_repeat_minutes' => 30,
        // Prefill for the next-update promise, in minutes.
        'update_interval'    => 60,

        // --- AI review (glpi-ai, optional) --------------------------------
        // The model reads a draft public update and reports on it. It never
        // writes one, and this is off until somebody turns it on.
        'ai_review_enabled' => 0,

        // --- status page ---------------------------------------------------
        // Master switch for publishing. Off, no file is ever written and the
        // public endpoint has nothing to serve.
        'status_enabled'       => 0,
        // Empty means GLPI's configured timezone.
        'status_timezone'      => '',
        // Empty falls back to glpi-whitelabel's product name, then to a plain
        // "Service status".
        'status_page_title'    => '',
        'status_support_email' => '',
        'status_support_phone' => '',
        // How far back resolved incidents stay on the page.
        'status_history_days'  => 30,
        // Embed the logo as a data: URI when it is smaller than the ceiling,
        // otherwise link it. The dev instance's logo is 1.18 MB, which is
        // 1.6 MB of base64 on a page somebody loads during an outage.
        'status_embed_logo'    => 1,
        'status_logo_max_kb'   => 256,
        // Regenerate anything older than this on the cron sweep.
        'status_max_age_min'   => 60,

        // --- the self-service portal ---------------------------------------
        // Surface the status page to requesters in the helpdesk interface. On
        // by default, but it can do nothing at all until publishing is on and
        // the entity has an address — a page nobody can read is not something
        // to link to. Both of these are read on portal page loads, so both are
        // answered from the plugin's own tables behind a short cache.
        'portal_banner' => 1,
        // The quiet persistent link, shown when nothing is wrong. Separable
        // from the banner because they are different promises: the banner is
        // "something is happening now", the link is "there is a page".
        'portal_link'   => 1,

        // --- post-incident review -----------------------------------------
        // Resolving requires an outcome summary.
        'pir_required'         => 1,
        'pir_lock_on_complete' => 1,
        // A glpi-sop procedure to attach on resolution. 0 = none.
        'pir_sops_id'          => 0,
        // Offer completed PIR actions to glpi-improve, if it is there.
        'improve_push'         => 0,

        // --- retention ------------------------------------------------------
        'events_retention_days' => 730,
    ];

    /** @return array<string,int|string> */
    public static function all(): array
    {
        $stored = Config::getConfigurationValues(
            PLUGIN_GLPIMAJOR_CONFIG_CONTEXT,
            array_keys(self::DEFAULTS)
        );

        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $stored[$key] ?? null;
            if ($value === null || $value === '') {
                $out[$key] = $default;
                continue;
            }
            $out[$key] = is_int($default) ? (int) $value : (string) $value;
        }

        return self::reconcile($out);
    }

    public static function get(string $key): int|string
    {
        return self::all()[$key] ?? self::DEFAULTS[$key];
    }

    public static function flag(string $key): bool
    {
        return ((int) self::get($key)) === 1;
    }

    /**
     * Correct values that contradict each other, in memory.
     *
     * A stored setting that is out of range is clamped rather than rejected, so
     * a bad row can never take the plugin down — it behaves like the nearest
     * sane one. The settings page reports anything it clamps at save time;
     * these are the backstop for values that arrived some other way.
     */
    private static function reconcile(array $s): array
    {
        $s['match_window_hours'] = max(1, min(8760, (int) $s['match_window_hours']));
        $s['match_min_word']     = max(3, min(20, (int) $s['match_min_word']));

        $s['nag_grace_minutes']  = max(0, min(1440, (int) $s['nag_grace_minutes']));
        // A repeat shorter than the cron period would fire every run, which is
        // an alert people mute rather than a reminder they act on.
        $s['nag_repeat_minutes'] = max(5, min(1440, (int) $s['nag_repeat_minutes']));
        $s['update_interval']    = max(5, min(1440, (int) $s['update_interval']));

        $s['status_history_days'] = max(1, min(365, (int) $s['status_history_days']));
        $s['status_logo_max_kb']  = max(8, min(2048, (int) $s['status_logo_max_kb']));
        $s['status_max_age_min']  = max(5, min(1440, (int) $s['status_max_age_min']));

        $s['events_retention_days'] = max(30, min(3650, (int) $s['events_retention_days']));

        foreach (
            [
                'match_enabled', 'match_same_category', 'match_same_location',
                'match_keywords', 'nag_enabled', 'ai_review_enabled',
                'status_enabled', 'status_embed_logo', 'pir_required',
                'pir_lock_on_complete', 'improve_push',
                'recursive_default', 'portal_banner', 'portal_link',
            ] as $bool
        ) {
            $s[$bool] = ((int) $s[$bool]) === 1 ? 1 : 0;
        }

        // Matching with every signal switched off would offer every open
        // incident in the entity on every new ticket, which is noise wearing a
        // feature's clothes. Category is the one every instance always has.
        if (
            (int) $s['match_enabled'] === 1
            && (int) $s['match_same_category'] === 0
            && (int) $s['match_same_location'] === 0
            && (int) $s['match_keywords'] === 0
        ) {
            $s['match_same_category'] = 1;
        }

        return $s;
    }

    /** The matching rules, in the shape Matcher expects. */
    public static function matchRules(): array
    {
        $s = self::all();

        return [
            'enabled'       => (int) $s['match_enabled'] === 1,
            'same_category' => (int) $s['match_same_category'] === 1,
            'same_location' => (int) $s['match_same_location'] === 1,
            'keywords'      => (int) $s['match_keywords'] === 1,
            'window_hours'  => (int) $s['match_window_hours'],
            'min_word'      => (int) $s['match_min_word'],
        ];
    }

    public static function save(array $input): void
    {
        $values = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (array_key_exists($key, $input)) {
                $values[$key] = $input[$key];
            }
        }
        if ($values !== []) {
            Config::setConfigurationValues(PLUGIN_GLPIMAJOR_CONFIG_CONTEXT, $values);
        }
    }
}
