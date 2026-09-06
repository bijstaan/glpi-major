<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The half of this plugin that only exists once there is a database.
 *
 * tests/nag.php, tests/matching.php and tests/statuspage.php cover everything
 * that was written to be pure. What they cannot reach is the half that decides
 * whether the plugin is safe to run on a customer's instance: whether declaring
 * really is gated on a right, whether attaching a ticket respects the tenant
 * boundary, and — the one that matters most — whether resolution *proposes* a
 * solution on twelve tickets or quietly closes them.
 *
 * Every claim asserted here is one this plugin's README makes in public:
 *
 *   - declaring is gated on `plugin_glpimajor_declare`, and the way in
 *     disappears for a profile without it
 *   - one incident per ticket, enforced
 *   - the matcher offers the incident in the same entity and never one in
 *     another; `Affected::attach()` refuses across the boundary even when a
 *     caller does not
 *   - attaching links with SON_OF in the direction core reads as parent/child
 *   - resolution writes an ITILSolution with `CommonITILValidation::WAITING` to
 *     every attached ticket, changes no ticket's status, and assigns nobody —
 *     the three suppression flags, verified by their effects rather than by
 *     reading the constants back
 *   - the update log keeps its audiences apart, and the published document has
 *     never held an internal sentence
 *   - the publisher writes a real file, named for the token, containing none of
 *     GLPI's vocabulary and not the token itself
 *   - regenerating an address retires the old file immediately
 *   - a completed review locks, the lock has a key, and turning it is recorded
 *
 *   - a recursive incident reaches down its declaring entity's subtree and
 *     nowhere else: onto every descendant's published page, into their
 *     tickets' duplicate offers and attachments, and never onto a sibling's
 *     page or up onto a parent's
 *
 * **It writes.** It creates a small entity tree of its own — a parent with two
 * offices under it, and an unrelated entity at the root to be the sibling that
 * must never see anything — plus tickets inside them, incidents, status pages
 * and a review, and it removes all of it on the way out, including on a fatal,
 * through a shutdown handler. Nothing it makes lives in an entity that was
 * there before it ran.
 *
 * That last property is why the recursion section builds its own
 * "— Manchester" and "— London" offices rather than the ones under the demo
 * entity `Ravensworth Legal`: those are fixtures the browser check depends on
 * and this suite purges everything it touches. The browser check asserts the
 * same propagation against the real demo tree; this one asserts it against a
 * tree it is free to destroy.
 *
 * It changes exactly one setting, `status_enabled`, because a publisher that
 * cannot publish cannot be tested. That is snapshotted and restored as a raw
 * `glpi_configs` row rather than through Config::setConfigurationValues(),
 * which re-encrypts every secure field in the context on the way back in.
 *
 * Usage, from anywhere:
 *
 *     docker exec glpi-glpi-1 php \
 *       /var/www/glpi/plugins/glpimajor/tests/db-live.php --yes-i-mean-it
 *
 * The flag is not decoration. Without it the file refuses to run, so a suite
 * runner that does not know what this one does cannot start it by accident.
 */

declare(strict_types=1);

use Glpi\Application\Environment;
use Glpi\Kernel\Kernel;
use GlpiPlugin\Glpimajor\Affected;
use GlpiPlugin\Glpimajor\Events;
use GlpiPlugin\Glpimajor\Feed;
use GlpiPlugin\Glpimajor\Incident;
use GlpiPlugin\Glpimajor\Maintenance;
use GlpiPlugin\Glpimajor\Matcher;
use GlpiPlugin\Glpimajor\Page;
use GlpiPlugin\Glpimajor\Pir;
use GlpiPlugin\Glpimajor\Portal;
use GlpiPlugin\Glpimajor\PirAction;
use GlpiPlugin\Glpimajor\Postmortem;
use GlpiPlugin\Glpimajor\Publisher;
use GlpiPlugin\Glpimajor\Renderer;
use GlpiPlugin\Glpimajor\Settings;
use GlpiPlugin\Glpimajor\StateChange;
use GlpiPlugin\Glpimajor\Tree;
use GlpiPlugin\Glpimajor\Update;

if (!in_array('--yes-i-mean-it', $argv, true)) {
    fwrite(
        STDERR,
        "Refusing to run.\n\n"
        . "This suite writes to the database: an entity tree of its own, seven tickets, two\n"
        . "incidents, four status pages, maintenance windows and a review. It cleans up\n"
        . "after itself, but it is not a suite to start by accident. Run it against a\n"
        . "development instance with:\n\n"
        . "    php tests/db-live.php --yes-i-mean-it\n"
    );
    exit(2);
}

// GLPI 11 has no procedural bootstrap; `inc/includes.php` is a shim that warns
// about globals it no longer honours. The kernel is the entry point, the login
// is what gives Session::haveRight() something to answer from, and Plugin::init
// is what makes this plugin's classes and hooks exist at all.
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

(new Kernel(Environment::PRODUCTION->value))->boot();
(new Auth())->login('glpi', 'glpi', true);
(new Plugin())->init(true);

/** @var DBmysql $DB */
global $DB;

// ------------------------------------------------------------------ harness

$failures = [];
$passes   = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $passes;

    echo ($ok ? "  \033[32mPASS\033[0m  " : "  \033[31mFAIL\033[0m  ") . $label
       . ($detail !== '' ? " :: " . substr($detail, 0, 200) : '') . "\n";

    if ($ok) {
        $passes++;
    } else {
        $failures[] = $label;
    }
}

function is_same(string $label, mixed $got, mixed $want): void
{
    check($label, $got === $want, $got === $want ? '' : sprintf(
        'expected %s, got %s',
        var_export($want, true),
        var_export($got, true)
    ));
}

function section(string $title): void
{
    echo "\n\033[1m$title\033[0m\n";
}

/** One row, or null. */
function row(string $table, array $where): ?array
{
    global $DB;

    foreach ($DB->request(['FROM' => $table, 'WHERE' => $where, 'LIMIT' => 1]) as $r) {
        return $r;
    }

    return null;
}

function count_rows(string $table, array $where): int
{
    global $DB;

    foreach ($DB->request(['COUNT' => 'c', 'FROM' => $table, 'WHERE' => $where]) as $r) {
        return (int) $r['c'];
    }

    return 0;
}

/** Did the audit trail record this? */
function had_event(int $incidents_id, string $action): bool
{
    return count_rows('glpi_plugin_glpimajor_events', [
        'plugin_glpimajor_incidents_id' => $incidents_id,
        'action'                        => $action,
    ]) > 0;
}

// --------------------------------------------------------------- teardown
//
// Registered before anything is created, so a fatal in the middle of the suite
// still leaves the instance as it was found. Everything is addressed by an id
// this run created; nothing is deleted by pattern.

$made = [
    'entities'  => [],
    'tickets'   => [],
    'incidents' => [],
];

$config_was = null;

