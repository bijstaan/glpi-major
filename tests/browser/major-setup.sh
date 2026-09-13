#!/usr/bin/env bash
# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright (C) 2026 Bijstaan
# Put the instance into the state major-check.js starts from: one organisation
# entity of our own, the people in it, and two tickets that read like a real
# morning — one from the practice manager, one from a solicitor an hour later,
# about the same dead file server.
#
# Nothing here declares the incident, attaches anything, or publishes. That is
# all major-check.js's job, because that is what is being tested; this only
# makes the world the test happens in.
#
# Re-runnable. Every run clears the previous demo's tickets, incident, page and
# windows out of the demo entity and lays down fresh ones, so the browser check
# always starts from a ticket that is not yet a major incident.
#
# It touches exactly three plugin settings, and does it by writing raw
# `glpi_configs` rows: the getConfigurationValues/setConfigurationValues round
# trip re-encrypts every secure field in a context on the way back in, and a
# fixture script is not worth that risk.
#
# Prints ENTITY_ID, MANCHESTER_ID, LONDON_ID, TICKET_MI, TICKET_DUP,
# TICKET_BRANCH, COMMANDER, COMMS, PORTAL_USER and PORTAL_PASSWORD for the
# caller.
set -u

docker exec -i glpi-glpi-1 php <<'PHPEOF'
<?php

require_once '/var/www/glpi/vendor/autoload.php';

(new Glpi\Kernel\Kernel(Glpi\Application\Environment::PRODUCTION->value))->boot();
(new Auth())->login('glpi', 'glpi', true);
(new Plugin())->init(true);

/** @var DBmysql $DB */
global $DB;

$ENTITY = 'Ravensworth Legal';

// ------------------------------------------------------------------ entity
//
// The firm, and its two offices. The offices exist so the recursion half of
// this plugin has somewhere real to happen: an outage declared at the firm and
// marked as covering its sub-entities has to appear on both offices' status
// pages, and a solicitor in Manchester has to see it on the portal.

/** Find an entity by name under a parent, or make it. */
$entity_named = static function (string $name, int $parent, string $comment) use ($DB): int {
    foreach (
        $DB->request([
            'FROM'  => 'glpi_entities',
            'WHERE' => ['name' => $name, 'entities_id' => $parent],
            'LIMIT' => 1,
        ]) as $row
    ) {
        return (int) $row['id'];
    }

    $entity = new Entity();

    return (int) $entity->add([
        'name'        => $name,
        'entities_id' => $parent,
        'comment'     => $comment,
    ]);
};

$entities_id = $entity_named(
    $ENTITY,
    0,
    'Demo fixture for glpi-major: a 24-person law firm on a managed contract. '
    . 'Created by major-setup.sh.'
);

if ($entities_id <= 0) {
    fwrite(STDERR, "could not create the demo entity\n");
    exit(1);
}

$manchester = $entity_named(
    'Ravensworth Legal — Manchester',
    $entities_id,
    'One of the firm\'s two offices. Exists so a recursive incident declared at the firm has '
    . 'somewhere to propagate to.'
);
$london = $entity_named(
    'Ravensworth Legal — London',
    $entities_id,
    'The firm\'s other office.'
);

if ($manchester <= 0 || $london <= 0) {
    fwrite(STDERR, "could not create the office entities\n");
    exit(1);
}

Session::changeActiveEntities($entities_id, true);

// ------------------------------------------------------------------- people

/**
 * Create a user if they are not there, and give them a profile in an entity.
 *
 * `$password` is set only for the one person the browser check actually logs in
 * as — the requester in Manchester, who is how the self-service portal gets
 * exercised by somebody who is not an administrator. The rest are names on
 * tickets and never sign in.
 */
