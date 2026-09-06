<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

use GlpiPlugin\Glpimajor\Events;
use GlpiPlugin\Glpimajor\Incident;
use GlpiPlugin\Glpimajor\Notifications;
use GlpiPlugin\Glpimajor\Page;
use GlpiPlugin\Glpimajor\Settings;

/**
 * Install: eleven tables, two rights, three crons, four notification events.
 *
 * Time columns are DATETIME / TIMESTAMP rather than the unix INTs glpi-presence
 * uses. Presence has a seconds-granularity expiry computed in PHP, where mixing
 * clocks matters; here every timestamp is either read by a human or compared at
 * minute granularity, and half of them are rendered onto a page a customer
 * reads — so the GLPI convention wins and Html::convDateTime() works unaided.
 *
 * `state`, `audience` and `status` are VARCHAR rather than ENUM: adding a value
 * to an ENUM is an ALTER on a table that may be large, and every one of these
 * is validated in PHP anyway.
 */
function plugin_glpimajor_install()
{
    /** @var DBmysql $DB */
    global $DB;

    $charset = 'utf8mb4';
    $collate = 'utf8mb4_unicode_ci';

    // The MI record. One per ticket — the UNIQUE key is what makes this a mode
    // on a ticket rather than a parallel object that can drift from it.
    //
    // `is_recursive` means "this outage also covers the sub-entities of the
    // entity it was declared in", and it travels strictly *down*. A customer
    // with three offices is one customer; a sibling entity is a different one,
    // and no query in this plugin can reach one from here. Default 0: putting
    // an outage on a page is a publication, and the safe direction for a
    // default is the one that publishes less.
    if (!$DB->tableExists('glpi_plugin_glpimajor_incidents')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpimajor_incidents` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `name` VARCHAR(255) NOT NULL DEFAULT '',
                `tickets_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `is_recursive` TINYINT NOT NULL DEFAULT 0,
                `state` VARCHAR(16) NOT NULL DEFAULT 'investigating',
                `users_id_commander` INT UNSIGNED NOT NULL DEFAULT 0,
                `users_id_comms` INT UNSIGNED NOT NULL DEFAULT 0,
                `users_id_declared` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_declared` TIMESTAMP NULL DEFAULT NULL,
                `date_resolved` TIMESTAMP NULL DEFAULT NULL,
                `next_update_at` TIMESTAMP NULL DEFAULT NULL,
                `date_nagged` TIMESTAMP NULL DEFAULT NULL,
                `outcome` TEXT NULL,
                `affected_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `comment` TEXT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `one_per_ticket` (`tickets_id`),
                KEY `entities_id` (`entities_id`),
                KEY `open` (`state`,`entities_id`),
                KEY `recursive` (`is_recursive`,`state`),
                KEY `promise` (`next_update_at`),
                KEY `users_id_comms` (`users_id_comms`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // Which tickets were attached to which incident, and by whom. `users_id` is
    // the person who clicked, not the ticket's owner: attaching somebody else's
    // ticket to an outage is a decision, and decisions are attributable.
    if (!$DB->tableExists('glpi_plugin_glpimajor_affected')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpimajor_affected` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpimajor_incidents_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `tickets_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `itilsolutions_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_proposed` TIMESTAMP NULL DEFAULT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `once` (`plugin_glpimajor_incidents_id`,`tickets_id`),
                KEY `tickets_id` (`tickets_id`),
                KEY `entities_id` (`entities_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // The comms log. Separate from the ticket timeline on purpose: a customer
    // update has an audience, is versioned against the state we believed at the
    // time, and is the source for a published artefact. A followup is none of
    // those things.
    if (!$DB->tableExists('glpi_plugin_glpimajor_updates')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpimajor_updates` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpimajor_incidents_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `audience` VARCHAR(16) NOT NULL DEFAULT 'internal',
                `content` TEXT NULL,
                `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `state_at_time` VARCHAR(16) NOT NULL DEFAULT '',
                `next_update_at` TIMESTAMP NULL DEFAULT NULL,
                `ai_reviewed` TINYINT NOT NULL DEFAULT 0,
                `ai_findings` TEXT NULL,
                `ai_heeded` TINYINT NOT NULL DEFAULT 0,
                `date_reviewed` TIMESTAMP NULL DEFAULT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `incident` (`plugin_glpimajor_incidents_id`,`date_creation`),
                KEY `published` (`plugin_glpimajor_incidents_id`,`audience`),
                KEY `entities_id` (`entities_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // Per-entity comms snippets. Configuration rather than an object anybody
    // searches for, so these are managed from a section of the settings page and
    // have no menu entry of their own.
    if (!$DB->tableExists('glpi_plugin_glpimajor_snippets')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpimajor_snippets` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `is_recursive` TINYINT NOT NULL DEFAULT 1,
                `name` VARCHAR(255) NOT NULL DEFAULT '',
                `audience` VARCHAR(16) NOT NULL DEFAULT 'customer',
                `content` TEXT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // One status page per entity.
    //
    // The generated file is named after the token, which is the whole point: the
    // public endpoint validates the token's shape and opens that filename, so
    // there is no query path from the internet into GLPI's data layer at all —
    // not a restricted one, not a read-only one, none.
    if (!$DB->tableExists('glpi_plugin_glpimajor_pages')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpimajor_pages` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `token` VARCHAR(64) NOT NULL DEFAULT '',
                `is_enabled` TINYINT NOT NULL DEFAULT 0,
                `page_title` VARCHAR(255) NOT NULL DEFAULT '',
                `support_note` TEXT NULL,
                `date_generated` TIMESTAMP NULL DEFAULT NULL,
                `last_error` TEXT NULL,
                `bytes` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `one_per_entity` (`entities_id`),
                UNIQUE KEY `token` (`token`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // Scheduled maintenance announcements. Self-contained — this plugin owns the
    // table and the UI — but `source`/`external_key` let another plugin push and
    // re-push an announcement idempotently without remembering our row id.
    if (!$DB->tableExists('glpi_plugin_glpimajor_maintenances')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpimajor_maintenances` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `is_recursive` TINYINT NOT NULL DEFAULT 0,
                `name` VARCHAR(255) NOT NULL DEFAULT '',
                `content` TEXT NULL,
                `date_start` TIMESTAMP NULL DEFAULT NULL,
                `date_end` TIMESTAMP NULL DEFAULT NULL,
                `state` VARCHAR(16) NOT NULL DEFAULT 'scheduled',
                -- NULL, not '', and that is load-bearing. The unique key below
                -- exists so a plugin re-pushing its own announcement updates one
                -- row instead of littering a customer's page. With empty strings
                -- as the default, every window created *by hand* shares the key
                -- ('','') — so the instance could hold exactly one of them, and
                -- the second one an administrator typed died on a duplicate-key
                -- error. MySQL does not consider two NULLs equal, which is the
                -- whole reason UNIQUE and NULL are allowed to coexist.
                `source` VARCHAR(100) NULL DEFAULT NULL,
                `external_key` VARCHAR(190) NULL DEFAULT NULL,
                `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `foreign` (`source`,`external_key`),
                KEY `entities_id` (`entities_id`),
                KEY `window` (`entities_id`,`state`,`date_start`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // The post-incident review, one per incident.
    if (!$DB->tableExists('glpi_plugin_glpimajor_pirs')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpimajor_pirs` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpimajor_incidents_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `what_happened` TEXT NULL,
                `impact` TEXT NULL,
                `root_cause` TEXT NULL,
                `problems_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `status` VARCHAR(16) NOT NULL DEFAULT 'draft',
                `is_unlocked` TINYINT NOT NULL DEFAULT 0,
                `date_completed` TIMESTAMP NULL DEFAULT NULL,
                `users_id_complete` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `one_per_incident` (`plugin_glpimajor_incidents_id`),
                KEY `entities_id` (`entities_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // Actions are rows, not a textarea: the improvement seam needs discrete
    // items with owners, and "what did we promise after the last three outages"
    // is a question somebody eventually asks in SQL.
    if (!$DB->tableExists('glpi_plugin_glpimajor_piractions')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpimajor_piractions` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpimajor_pirs_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `content` TEXT NULL,
                `users_id_owner` INT UNSIGNED NOT NULL DEFAULT 0,
                `due_date` DATE NULL DEFAULT NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT 'open',
                `improve_candidates_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_pushed` TIMESTAMP NULL DEFAULT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `pir` (`plugin_glpimajor_pirs_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // One audit table rather than four, because the question asked of it is
    // always "what happened to this incident, in order".
    if (!$DB->tableExists('glpi_plugin_glpimajor_events')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpimajor_events` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `plugin_glpimajor_incidents_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `action` VARCHAR(32) NOT NULL DEFAULT '',
                `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `detail` TEXT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `incident` (`plugin_glpimajor_incidents_id`,`date_creation`),
                KEY `sweep` (`date_creation`),
                KEY `entities_id` (`entities_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // The feed's state transitions (0.1.2). NOT the audit table, on purpose:
    // events rows are pruned on a retention cron and carry the transition as
    // a debug sentence, and the war-room feed needs typed columns that stay
    // for as long as the update log they interleave with. `outcome` is a
    // snapshot of what was written at the moment of resolving — the same
    // argument as `state_at_time` on the updates table. The declaration is
    // deliberately not a row here; the incident row already says who, when
    // and from which ticket, and the feed reads it from there (see Feed).
    if (!$DB->tableExists('glpi_plugin_glpimajor_statechanges')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpimajor_statechanges` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpimajor_incidents_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `state_from` VARCHAR(16) NOT NULL DEFAULT '',
                `state_to` VARCHAR(16) NOT NULL DEFAULT '',
                `outcome` TEXT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `incident` (`plugin_glpimajor_incidents_id`,`date_creation`),
                KEY `entities_id` (`entities_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // The public post-mortem (0.1.3), one per incident like the PIR — and
    // deliberately not a column on the PIR. The PIR is the house being honest
    // with itself; this is the same story told across the counter, and the
    // two must be free to disagree in tone without either censoring the
    // other. `published_at` is the stamp the status page prints; NULL while
    // it is a draft and after a retraction. `ai_drafted` mirrors the update
    // log's `ai_reviewed` honesty: when the text originated as a model's
    // draft, the row says so permanently, however much a human edited it —
    // publishing itself is always the human's act.
    if (!$DB->tableExists('glpi_plugin_glpimajor_postmortems')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_glpimajor_postmortems` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_glpimajor_incidents_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `content` TEXT NULL,
                `is_published` TINYINT NOT NULL DEFAULT 0,
                `published_at` TIMESTAMP NULL DEFAULT NULL,
                `users_id_author` INT UNSIGNED NOT NULL DEFAULT 0,
                `users_id_publisher` INT UNSIGNED NOT NULL DEFAULT 0,
                `ai_drafted` TINYINT NOT NULL DEFAULT 0,
                `date_creation` TIMESTAMP NULL DEFAULT NULL,
                `date_mod` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `one_per_incident` (`plugin_glpimajor_incidents_id`),
                KEY `entities_id` (`entities_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate"
        );
    }

    // Five minutes, because the unit of a broken promise is a minute and a
    // customer waiting on an update notices a quarter of an hour.
    CronTask::register(
        Incident::class,
        'nag',
        5 * MINUTE_TIMESTAMP,
        [
            'state'         => CronTask::STATE_WAITING,
            'mode'          => CronTask::MODE_EXTERNAL,
            'allowmode'     => CronTask::MODE_EXTERNAL,
            'logs_lifetime' => 30,
            'comment'       => 'Remind the comms owner when a promised update has passed',
        ]
    );

    // The page is regenerated synchronously on every customer-visible change.
    // This is the backstop that makes a missed regeneration self-heal, rather
    // than leaving a customer reading yesterday and nobody knowing.
    CronTask::register(
        Page::class,
        'publish',
        15 * MINUTE_TIMESTAMP,
        [
            'state'         => CronTask::STATE_WAITING,
            'mode'          => CronTask::MODE_EXTERNAL,
            'allowmode'     => CronTask::MODE_EXTERNAL,
            'logs_lifetime' => 30,
            'comment'       => 'Republish stale status pages and roll maintenance windows',
        ]
    );

    CronTask::register(
        Events::class,
        'prune',
        DAY_TIMESTAMP,
        [
            'state'         => CronTask::STATE_WAITING,
            'mode'          => CronTask::MODE_EXTERNAL,
            'allowmode'     => CronTask::MODE_EXTERNAL,
            'logs_lifetime' => 30,
            'comment'       => 'Drop major-incident audit rows past the retention window',
        ]
    );

    plugin_glpimajor_migrate();

    plugin_glpimajor_install_rights();

    Notifications::install();

    // Seeded rather than left to the Settings defaults so the values are visible
    // in the config table — an administrator auditing what a plugin will do
    // should be able to read it, not infer it from code.
    Config::setConfigurationValues(PLUGIN_GLPIMAJOR_CONFIG_CONTEXT, Settings::DEFAULTS);

    return true;
}

/**
 * Columns added after the first release, applied to a table that already exists.
 *
 * The install hook runs on upgrade as well as on first install, and every
 * CREATE TABLE above is guarded by `tableExists()` — so a table made by an
 * earlier version is never revisited by them. Anything added later has to be
 * added here, idempotently, or the plugin ships a column that only exists on
 * instances that installed it fresh.
 *
 * `is_recursive` defaults to 0 on both tables on purpose. An instance upgrading
 * into this feature has incidents and windows that were declared under a model
 * where propagation did not exist; giving any of them a subtree retroactively
 * would publish an outage onto a page whose customer never agreed to see it.
 */
function plugin_glpimajor_migrate()
{
    /** @var DBmysql $DB */
    global $DB;

    $columns = [
        'glpi_plugin_glpimajor_incidents' => [
            'is_recursive' => "ADD COLUMN `is_recursive` TINYINT NOT NULL DEFAULT 0 AFTER `tickets_id`,"
                            . " ADD KEY `recursive` (`is_recursive`,`state`)",
        ],
        'glpi_plugin_glpimajor_maintenances' => [
            'is_recursive' => "ADD COLUMN `is_recursive` TINYINT NOT NULL DEFAULT 0 AFTER `entities_id`",
        ],
    ];

    foreach ($columns as $table => $adds) {
        if (!$DB->tableExists($table)) {
            continue;
        }

        foreach ($adds as $field => $clause) {
            if ($DB->fieldExists($table, $field)) {
                continue;
            }

            $DB->doQuery("ALTER TABLE `$table` $clause");
        }
    }

    // The `source`/`external_key` pair became nullable after the first release.
    //
    // As NOT NULL DEFAULT '' they made the unique key on the pair apply to every
    // window nobody pushed: an administrator could create one maintenance window
    // by hand on the whole instance, and the second died on a duplicate key. Two
    // NULLs are not equal in MySQL, which is what makes the idempotent upsert and
    // hand-created windows able to share one index.
    $windows = 'glpi_plugin_glpimajor_maintenances';
    if ($DB->tableExists($windows)) {
        $nullable = false;
        foreach ($DB->request([
            'SELECT' => ['IS_NULLABLE'],
            'FROM'   => 'information_schema.COLUMNS',
            'WHERE'  => [
                'TABLE_SCHEMA' => new \Glpi\DBAL\QueryExpression('DATABASE()'),
                'TABLE_NAME'   => $windows,
                'COLUMN_NAME'  => 'source',
            ],
        ]) as $row) {
            $nullable = strtoupper((string) $row['IS_NULLABLE']) === 'YES';
        }

        if (!$nullable) {
            $DB->doQuery(
                "ALTER TABLE `$windows`"
                . " MODIFY COLUMN `source` VARCHAR(100) NULL DEFAULT NULL,"
                . " MODIFY COLUMN `external_key` VARCHAR(190) NULL DEFAULT NULL"
            );
            $DB->doQuery("UPDATE `$windows` SET `source` = NULL WHERE `source` = ''");
            $DB->doQuery("UPDATE `$windows` SET `external_key` = NULL WHERE `external_key` = ''");
        }
    }

    // 0.1.1 — `affected_count` changed meaning: it is now *tickets impacted,
    // including the one the incident was declared from* (attached + 1), because
    // a freshly declared incident reading "0 affected tickets" told everyone
    // the outage's first ticket did not count. No schema change; the stored
    // numbers on existing incidents are stale under the new semantics until
    // recounted, so recount them all. Affected::recount() owns the column and
    // is idempotent, which is what lets this run on every upgrade rather than
    // being fenced behind a version comparison somebody has to keep honest.
    $incidents = 'glpi_plugin_glpimajor_incidents';
    if ($DB->tableExists($incidents)) {
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => $incidents]) as $row) {
            \GlpiPlugin\Glpimajor\Affected::recount((int) $row['id']);
        }
    }
}

/**
 * Declaring a major incident and seeing one are separate grants of one right.
 *
 * Declaring is deliberate — the brief's word is "not a whim" — so full rights go
 * only to profiles that already administer configuration. READ goes to everyone
 * who can read a ticket, because the banner is invisible without it and a
 * banner nobody can see is the exact failure this plugin exists to prevent.
 *
 * Readers are granted first and administrators second:
 * ProfileRight::updateProfileRights() overwrites rather than merges, so a
 * profile in both sets must be written with the wider value last.
 */
function plugin_glpimajor_install_rights()
{
    /** @var DBmysql $DB */
    global $DB;

    // Both are ALLSTANDARDRIGHT for an administrator, and the second one has to
    // be. `plugin_glpimajor_config` is not only the settings page's right: it
    // is the Maintenance itemtype's `$rightname`, and GLPI gates an itemtype's
    // *new-item form* on CREATE — `ajax/common.tabs.php` returns nothing at all
    // when `can(-1, CREATE)` is false. Granted READ|UPDATE, the menu offered an
    // Add button that led to a blank page, and no window could ever be created
    // or purged from the interface; the only way in was the `announce()` seam
    // another plugin calls.
    $rights = [
        'plugin_glpimajor_declare' => ALLSTANDARDRIGHT,
        'plugin_glpimajor_config'  => ALLSTANDARDRIGHT,
    ];

    // Only add rights that are not already registered.
    //
    // GLPI runs the install hook on upgrade as well as on first install, and
    // ProfileRight::addProfileRights() inserts unconditionally — so calling it
    // for an existing right raises a duplicate-key error that aborts the whole
    // upgrade. Every plugin update would fail once it had ever been installed.
    $existing = [];
    foreach (
        $DB->request([
            'SELECT'   => ['name'],
            'DISTINCT' => true,
            'FROM'     => 'glpi_profilerights',
            'WHERE'    => ['name' => array_keys($rights)],
        ]) as $row
    ) {
        $existing[(string) $row['name']] = true;
    }

    foreach (array_keys($rights) as $right) {
        if (!isset($existing[$right])) {
            ProfileRight::addProfileRights([$right]);
        }
    }

    $readers = [];
    foreach (
        $DB->request([
            'SELECT' => ['profiles_id'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => 'ticket', 'rights' => ['&', READ]],
        ]) as $row
    ) {
        $readers[] = (int) $row['profiles_id'];
    }

    foreach (array_unique($readers) as $profiles_id) {
        ProfileRight::updateProfileRights($profiles_id, ['plugin_glpimajor_declare' => READ]);
    }

    // Keying off the installing user's session does not work on its own:
    // plugins are routinely installed from the console, where there is no active
    // profile, and the plugin would then be installed but usable by nobody.
    $admins = [];
    foreach (
        $DB->request([
            'SELECT' => ['profiles_id'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => 'config', 'rights' => ['&', UPDATE]],
        ]) as $row
    ) {
        $admins[] = (int) $row['profiles_id'];
    }

    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $admins[] = (int) $_SESSION['glpiactiveprofile']['id'];
    }

    foreach (array_unique($admins) as $profiles_id) {
        ProfileRight::updateProfileRights($profiles_id, $rights);
    }
}

function plugin_glpimajor_uninstall()
{
    /** @var DBmysql $DB */
    global $DB;

    foreach (
        [
            'incidents', 'affected', 'updates', 'snippets', 'pages',
            'maintenances', 'pirs', 'piractions', 'events',
            // statechanges (0.1.2) was missing from this list for one
            // release; postmortems joined in 0.1.3.
            'statechanges', 'postmortems',
        ] as $suffix
    ) {
        $table = "glpi_plugin_glpimajor_$suffix";
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    foreach ([Incident::class, Page::class, Events::class] as $class) {
        $DB->delete('glpi_crontasks', ['itemtype' => $class]);
    }

    Notifications::uninstall();

    ProfileRight::deleteProfileRights(['plugin_glpimajor_declare']);
    ProfileRight::deleteProfileRights(['plugin_glpimajor_config']);

    Config::deleteConfigurationValues(
        PLUGIN_GLPIMAJOR_CONFIG_CONTEXT,
        array_keys(Settings::DEFAULTS)
    );

    // Published pages are the only thing this plugin leaves outside the
    // database, and leaving them behind would mean a token that still serves a
    // customer-facing page after the plugin is gone.
    Page::purgeAllFiles();

    return true;
}