$teardown = static function () use (&$made, &$config_was): void {
    /** @var DBmysql $DB */
    global $DB;

    foreach ($made['entities'] as $entities_id) {
        $page = Page::forEntity((int) $entities_id);
        if ($page !== null) {
            Page::removeFile((string) $page['token']);
            $DB->delete(Page::getTable(), ['id' => (int) $page['id']]);
        }
    }

    foreach ($made['incidents'] as $incidents_id) {
        $incident = new Incident();
        if ($incident->getFromDB((int) $incidents_id)) {
            $incident->delete(['id' => (int) $incidents_id], true);
        }
        // The audit rows deliberately outlive their incident, which is right in
        // production and litter here.
        $DB->delete('glpi_plugin_glpimajor_events', ['plugin_glpimajor_incidents_id' => (int) $incidents_id]);
    }

    foreach ($made['tickets'] as $tickets_id) {
        $ticket = new Ticket();
        if ($ticket->getFromDB((int) $tickets_id)) {
            $ticket->delete(['id' => (int) $tickets_id], true);
        }
    }

    // Deepest first. Entities are created parents-before-children, so ids
    // ascend with depth and the reverse is a safe deletion order — a parent
    // purged while it still has sub-entities leaves them orphaned at the root,
    // which is litter this suite promises not to make.
    $entities = $made['entities'];
    rsort($entities);

    foreach ($entities as $entities_id) {
        // Publication events carry the entity, not an incident.
        $DB->delete('glpi_plugin_glpimajor_events', ['entities_id' => (int) $entities_id]);
        $DB->delete('glpi_plugin_glpimajor_maintenances', ['entities_id' => (int) $entities_id]);

        $entity = new Entity();
        if ($entity->getFromDB((int) $entities_id)) {
            $entity->delete(['id' => (int) $entities_id], true);
        }
    }

    if ($config_was !== null) {
        $DB->update(
            'glpi_configs',
            ['value' => $config_was],
            ['context' => PLUGIN_GLPIMAJOR_CONFIG_CONTEXT, 'name' => 'status_enabled']
        );
    }
};

register_shutdown_function($teardown);

// ------------------------------------------------------------------ fixtures

$uniq = substr(bin2hex(random_bytes(4)), 0, 8);

$entity = new Entity();

$eA = (int) $entity->add(['name' => "glpimajor db-live $uniq", 'entities_id' => 0]);
$eB = (int) $entity->add(['name' => "glpimajor db-live other $uniq", 'entities_id' => 0]);

if ($eA <= 0 || $eB <= 0) {
    fwrite(STDERR, "Could not create the test entities. Nothing was run.\n");
    exit(1);
}

$made['entities'] = [$eA, $eB];

// The session's entity list is computed at login and does not know about an
// entity created a moment ago.
Session::changeActiveEntities($eA, true);

$CATEGORY = 0;
foreach ($DB->request(['FROM' => 'glpi_itilcategories', 'WHERE' => ['is_recursive' => 1], 'LIMIT' => 1]) as $r) {
    $CATEGORY = (int) $r['id'];
}

$ticket = new Ticket();

$t1 = (int) $ticket->add([
    'name'              => "db-live $uniq: file server unreachable",
    'content'           => 'Nobody in the office can open anything on the shared drive.',
    'entities_id'       => $eA,
    'itilcategories_id' => $CATEGORY,
    'status'            => Ticket::ASSIGNED,
]);

$t2 = (int) $ticket->add([
    'name'              => "db-live $uniq: cannot open the shared drive",
    'content'           => 'I get an error every time I try to open a file on the S drive.',
    'entities_id'       => $eA,
    'itilcategories_id' => $CATEGORY,
    'status'            => Ticket::ASSIGNED,
]);

$t3 = (int) $ticket->add([
    'name'              => "db-live $uniq: cannot open the shared drive",
    'content'           => 'Same words, different customer.',
    'entities_id'       => $eB,
    'itilcategories_id' => $CATEGORY,
    'status'            => Ticket::ASSIGNED,
]);

if ($t1 <= 0 || $t2 <= 0 || $t3 <= 0) {
    fwrite(STDERR, "Could not create the test tickets. Nothing was run.\n");
    exit(1);
}

$made['tickets'] = [$t1, $t2, $t3];

// ============================================================ 1. declaring

section('Declaring is a right, not a button');

$profile_was = $_SESSION['glpiactiveprofile'][Incident::$rightname] ?? 0;

$_SESSION['glpiactiveprofile'][Incident::$rightname] = 0;
is_same('with the right withheld, the menu entry is gone', Incident::getMenuContent(), false);

$_SESSION['glpiactiveprofile'][Incident::$rightname] = READ;
$menu = Incident::getMenuContent();
check(
    'READ alone gets the list but no Add button',
    is_array($menu) && !isset($menu['links']['add']),
    is_array($menu) ? implode(',', array_keys($menu['links'])) : 'no menu'
);

$_SESSION['glpiactiveprofile'][Incident::$rightname] = $profile_was;
check(
    'the installed administrator profile holds CREATE',
    (bool) Session::haveRight(Incident::$rightname, CREATE)
);

$ticket->getFromDB($t1);

$incidents_id = Incident::declareFor($ticket, [
    'name'               => 'Shared drive unavailable',
    'users_id_commander' => (int) Session::getLoginUserID(),
    'users_id_comms'     => (int) Session::getLoginUserID(),
]);

check('a ticket can be declared', is_int($incidents_id) && $incidents_id > 0, var_export($incidents_id, true));

if (!is_int($incidents_id) || $incidents_id <= 0) {
    fwrite(STDERR, "Declaration failed; the rest of the suite has nothing to run against.\n");
    exit(1);
}

$made['incidents'][] = $incidents_id;

$incident = new Incident();
$incident->getFromDB($incidents_id);

is_same('it belongs to the ticket', (int) $incident->fields['tickets_id'], $t1);
is_same('it inherits the ticket\'s entity', (int) $incident->fields['entities_id'], $eA);
is_same('it starts investigating', (string) $incident->fields['state'], Incident::INVESTIGATING);
is_same('the declarer is recorded', (int) $incident->fields['users_id_declared'], (int) Session::getLoginUserID());
check('the customer-visible title is its own field',
    (string) $incident->fields['name'] === 'Shared drive unavailable'
    && (string) $incident->fields['name'] !== (string) $ticket->fields['name']);
check('the declaration is in the audit trail', had_event($incidents_id, Events::DECLARED));

// `affected_count` means "tickets impacted, including the one the incident was
// declared from" — so a fresh declaration reads 1, not 0, without any row in
// the attach table (the declaring ticket never gets one; see below).
is_same('a fresh declaration counts its own ticket', (int) $incident->fields['affected_count'], 1);
is_same('without a row in the attach table', count_rows(Affected::TABLE, [
    'plugin_glpimajor_incidents_id' => $incidents_id,
]), 0);

// The ticket itself is untouched: promotion is a mode, not a rewrite.
$after = new Ticket();
$after->getFromDB($t1);
is_same('declaring does not touch the ticket\'s status', (int) $after->fields['status'], Ticket::ASSIGNED);

is_same(
    'a second declaration on the same ticket is refused',
    Incident::declareFor($ticket, ['name' => 'Again']),
    false
);
is_same(
    'and there is still exactly one incident for it',
    count_rows(Incident::getTable(), ['tickets_id' => $t1]),
    1
);

// ============================================================== 2. matching

section('The duplicate offer, and the tenant boundary');

$rules = Settings::matchRules();
$open  = Incident::openIn($eA);

is_same('the incident is open in its own entity', count($open), 1);