$person = static function (
    string $login,
    string $first,
    string $last,
    int $profiles_id,
    ?int $in_entity = null,
    string $password = ''
) use ($DB, $entities_id): int {
    $in_entity = $in_entity ?? $entities_id;

    $users_id = null;
    foreach ($DB->request(['FROM' => 'glpi_users', 'WHERE' => ['name' => $login], 'LIMIT' => 1]) as $row) {
        $users_id = (int) $row['id'];
    }

    $fields = [
        'name'        => $login,
        'firstname'   => $first,
        'realname'    => $last,
        'is_active'   => 1,
        'entities_id' => $in_entity,
    ];

    if ($password !== '') {
        $fields['password']  = $password;
        $fields['password2'] = $password;
    }

    if ($users_id === null) {
        $user     = new User();
        $users_id = (int) $user->add($fields);
    } elseif ($password !== '') {
        // Re-runnable: a demo user whose password nobody remembers is a demo
        // that cannot be run twice.
        $user = new User();
        $user->update($fields + ['id' => $users_id]);
    }

    $has = false;
    foreach (
        $DB->request([
            'FROM'  => 'glpi_profiles_users',
            'WHERE' => ['users_id' => $users_id, 'entities_id' => $in_entity, 'profiles_id' => $profiles_id],
            'LIMIT' => 1,
        ]) as $_
    ) {
        $has = true;
    }

    if (!$has) {
        $link = new Profile_User();
        $link->add([
            'users_id'     => $users_id,
            'profiles_id'  => $profiles_id,
            'entities_id'  => $in_entity,
            'is_recursive' => 0,
        ]);
    }

    return $users_id;
};

$SELF = 1;   // Self-Service
$TECH = 6;   // Technician

$hannah  = $person('hannah.dalby', 'Hannah', 'Dalby', $SELF);
$tom     = $person('tom.brennan', 'Tom', 'Brennan', $SELF);
$michael = $person('michael.okafor', 'Michael', 'Okafor', $TECH);
$priya   = $person('priya.raman', 'Priya', 'Raman', $TECH);

// A solicitor in the Manchester office, with a password, because the portal
// half of this plugin is only true if somebody who is not an administrator can
// log in and see it.
$PORTAL_PASSWORD = 'Ravensworth!2026';
$nadia = $person(
    'nadia.whitfield',
    'Nadia',
    'Whitfield',
    $SELF,
    $manchester,
    $PORTAL_PASSWORD
);

// Reset to the default (light) palette on every run. major-check.js's own
// dark-theme pass switches this to auror_dark for the duration of its own
// checks — a run that was interrupted mid-way, or a stray manual test,
// could otherwise leave nadia stuck on a dark palette forever and silently
// turn every later "light theme" screenshot dark too. This is the one
// place that resets her back to what a fresh fixture starts as.
$DB->update('glpi_users', ['palette' => ''], ['id' => $nadia]);

// ------------------------------------------------------------------- reset

// The demo entity and its two offices are ours alone, so everything in them can
// go — including the offices' own pages, or the browser check would find a
// Manchester address left over from the last run and never exercise minting it.
$OURS = [$entities_id, $manchester, $london];

foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['entities_id' => $OURS]]) as $row) {
    $ticket = new Ticket();
    if ($ticket->getFromDB((int) $row['id'])) {
        $ticket->delete(['id' => (int) $row['id']], true);
    }
}

foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_glpimajor_incidents', 'WHERE' => ['entities_id' => $OURS]]) as $row) {
    $incident = new GlpiPlugin\Glpimajor\Incident();
    if ($incident->getFromDB((int) $row['id'])) {
        $incident->delete(['id' => (int) $row['id']], true);
    }
}

foreach ($DB->request(['FROM' => 'glpi_plugin_glpimajor_pages', 'WHERE' => ['entities_id' => $OURS]]) as $row) {
    GlpiPlugin\Glpimajor\Page::removeFile((string) $row['token']);
    $DB->delete('glpi_plugin_glpimajor_pages', ['id' => (int) $row['id']]);
}

$DB->delete('glpi_plugin_glpimajor_maintenances', ['entities_id' => $OURS]);
$DB->delete('glpi_plugin_glpimajor_events', ['entities_id' => $OURS]);

// ----------------------------------------------------------------- settings

// Raw rows, on purpose. See the header.
$settings = [
    // Publishing is off by default and the whole demo is about the page.
    'status_enabled'       => '1',
    'status_support_email' => 'servicedesk@bijstaan.example',
    'status_support_phone' => '+44 20 7946 0312',
    // Both on by default, but pinned here so a run after somebody switched
    // them off in the interface still tests what it says it tests.
    'portal_banner'        => '1',
    'portal_link'          => '1',
    // Left at its default: the browser check ticks the coverage box itself,
    // which is the control being tested.
    'recursive_default'    => '0',
];