$best = Matcher::best(
    [
        'id'                => $t2,
        'entities_id'       => $eA,
        'itilcategories_id' => $CATEGORY,
        'locations_id'      => 0,
        'name'              => (string) $ticket->fields['name'],
    ],
    $open,
    $rules
);

check('a like ticket in the same entity is offered the incident',
    $best !== null && (int) $best['incident']['id'] === $incidents_id,
    $best === null ? 'no match' : Matcher::explain($best['reasons']));

is_same('no incident is even visible from the other entity', count(Incident::openIn($eB)), 0);

$across = Matcher::best(
    [
        'id'                => $t3,
        'entities_id'       => $eB,
        'itilcategories_id' => $CATEGORY,
        'locations_id'      => 0,
        'name'              => (string) $ticket->fields['name'],
    ],
    $open,
    $rules
);
is_same('and the matcher refuses one from another entity outright', $across, null);

section('Attaching');

check('the offered ticket attaches', Affected::attach($incidents_id, $t2));
check('a second click is not an error', Affected::attach($incidents_id, $t2));
is_same('and it is attached once', count_rows(Affected::TABLE, [
    'plugin_glpimajor_incidents_id' => $incidents_id,
    'tickets_id'                    => $t2,
]), 1);

is_same(
    'a ticket in another entity is refused even when the caller asks directly',
    Affected::attach($incidents_id, $t3),
    false
);
is_same('the incident\'s own ticket cannot attach to itself', Affected::attach($incidents_id, $t1), false);

$link = row('glpi_tickets_tickets', ['tickets_id_1' => $t2, 'tickets_id_2' => $t1]);
check('the core link exists', $link !== null);
is_same(
    'as SON_OF, with the affected ticket as the son',
    $link === null ? -1 : (int) $link['link'],
    Ticket_Ticket::SON_OF
);
is_same(
    'and never as DUPLICATE_WITH, which core would treat as an instruction',
    count_rows('glpi_tickets_tickets', [
        'OR' => [
            ['tickets_id_1' => $t2, 'tickets_id_2' => $t1],
            ['tickets_id_1' => $t1, 'tickets_id_2' => $t2],
        ],
        'link' => Ticket_Ticket::DUPLICATE_WITH,
    ]),
    0
);

$incident->getFromDB($incidents_id);
// One attached row plus the declaring ticket: 2.
is_same('the cached count keeps up, declaring ticket included', (int) $incident->fields['affected_count'], 2);
check('the attachment is attributable', (int) (row(Affected::TABLE, [
    'plugin_glpimajor_incidents_id' => $incidents_id,
    'tickets_id'                    => $t2,
])['users_id'] ?? 0) === (int) Session::getLoginUserID());
check('and it is in the audit trail', had_event($incidents_id, Events::ATTACHED));

// =============================================================== 3. comms

section('The update log, and its audiences');

$internal_text = 'DC01 is out of disk. Veeam restore running, ETA 40 minutes. Do not reboot it.';
$customer_text = 'We have found the cause and are working on it. '
    . 'We will post the next update within the hour.';

$u1 = Update::publish($incident, Update::INTERNAL, $internal_text);
check('an internal note is recorded', $u1 !== false);

// The id it hands back has to be the row's own. `DBmysql::insert()` returns
// *true*, so a caller reading its return as an id gets 1 — which is a real row
// belonging to somebody else.
check(
    'and the id it returns is that note\'s own row',
    (int) (row(Update::TABLE, ['id' => (int) $u1])['plugin_glpimajor_incidents_id'] ?? 0) === $incidents_id,
    'id ' . var_export($u1, true)
);

$incident->setState(Incident::IDENTIFIED);
$incident->getFromDB($incidents_id);

$u2 = Update::publish($incident, Update::CUSTOMER, $customer_text);
check('a customer update is recorded', $u2 !== false);

is_same('the log holds both', count(Update::forIncident($incidents_id)), 2);
is_same('one of them is internal', count(Update::forIncident($incidents_id, Update::INTERNAL)), 1);
is_same('one of them is customer-visible', count(Update::forIncident($incidents_id, Update::CUSTOMER)), 1);

$latest = Update::latestCustomer($incidents_id);
check('the latest customer update is the customer one', $latest !== null
    && (string) $latest['content'] === $customer_text);
is_same(
    'and it carries the state we believed at the time',
    (string) ($latest['state_at_time'] ?? ''),
    Incident::IDENTIFIED
);

$first = row(Update::TABLE, ['id' => (int) $u1]);
is_same(
    'the earlier one still says investigating, not what we know now',
    (string) ($first['state_at_time'] ?? ''),
    Incident::INVESTIGATING
);
is_same('an empty update is refused', Update::publish($incident, Update::CUSTOMER, "  \n "), false);
check('the state change is in the audit trail', had_event($incidents_id, Events::STATE));

// The feed's own copy of that transition (0.1.2) — typed columns, permanent,
// written by setState() alongside the prunable audit row.
$change = row(StateChange::TABLE, [
    'plugin_glpimajor_incidents_id' => $incidents_id,
    'state_to'                      => Incident::IDENTIFIED,
]);
check('the transition is recorded for the feed', $change !== null);
is_same('with where it came from', (string) ($change['state_from'] ?? ''), Incident::INVESTIGATING);
is_same('and who moved it', (int) ($change['users_id'] ?? 0), (int) Session::getLoginUserID());
is_same('and no outcome — only resolving snapshots one', $change['outcome'] ?? null, null);

check('a no-op state change records nothing', $incident->setState(Incident::IDENTIFIED));

// The public post-mortem can be *drafted* while the incident is open — half
// the story is best written while it is fresh — but publishing one is the
// gate: a "post-mortem" on a live outage declares it over in the very
// paragraph the timeline above it contradicts.
check('a post-mortem draft can be written while the incident is open',
    Postmortem::save($incident, 'Draft in progress.', false));
check('but publishing it is refused while the incident is open',
    Postmortem::publish($incident, 'Draft in progress.', false) !== null);
check('and nothing reached the published side',
    Postmortem::publishedFor($incidents_id) === null);
is_same(
    'still exactly one transition row',
    count_rows(StateChange::TABLE, ['plugin_glpimajor_incidents_id' => $incidents_id]),
    1
);

// The assembled feed: the declaration is synthesised from the incident row
// itself — no stored event, nothing to drift — so it is there for every
// incident, including ones declared before the statechanges table existed.
$feed = Feed::forIncident($incident);
is_same('the feed starts at the declaration', (string) ($feed[0]['kind'] ?? ''), Feed::DECLARED);
is_same(
    'and the declaration names the declaring ticket',
    (int) ($feed[0]['tickets_id'] ?? 0),
    (int) $incident->fields['tickets_id']
);
is_same(
    'the feed interleaves both updates and the transition',
    [
        count(array_filter($feed, static fn($e) => $e['kind'] === Feed::UPDATE)),
        count(array_filter($feed, static fn($e) => $e['kind'] === Feed::STATE)),
    ],
    [2, 1]
);

// ============================================================ 4. publishing

section('The published file');

$config_was = (string) ($DB->request([
    'FROM'  => 'glpi_configs',
    'WHERE' => ['context' => PLUGIN_GLPIMAJOR_CONFIG_CONTEXT, 'name' => 'status_enabled'],
    'LIMIT' => 1,
])->current()['value'] ?? '0');