foreach ($settings as $name => $value) {
    // Upsert, not update. A setting added by a later version of the plugin has
    // no row until the install hook is re-run, and an UPDATE that matches
    // nothing succeeds silently — so a fixture script that only updates would
    // quietly stop pinning the settings it says it pins.
    $exists = false;
    foreach (
        $DB->request([
            'FROM'  => 'glpi_configs',
            'WHERE' => ['context' => 'plugin:glpimajor', 'name' => $name],
            'LIMIT' => 1,
        ]) as $_
    ) {
        $exists = true;
    }

    if ($exists) {
        $DB->update('glpi_configs', ['value' => $value], [
            'context' => 'plugin:glpimajor',
            'name'    => $name,
        ]);
    } else {
        $DB->insert('glpi_configs', [
            'context' => 'plugin:glpimajor',
            'name'    => $name,
            'value'   => $value,
        ]);
    }
}

// ------------------------------------------------------------------ tickets

$category = 0;
foreach (
    $DB->request([
        'SELECT' => ['id'],
        'FROM'   => 'glpi_itilcategories',
        'WHERE'  => ['completename' => 'Hardware > Servers'],
        'LIMIT'  => 1,
    ]) as $row
) {
    $category = (int) $row['id'];
}

$ticket = new Ticket();

$mi = (int) $ticket->add([
    'name'                 => 'Nobody in the office can open anything on the S: drive',
    'content'              => "Since about ten past nine none of us can open documents on the S: drive. "
                            . "It just spins and then says the network path was not found. I have tried "
                            . "restarting my laptop and so has Tom. Post is due out by twelve and the "
                            . "letters are all on there.",
    'entities_id'          => $entities_id,
    'itilcategories_id'    => $category,
    'type'                 => Ticket::INCIDENT_TYPE,
    'urgency'              => 4,
    'impact'               => 4,
    'status'               => Ticket::ASSIGNED,
    '_users_id_requester'  => $hannah,
    '_users_id_assign'     => $michael,
]);

$dup = (int) $ticket->add([
    'name'                 => 'Cannot open the client files on the shared drive',
    'content'              => "I am trying to get at the Ferris completion file and Windows keeps telling "
                            . "me it cannot find S:. It was working first thing. Is it just me?",
    'entities_id'          => $entities_id,
    'itilcategories_id'    => $category,
    'type'                 => Ticket::INCIDENT_TYPE,
    'urgency'              => 3,
    'impact'               => 3,
    'status'               => Ticket::ASSIGNED,
    '_users_id_requester'  => $tom,
]);

// The Manchester office's own ticket about the same outage. It is in a
// *different entity* from the incident, and is the one that proves propagation
// down the tree: without the coverage box it must be offered nothing at all.
$branch = (int) $ticket->add([
    'name'                 => 'Nothing on the shared drive will open from the Manchester office',
    'content'              => "Same as the Leeds lot by the sound of it — none of us up here can get at "
                            . "the client files either. I have got a completion at two.",
    'entities_id'          => $manchester,
    'itilcategories_id'    => $category,
    'type'                 => Ticket::INCIDENT_TYPE,
    'urgency'              => 3,
    'impact'               => 3,
    'status'               => Ticket::ASSIGNED,
    '_users_id_requester'  => $nadia,
]);

if ($mi <= 0 || $dup <= 0 || $branch <= 0) {
    fwrite(STDERR, "could not create the demo tickets\n");
    exit(1);
}

printf(
    "ENTITY_ID=%d\nMANCHESTER_ID=%d\nLONDON_ID=%d\nTICKET_MI=%d\nTICKET_DUP=%d\n"
    . "TICKET_BRANCH=%d\nCOMMANDER=%d\nCOMMS=%d\nPORTAL_USER=%s\nPORTAL_PASSWORD=%s\n",
    $entities_id, $manchester, $london, $mi, $dup, $branch, $michael, $priya,
    'nadia.whitfield', $PORTAL_PASSWORD
);
PHPEOF