$DB->update(
    'glpi_configs',
    ['value' => '1'],
    ['context' => PLUGIN_GLPIMAJOR_CONFIG_CONTEXT, 'name' => 'status_enabled']
);
check('publishing can be switched on', Settings::flag('status_enabled'));

$token = Page::mint($eA);
check('an address is 48 hex characters', Page::isTokenShaped($token), $token);

$file = Page::fileFor($token);
check('minting publishes a file named for the address', is_file($file), $file);

$html = is_file($file) ? (string) file_get_contents($file) : '';
$page_row = Page::forEntity($eA);
check('the page row records what was written',
    $page_row !== null && (int) $page_row['bytes'] === strlen($html) && (string) $page_row['last_error'] === '',
    (string) ($page_row['last_error'] ?? ''));

check('the customer update is on it', str_contains($html, 'We have found the cause'));
check('the internal note is not', !str_contains($html, 'Veeam') && !str_contains($html, 'DC01'));
check('the internal ticket title is not', !str_contains($html, 'file server unreachable'));
check('the customer-visible title is', str_contains($html, 'Shared drive unavailable'));
check('the address is not printed inside the page it addresses', !str_contains($html, $token));

// The same word list the pure renderer suite asserts, re-asserted against a
// document assembled from real rows: the projection happens in Publisher, and a
// column that leaked would never reach the pure test.
$vocabulary = ['ticket', 'entity', 'entities', 'requester', 'itil', 'glpi', 'assignee', 'technician'];
$leaked     = [];
foreach ($vocabulary as $word) {
    if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', strip_tags($html))) {
        $leaked[] = $word;
    }
}
is_same('the document uses none of GLPI\'s vocabulary', $leaked, []);

// Nothing the gatherer hands the renderer may be internal, either — the
// projection is asserted at its source rather than only in its output.
$gathered = Publisher::gather($eA, (array) $page_row);
$audiences_leaked = false;
foreach ($gathered['incidents'] as $one) {
    foreach ($one['updates'] as $one_update) {
        if (str_contains((string) $one_update['content'], 'Veeam')) {
            $audiences_leaked = true;
        }
    }
}
is_same('and the gatherer never even loads an internal one', $audiences_leaked, false);

$old_file = $file;
$token2   = Page::mint($eA);
check('regenerating gives a different address', $token2 !== $token);
check('and retires the old file at once, not at the next publish', !is_file($old_file));
check('while the new address serves a page', is_file(Page::fileFor($token2)));

check('revoking takes the page down', Page::revoke($eA));
check('and removes the file', !is_file(Page::fileFor($token2)));
$revoked = Page::forEntity($eA);
is_same(
    'leaving a row that says it was revoked rather than never made',
    $revoked === null ? 'gone' : (string) $revoked['token'],
    ''
);
is_same('a revoked page has no public address', Page::publicUrl((array) $revoked), '');

// Put it back for the resolution assertions, which republish.
$token3 = Page::mint($eA);
check('an address can be minted again', Page::isTokenShaped($token3));

// =========================================================== 4b. the seam

section('Maintenance windows — the seam another plugin calls');

$key = "window:$uniq";

$m_id = Maintenance::announce([
    'entities_id'  => $eA,
    'name'         => 'Overnight firewall upgrade',
    'content'      => 'Internet access will drop briefly, twice.',
    'date_start'   => date('Y-m-d H:i:s', time() + (2 * DAY_TIMESTAMP)),
    'date_end'     => date('Y-m-d H:i:s', time() + (2 * DAY_TIMESTAMP) + 7200),
    'state'        => Maintenance::SCHEDULED,
    'source'       => 'glpimajor-db-live',
    'external_key' => $key,
]);

check('a window can be announced', is_int($m_id) && $m_id > 0, Maintenance::lastError());
is_same(
    'and the id it returns is that window\'s own row — it is the caller\'s handle on it',
    (int) (row(Maintenance::getTable(), ['id' => (int) $m_id])['entities_id'] ?? -1),
    $eA
);
is_same('a window with no title is refused', Maintenance::announce([
    'entities_id' => $eA,
    'name'        => '   ',
]), false);

$m_again = Maintenance::announce([
    'entities_id'  => $eA,
    'name'         => 'Overnight firewall upgrade (rescheduled)',
    'date_start'   => date('Y-m-d H:i:s', time() + (3 * DAY_TIMESTAMP)),
    'date_end'     => date('Y-m-d H:i:s', time() + (3 * DAY_TIMESTAMP) + 7200),
    'source'       => 'glpimajor-db-live',
    'external_key' => $key,
]);
is_same('re-announcing the same key updates the same row', $m_again, $m_id);
is_same('rather than littering the customer\'s page', count_rows(Maintenance::getTable(), [
    'source'       => 'glpimajor-db-live',
    'external_key' => $key,
]), 1);

$with_window = (string) @file_get_contents(Page::fileFor($token3));
check('and the window reaches the published page',
    str_contains($with_window, 'Overnight firewall upgrade (rescheduled)'));

check('withdrawing it succeeds', Maintenance::withdraw('glpimajor-db-live', $key));
is_same(
    'the row survives, cancelled rather than vanished',
    (string) (row(Maintenance::getTable(), ['id' => (int) $m_id])['state'] ?? ''),
    Maintenance::CANCELLED
);
check('and it is off the page a customer planned around',
    !str_contains((string) @file_get_contents(Page::fileFor($token3)), 'Overnight firewall upgrade'));
is_same(
    'withdrawing something nobody announced is a refusal, not a crash',
    Maintenance::withdraw('glpimajor-db-live', 'no-such-key'),
    false
);

// ============================================================ 5. resolution

section('Resolution proposes and closes nothing');

// The two facts that must not change underneath an attached ticket.
$status_before = (int) (row('glpi_tickets', ['id' => $t2])['status'] ?? -1);
$assignees_before = count_rows('glpi_tickets_users', [
    'tickets_id' => $t2,
    'type'       => CommonITILActor::ASSIGN,
]);

check('resolution is refused with no outcome while an outcome is required',
    Settings::flag('pir_required') ? $incident->setState(Incident::RESOLVED, '  ') === false : true);
$incident->getFromDB($incidents_id);
check('and the incident is still open', Incident::isOpenState((string) $incident->fields['state']));

$outcome = 'A failed disk in the file server was replaced and the shared drive was brought '
    . 'back online. Nothing was lost.';

check('resolution with an outcome is accepted', $incident->setState(Incident::RESOLVED, $outcome));
$incident->getFromDB($incidents_id);
is_same('the incident is resolved', (string) $incident->fields['state'], Incident::RESOLVED);
check('with a resolution time', (string) ($incident->fields['date_resolved'] ?? '') !== '');
is_same('and no promise left to break', $incident->fields['next_update_at'], null);

// The resolve event carries the outcome as it was written at that moment —
// a snapshot on the transition row, immune to the column being edited later.
$resolve_change = row(StateChange::TABLE, [
    'plugin_glpimajor_incidents_id' => $incidents_id,
    'state_to'                      => Incident::RESOLVED,
]);
check('the resolve transition is recorded for the feed', $resolve_change !== null);
is_same('with the outcome snapshotted onto it', (string) ($resolve_change['outcome'] ?? ''), $outcome);

$feed_after = Feed::forIncident($incident);
$last = $feed_after[count($feed_after) - 1] ?? [];
check(
    'and the feed ends on it',
    ($last['kind'] ?? '') === Feed::STATE && ($last['to'] ?? '') === Incident::RESOLVED,
    json_encode($last)
);

$attached = row(Affected::TABLE, [
    'plugin_glpimajor_incidents_id' => $incidents_id,
    'tickets_id'                    => $t2,
]);

$solutions_id = (int) ($attached['itilsolutions_id'] ?? 0);
check('the attached ticket received a solution', $solutions_id > 0);
check('recorded against the attachment with a time', (string) ($attached['date_proposed'] ?? '') !== '');

$solution = row('glpi_itilsolutions', ['id' => $solutions_id]);
is_same(
    'and it is waiting for approval, not approved',
    (int) ($solution['status'] ?? -1),
    CommonITILValidation::WAITING
);
check('it speaks the customer-visible title, not the internal one',
    $solution !== null
    && str_contains((string) $solution['content'], 'Shared drive unavailable')
    && !str_contains((string) $solution['content'], 'file server unreachable'));

$ticket_after = new Ticket();
$ticket_after->getFromDB($t2);
$status_after = (int) $ticket_after->fields['status'];

is_same('the attached ticket\'s status did not move', $status_after, $status_before);
check('it is not solved', !in_array($status_after, $ticket_after->getSolvedStatusArray(), true));
check('it is not closed', !in_array($status_after, $ticket_after->getClosedStatusArray(), true));
is_same(
    'and nobody was assigned to it on the way past',
    count_rows('glpi_tickets_users', ['tickets_id' => $t2, 'type' => CommonITILActor::ASSIGN]),
    $assignees_before
);
is_same(
    'the commander in particular',
    count_rows('glpi_tickets_users', [
        'tickets_id' => $t2,
        'type'       => CommonITILActor::ASSIGN,
        'users_id'   => (int) $incident->fields['users_id_commander'],
    ]),
    0
);

check('the proposal is in the audit trail', had_event($incidents_id, Events::PROPOSED));
check('so is the resolution', had_event($incidents_id, Events::RESOLVED));

// A second resolution must not propose twice onto the same ticket.
$incident->setState(Incident::RESOLVED, $outcome);
is_same(
    'resolving again does not stack a second solution on it',
    count_rows('glpi_itilsolutions', ['itemtype' => 'Ticket', 'items_id' => $t2]),
    1
);

$resolved_html = (string) @file_get_contents(Page::fileFor($token3));
check('the resolved incident is on the page it was published to',
    str_contains($resolved_html, 'Shared drive unavailable'));
check('and the outcome does not carry the internal hostname',
    !str_contains($resolved_html, 'DC01'));

// ================================================================== 6. PIR

section('The review, its lock, and the key');

$pir = Pir::forIncident($incidents_id, $eA);
check('a review is created on first look', $pir !== null && (int) $pir['id'] > 0);

// The one that mattered most: created and returned in the same call, so an id
// read wrongly hands back the *first* review on the instance — another
// incident's, under this incident's heading.
is_same(
    'and it is this incident\'s review, not the first one ever written',
    (int) ($pir['plugin_glpimajor_incidents_id'] ?? 0),
    $incidents_id
);
is_same('found again on the second look', (int) (Pir::forIncident($incidents_id, $eA)['id'] ?? 0), (int) ($pir['id'] ?? -1));

$pirs_id = (int) ($pir['id'] ?? 0);

is_same(
    'completing an empty one is refused',
    Pir::complete($pirs_id),
    false
);

check('the review saves', Pir::save($pirs_id, [
    'what_happened' => 'A disk failed in the file server at 09:12. The array did not rebuild.',
    'impact'        => 'The whole office could not open shared files for two hours and ten minutes.',
    'root_cause'    => 'The second disk had been failed since March and nobody was told.',
]));
check('the save is in the audit trail', had_event($incidents_id, Events::PIR_SAVED));

$actions_id = PirAction::add($pirs_id, 'Alert on a degraded array, not only on a failed one',
    (int) Session::getLoginUserID(), date('Y-m-d', time() + (14 * DAY_TIMESTAMP)));
check('an action can be agreed', $actions_id !== false);
is_same('and it belongs to this review', count(PirAction::forPir($pirs_id)), 1);
is_same(
    'and the id it returns is that action\'s own row',
    (int) (row(PirAction::TABLE, ['id' => (int) $actions_id])['plugin_glpimajor_pirs_id'] ?? 0),
    $pirs_id
);

check('the review completes', Pir::complete($pirs_id));
$pir = Pir::byId($pirs_id);
is_same('it is marked complete', (string) $pir['status'], Pir::COMPLETE);
check('with a completer and a time', (int) $pir['users_id_complete'] > 0
    && (string) $pir['date_completed'] !== '');
check('the completion is in the audit trail', had_event($incidents_id, Events::PIR_DONE));

check('a complete review is locked', Pir::isLocked($pir));
check('and cannot be edited', !Pir::isEditable($pir));
is_same('an edit through the front door is refused', Pir::save($pirs_id, [
    'what_happened' => 'Something else entirely',
]), false);
is_same(
    'and the text is untouched',
    substr((string) Pir::byId($pirs_id)['what_happened'], 0, 20),
    'A disk failed in the'
);

check('the lock has a key', Pir::setUnlocked($pirs_id, true));
$pir = Pir::byId($pirs_id);
check('an unlocked review is editable again', Pir::isEditable($pir));
check('turning the key is recorded', had_event($incidents_id, Events::PIR_UNLOCK));

$unlock_event = row('glpi_plugin_glpimajor_events', [
    'plugin_glpimajor_incidents_id' => $incidents_id,
    'action'                        => Events::PIR_UNLOCK,
]);
is_same(
    'against the person who turned it',
    (int) ($unlock_event['users_id'] ?? 0),
    (int) Session::getLoginUserID()
);

check('an unlocked review saves', Pir::save($pirs_id, [
    'what_happened' => 'A disk failed in the file server at 09:12. The array did not rebuild, '
        . 'because the second disk had already been failed since March.',
    'impact'     => (string) $pir['impact'],
    'root_cause' => (string) $pir['root_cause'],
]));

check('completing it again re-arms the lock', Pir::complete($pirs_id));
$pir = Pir::byId($pirs_id);
is_same('the key is turned back', (int) $pir['is_unlocked'], 0);
check('and it is locked once more', Pir::isLocked($pir));

check('re-locking by hand is recorded too',
    Pir::setUnlocked($pirs_id, true)
    && Pir::setUnlocked($pirs_id, false)
    && had_event($incidents_id, Events::PIR_RELOCK));

// The assembled timeline is a derivation, and the thing it must not lose is
// what the customer was told and when.
$timeline = Pir::timeline($incidents_id);
$labels   = array_column($timeline, 'label');
check('the timeline is assembled, not typed', count($timeline) >= 5, implode(' | ', $labels));
check('it distinguishes what we said in public from what we said inside',
    in_array('Update to the customer', $labels, true) && in_array('Internal note', $labels, true),
    implode(' | ', $labels));
$ordered = $timeline;
usort($ordered, static fn(array $a, array $b): int => strcmp($a['at'], $b['at']));
is_same('and it is in order', array_column($ordered, 'at'), array_column($timeline, 'at'));

// The internal note was written before the customer update, and on a fast
// machine both land in the same second. The update log reads newest-first and
// PHP's sort is stable, so a merge that does not reverse it tells that second
// of the story backwards — which is the second a review is usually about.
$said = array_values(array_filter($timeline, static fn(array $r): bool => $r['kind'] === 'update'));
check(
    'two things said in the same second are still told in the order they were said',
    isset($said[0]) && str_starts_with((string) $said[0]['detail'], 'DC01 is out of disk'),
    (string) ($said[0]['detail'] ?? 'nothing')
);

// =========================================================== 6b. post-mortem

section('The public post-mortem: drafted freely, published only when it is over');

// The incident is resolved by now, so the gate is open. Blank text is still
// refused — an empty account on a customer's page is not a thing to publish.
check('publishing a blank post-mortem is refused',
    Postmortem::publish($incident, "  \n  ", false) !== null);
check('and the refusal changed nothing', Postmortem::publishedFor($incidents_id) === null);

$pm_text = 'On Friday morning the shared drive was unavailable for around two hours.'
    . "\n\n" . 'A failed disk in the storage array was replaced and the drive was brought '
    . 'back online. Nothing stored on it was lost.'
    . "\n\n" . 'We are adding monitoring so a degraded array is flagged before a second '
    . 'failure can take the drive down.';

// The AI-draft provenance flag, recorded by evidence: the save carries "this
// text originated as a model's draft" the way the composer carries the
// reviewed text back.
check('a save carrying draft provenance records it',
    Postmortem::save($incident, $pm_text, true));
is_same('ai_drafted is on the row',
    (int) (Postmortem::forIncident($incidents_id)['ai_drafted'] ?? -1), 1);
check('and it is sticky across a later hand edit',
    Postmortem::save($incident, $pm_text, false)
    && (int) (Postmortem::forIncident($incidents_id)['ai_drafted'] ?? -1) === 1);

check('publishing the resolved incident\'s post-mortem is accepted',
    Postmortem::publish($incident, $pm_text, false) === null);

$pm_row = Postmortem::publishedFor($incidents_id);
check('the published row exists', $pm_row !== null);
check('with a publish stamp and a publisher',
    $pm_row !== null
    && (string) ($pm_row['published_at'] ?? '') !== ''
    && (int) ($pm_row['users_id_publisher'] ?? 0) === (int) Session::getLoginUserID());
check('the publication is in the audit trail', had_event($incidents_id, Events::PM_PUBLISHED));

// Publishing republished the page synchronously; the account must lead the
// incident's entry — after the title, before the update timeline.
$pm_html  = (string) @file_get_contents(Page::fileFor($token3));
$pm_title = strpos($pm_html, 'Shared drive unavailable');
$pm_sect  = strpos($pm_html, '<section class="pm">');
$pm_log   = $pm_title !== false ? strpos($pm_html, '<ol class="log">', $pm_title) : false;
check('the published page carries the post-mortem', $pm_sect !== false);
check('it contains the published text, not the internal review\'s',
    str_contains($pm_html, 'Nothing stored on it was lost')
    && !str_contains($pm_html, 'failed since March'));
check(
    'and it renders FIRST in the incident\'s entry: title, post-mortem, then the timeline',
    $pm_title !== false && $pm_sect !== false && $pm_log !== false
    && $pm_title < $pm_sect && $pm_sect < $pm_log,
    sprintf('title@%s pm@%s log@%s', var_export($pm_title, true), var_export($pm_sect, true), var_export($pm_log, true))
);

// The gatherer's own contract, asserted at the seam: the postmortem key is
// present on the resolved incident, and shaped as the renderer documents.
$page_row_pm = Page::forEntity($eA);
$gathered_pm = Publisher::gather($eA, (array) $page_row_pm);
$seam        = null;
foreach ($gathered_pm['incidents'] as $one) {
    if ((string) $one['title'] === 'Shared drive unavailable') {
        $seam = $one['postmortem'] ?? null;
    }
}
check('the gatherer supplies the documented seam',
    is_array($seam)
    && (string) ($seam['content'] ?? '') === $pm_text
    && (int) ($seam['published_at'] ?? 0) > 0,
    json_encode($seam));

// The reopen case: a published post-mortem is *held off* the page while the
// incident is open again — the row stays published, and the account returns
// when it is resolved once more.
check('the incident can regress out of resolved', $incident->setState(Incident::MONITORING));
$gathered_open = Publisher::gather($eA, (array) Page::forEntity($eA));
$open_entry    = null;
foreach ($gathered_open['incidents'] as $one) {
    if ((string) $one['title'] === 'Shared drive unavailable') {
        $open_entry = $one;
    }
}
check('a reopened incident carries no post-mortem key',
    is_array($open_entry) && !array_key_exists('postmortem', $open_entry));
check('resolving again brings it back',
    $incident->setState(Incident::RESOLVED, $outcome)
    && Postmortem::publishedFor($incidents_id) !== null
    && str_contains((string) @file_get_contents(Page::fileFor($token3)), '<section class="pm">'));

// Retract: off the page at once, the text kept as a draft, the stamp gone.
check('retracting is accepted', Postmortem::retract($incident));
check('the retraction is in the audit trail', had_event($incidents_id, Events::PM_RETRACTED));
$pm_after = Postmortem::forIncident($incidents_id);
check('the text survives as a draft',
    $pm_after !== null
    && (int) $pm_after['is_published'] === 0
    && $pm_after['published_at'] === null
    && (string) $pm_after['content'] === $pm_text);
check('and the page no longer carries it',
    !str_contains((string) @file_get_contents(Page::fileFor($token3)), '<section class="pm">'));

// Publish again, so the purge sweep below has a published row to clean.
check('re-publishing after a retraction works',
    Postmortem::publish($incident, $pm_text, false) === null);

// ====================================================== 7. down the tree
//
// The property this whole section exists for: a recursive incident reaches
// every entity *underneath* the one it was declared in, and no other entity in
// the instance — not a sibling of the declaring entity, not a sibling of a
// child, not a parent. Everything below is asserted from both directions:
// what the covered offices can see, and what the unrelated firm cannot.

section('Recursion travels down the tree, and only down');

$firm = (int) $entity->add([
    'name'        => "glpimajor db-live firm $uniq",
    'entities_id' => 0,
]);

$manchester = $firm > 0 ? (int) $entity->add([
    'name'        => "glpimajor db-live firm $uniq — Manchester",
    'entities_id' => $firm,
]) : 0;

$london = $firm > 0 ? (int) $entity->add([
    'name'        => "glpimajor db-live firm $uniq — London",
    'entities_id' => $firm,
]) : 0;

// The one that must never see anything. A sibling of the declaring entity —
// another customer of the same MSP, at the same level in the tree.
$rival = (int) $entity->add([
    'name'        => "glpimajor db-live rival firm $uniq",
    'entities_id' => 0,
]);

if ($firm <= 0 || $manchester <= 0 || $london <= 0 || $rival <= 0) {
    fwrite(STDERR, "Could not build the entity tree; the recursion section cannot run.\n");
    exit(1);
}

$made['entities'] = array_merge($made['entities'], [$firm, $manchester, $london, $rival]);

// The tree cache was populated before these existed, in this process and in
// GLPI's own.
Tree::forget();
Session::changeActiveEntities($firm, true);

is_same('the offices are under the firm', Tree::descendants($firm), [$manchester, $london]);
is_same('and the firm is above them', in_array($firm, Tree::ancestors($manchester), true), true);
is_same('the rival firm is under neither', Tree::descendants($rival), []);
is_same(
    'and is in no office\'s ancestry — which is why a sibling is unreachable rather than filtered',
    in_array($rival, Tree::ancestors($manchester), true),
    false
);
is_same('the firm does not contain the rival', Tree::contains($firm, $rival), false);
is_same('nor does an office contain its own parent', Tree::contains($manchester, $firm), false);
is_same('an entity contains itself', Tree::contains($manchester, $manchester), true);

// One ticket per entity, so every surface has something real to be asserted on.
$mk_ticket = static function (string $name, int $in_entity) use ($ticket, $CATEGORY, &$made): int {
    $id = (int) $ticket->add([
        'name'              => $name,
        'content'           => 'Raised for the recursion assertions.',
        'entities_id'       => $in_entity,
        'itilcategories_id' => $CATEGORY,
        'status'            => Ticket::ASSIGNED,
    ]);
    $made['tickets'][] = $id;

    return $id;
};

$t_firm  = $mk_ticket("db-live $uniq: shared drive down at head office", $firm);
$t_man   = $mk_ticket("db-live $uniq: cannot open the shared drive", $manchester);
$t_lon   = $mk_ticket("db-live $uniq: cannot open the shared drive", $london);
$t_rival = $mk_ticket("db-live $uniq: cannot open the shared drive", $rival);

$ticket->getFromDB($t_firm);

$wide = Incident::declareFor($ticket, [
    'name'               => 'All offices: shared documents unavailable',
    'users_id_commander' => (int) Session::getLoginUserID(),
    'users_id_comms'     => (int) Session::getLoginUserID(),
    'is_recursive'       => 1,
]);

check('a covering incident can be declared', is_int($wide) && $wide > 0, var_export($wide, true));
if (!is_int($wide) || $wide <= 0) {
    fwrite(STDERR, "The covering declaration failed; the rest of the section has nothing to run against.\n");
    exit(1);
}

$made['incidents'][] = $wide;

$wide_mi = new Incident();
$wide_mi->getFromDB($wide);
is_same('and it records that it covers sub-entities', (int) $wide_mi->fields['is_recursive'], 1);
is_same('in the entity it was declared in', (int) $wide_mi->fields['entities_id'], $firm);

// -------------------------------------------------- what each entity can see

is_same('the firm sees its own incident', count(Incident::openFor($firm)), 1);
is_same('Manchester sees it too', count(Incident::openFor($manchester)), 1);
is_same('and London', count(Incident::openFor($london)), 1);
is_same('the rival firm sees nothing at all', count(Incident::openFor($rival)), 0);
is_same(
    'and Manchester\'s own entity has no incident of its own',
    count(Incident::openIn($manchester)),
    0
);

// GLPI's own search engine, which is what the incident list under Setup is.
// `maybeRecursive()` returning true is what makes it agree with everything
// else here; returning false would give a technician in a covered office a
// list that denies the outage their tickets are being attached to.
Session::changeActiveEntities($manchester, false);
$listed = Search::getDatas(Incident::class, ['reset' => 'reset', 'start' => 0]);
is_same(
    'the incident list shows a covered office the incident declared above it',
    (int) ($listed['data']['totalcount'] ?? -1),
    1
);

Session::changeActiveEntities($rival, false);
$listed_rival = Search::getDatas(Incident::class, ['reset' => 'reset', 'start' => 0]);
is_same(
    'and shows the unrelated firm nothing at all',
    (int) ($listed_rival['data']['totalcount'] ?? -1),
    0
);

Session::changeActiveEntities($firm, true);

// ------------------------------------------------------- the duplicate offer

$offer_for = static function (int $tickets_id, int $in_entity) use ($ticket): ?array {
    $ticket->getFromDB($tickets_id);

    return Matcher::best(
        [
            'id'                => $tickets_id,
            'entities_id'       => $in_entity,
            'ancestors'         => Tree::ancestors($in_entity),
            'itilcategories_id' => (int) $ticket->fields['itilcategories_id'],
            'locations_id'      => 0,
            'name'              => (string) $ticket->fields['name'],
        ],
        Incident::openFor($in_entity),
        Settings::matchRules()
    );
};

$man_offer = $offer_for($t_man, $manchester);
check(
    'a ticket in a covered office is offered the incident declared above it',
    $man_offer !== null && (int) $man_offer['incident']['id'] === $wide,
    $man_offer === null ? 'no match' : Matcher::explain($man_offer['reasons'])
);
is_same('a ticket in the rival firm is offered nothing', $offer_for($t_rival, $rival), null);

// Even handed the incident directly — the matcher's own boundary, not the
// query's. This is the assertion that would fail if somebody "simplified"
// Matcher::reaches() into an entity list.
is_same(
    'and the matcher refuses it even when the rival\'s ticket is handed the incident outright',
    Matcher::best(
        [
            'id'          => $t_rival,
            'entities_id' => $rival,
            'ancestors'   => Tree::ancestors($rival),
            'itilcategories_id' => $CATEGORY,
            'locations_id' => 0,
            'name'        => 'cannot open the shared drive',
        ],
        Incident::openFor($firm),
        Settings::matchRules()
    ),
    null
);

// ------------------------------------------------------------- attaching

check('a covered office\'s ticket attaches', Affected::attach($wide, $t_man));
is_same(
    'the rival firm\'s ticket is refused even when the caller asks directly',
    Affected::attach($wide, $t_rival),
    false
);
check('the incident reaches down to Manchester', Affected::inScope($wide_mi, $manchester));
check('and to London', Affected::inScope($wide_mi, $london));
is_same('but not sideways to the rival firm', Affected::inScope($wide_mi, $rival), false);
is_same('and not upwards to the root', Affected::inScope($wide_mi, 0), false);

// The MI banner on a ticket in a descendant entity is drawn from this: the
// attachment is what the banner reads, and it is now in another entity from
// the incident it belongs to.
$branch_attachment = Affected::incidentFor($t_man);
is_same(
    'a ticket in a sub-entity now carries the incident, which is what its banner reads',
    (int) ($branch_attachment['plugin_glpimajor_incidents_id'] ?? 0),
    $wide
);
is_same(
    'recorded against its own entity, not the incident\'s',
    (int) ($branch_attachment['entities_id'] ?? 0),
    $manchester
);

// ------------------------------------------------------------ published pages

Update::publish($wide_mi, Update::INTERNAL,
    'Array on FS02 degraded since 04:00. Do not fail the second disk.');
Update::publish($wide_mi, Update::CUSTOMER,
    'Documents on the shared drive cannot be opened from any of our offices. We are working on it.');

$tok_firm  = Page::mint($firm);
$tok_man   = Page::mint($manchester);
$tok_lon   = Page::mint($london);
$tok_rival = Page::mint($rival);

$read = static fn(string $token): string => (string) @file_get_contents(Page::fileFor($token));

check('the firm\'s own page carries it', str_contains($read($tok_firm), 'All offices'));
check('Manchester\'s page carries it', str_contains($read($tok_man), 'All offices'));
check('London\'s page carries it', str_contains($read($tok_lon), 'All offices'));
check(
    'and the rival firm\'s page does not — the assertion this whole feature turns on',
    !str_contains($read($tok_rival), 'All offices'),
    substr(strip_tags($read($tok_rival)), 0, 160)
);

check(
    'the customer update travels with it',
    str_contains($read($tok_man), 'cannot be opened from any of our offices')
);
check(
    'the internal note does not',
    !str_contains($read($tok_man), 'FS02') && !str_contains($read($tok_man), 'Do not fail')
);

// ------------------------------------------- a non-recursive one stays put

$ticket->getFromDB($t_lon);
$narrow = Incident::declareFor($ticket, [
    'name'               => 'London only: telephones unavailable',
    'users_id_commander' => (int) Session::getLoginUserID(),
    'users_id_comms'     => (int) Session::getLoginUserID(),
]);

check('an ordinary incident can be declared in an office', is_int($narrow) && $narrow > 0);
$made['incidents'][] = (int) $narrow;

$narrow_mi = new Incident();
$narrow_mi->getFromDB((int) $narrow);
is_same('it is not marked as covering anything', (int) $narrow_mi->fields['is_recursive'], 0);

Update::publish($narrow_mi, Update::CUSTOMER, 'Calls to the London office are not connecting.');

check('London\'s page carries its own incident', str_contains($read($tok_lon), 'London only'));
check(
    'the firm above it does not — recursion never travels upwards',
    !str_contains($read($tok_firm), 'London only')
);
check(
    'and Manchester does not — nor sideways between offices',
    !str_contains($read($tok_man), 'London only')
);

// And a *non*-recursive incident declared at the firm must stay off the
// offices' pages, which is the setting-dependent half of the same rule.
$ticket->getFromDB($t_firm);
$DB->update(Incident::getTable(), ['is_recursive' => 0], ['id' => $wide]);
Tree::forget();
Publisher::publish($manchester);
check(
    'clearing the coverage takes it off a sub-entity\'s page',
    !str_contains($read($tok_man), 'All offices'),
    substr(strip_tags($read($tok_man)), 0, 160)
);
check(
    'while the entity it was declared in keeps it',
    str_contains($read($tok_firm), 'All offices')
);

$DB->update(Incident::getTable(), ['is_recursive' => 1], ['id' => $wide]);
$wide_mi->getFromDB($wide);

// ------------------------------------------------------------ the fan-out

$republished = Publisher::onChangeSubtree($firm);
is_same(
    'a covering change rewrites every published page in the subtree',
    $republished,
    3
);
check('including the sub-entities\' own', str_contains($read($tok_man), 'All offices'));
check(
    'and not the rival\'s, which is outside the subtree and was never asked',
    !str_contains($read($tok_rival), 'All offices')
);

// ------------------------------------------------- maintenance windows too

$m_wide = Maintenance::announce([
    'entities_id'  => $firm,
    'is_recursive' => 1,
    'name'         => 'Overnight replacement of the document server',
    'content'      => 'The shared drive will be unavailable overnight at every office.',
    'date_start'   => date('Y-m-d H:i:s', time() + (2 * DAY_TIMESTAMP)),
    'date_end'     => date('Y-m-d H:i:s', time() + (2 * DAY_TIMESTAMP) + 10800),
]);
check('a window can cover sub-entities', is_int($m_wide) && $m_wide > 0, Maintenance::lastError());

check('and it reaches an office\'s page', str_contains($read($tok_man), 'Overnight replacement of the document server'));
check(
    'and stops at the subtree',
    !str_contains($read($tok_rival), 'Overnight replacement of the document server')
);

$m_narrow = Maintenance::announce([
    'entities_id' => $firm,
    'name'        => 'Head office lift inspection',
    'date_start'  => date('Y-m-d H:i:s', time() + (3 * DAY_TIMESTAMP)),
    'date_end'    => date('Y-m-d H:i:s', time() + (3 * DAY_TIMESTAMP) + 3600),
]);
check('a window with no coverage flag is announced', is_int($m_narrow) && $m_narrow > 0);
check(
    'and a caller that never heard of coverage gets what it always meant',
    str_contains($read($tok_firm), 'Head office lift inspection')
        && !str_contains($read($tok_man), 'Head office lift inspection')
);

// ------------------------------------------------------------- the portal
//
// The data function, asked directly. What a browser draws is asserted by the
// browser check; this is the scoping, which is the part that must be true
// whatever anybody draws.

Portal::forget($firm);
Portal::forget($manchester);
Portal::forget($rival);

$portal_man = Portal::state($manchester);
check('the portal offers a covered office its own address', $portal_man['url'] !== '');
is_same(
    'and names the incident declared above it, in the customer\'s words',
    array_column($portal_man['incidents'], 'title'),
    ['All offices: shared documents unavailable']
);
is_same(
    'but not with a window two days out — the page is where a customer plans, '
        . 'the banner is where they are interrupted',
    array_column($portal_man['maintenance'], 'title'),
    []
);
check('and something to interrupt somebody about', Portal::isActive($portal_man));

// One starting tonight, which is what the horizon is for.
$m_tonight = Maintenance::announce([
    'entities_id'  => $firm,
    'is_recursive' => 1,
    'name'         => 'Tonight: the shared drive will be offline for an hour',
    'date_start'   => date('Y-m-d H:i:s', time() + (4 * HOUR_TIMESTAMP)),
    'date_end'     => date('Y-m-d H:i:s', time() + (5 * HOUR_TIMESTAMP)),
]);
check('a window inside the day is announced', is_int($m_tonight) && $m_tonight > 0, Maintenance::lastError());

Portal::forget($manchester);
$portal_tonight = Portal::state($manchester);
is_same(
    'and reaches the covered office\'s banner',
    array_column($portal_tonight['maintenance'], 'title'),
    ['Tonight: the shared drive will be offline for an hour']
);

Portal::forget($rival);

$portal_rival = Portal::state($rival);
is_same('the rival firm is told about no incident whatsoever', $portal_rival['incidents'], []);
is_same('and no window of somebody else\'s', $portal_rival['maintenance'], []);
is_same('so nothing interrupts them', Portal::isActive($portal_rival), false);
check('though they still have their own address', $portal_rival['url'] !== '');

$portal_lon = Portal::state($london);
is_same(
    'London sees the covering incident and its own, and nothing of Manchester\'s',
    count($portal_lon['incidents']),
    2
);

// An entity with no page at all offers nothing, banner or link — a link to a
// page that does not exist is worse than no link.
Portal::forget($rival);
Page::revoke($rival);
$portal_gone = Portal::state($rival);
is_same('a revoked address leaves nothing to link to', $portal_gone['url'], '');
is_same('and no path for the menu either', $portal_gone['path'], '');

// The banner must never be drawn for a technician: they have the incident
// itself, and a softer copy of it on their home page is noise.
is_same(
    'the portal answers nothing at all in the central interface',
    Portal::currentEntity(),
    null
);

// ------------------------------------------------------------------ verdict

section('Verdict');

echo "\n" . ($failures === []
    ? "\033[32mall $passes checks passed\033[0m\n"
    : "\033[31m" . count($failures) . " of " . ($passes + count($failures)) . " failed\033[0m: "
      . implode('; ', $failures) . "\n");

echo "\nCleaning up.\n";

exit($failures === [] ? 0 : 1);
