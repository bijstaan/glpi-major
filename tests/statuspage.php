<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The status-page renderer, and the token's whereabouts.
 *
 * This is the one piece of the plugin whose output a stranger reads, so the
 * assertions are about the two ways it can betray the business: by leaking a
 * word from the inside of it, and by leaking the address of the page itself.
 *
 * The renderer is pure — an array in, a string out — which is what makes any of
 * this testable without a database. If a future change makes it reach for one,
 * this file stops loading, which is the intended alarm.
 *
 * Usage, inside the GLPI container:
 *   php /var/www/glpi/plugins/glpimajor/tests/statuspage.php
 */

require __DIR__ . '/stubs.php';
require __DIR__ . '/../src/Renderer.php';
require __DIR__ . '/../src/Page.php';

use GlpiPlugin\Glpimajor\Incident;
use GlpiPlugin\Glpimajor\Maintenance;
use GlpiPlugin\Glpimajor\Page;
use GlpiPlugin\Glpimajor\Renderer;

const NOW = 1_700_000_000;

/** A full fixture: two open incidents with history, one resolved, two windows. */
function fixture(array $overrides = []): array
{
    return $overrides + [
        'title'        => 'Northwind IT — Service status',
        'brand'        => [
            'name'          => 'Northwind IT',
            'logo'          => 'data:image/png;base64,iVBORw0KGgo=',
            'support_email' => 'help@northwind.example',
            'support_phone' => '+44 20 7946 0000',
            'note'          => 'Our service desk is open 08:00–18:00.',
        ],
        'generated_at' => NOW,
        'timezone'     => 'Europe/London',
        'history_days' => 30,
        'incidents'    => [
            [
                'title'       => 'File sharing is unavailable',
                'state'       => Incident::IDENTIFIED,
                'started_at'  => NOW - 7200,
                'resolved_at' => 0,
                'updates'     => [
                    [
                        'at'      => NOW - 900,
                        'state'   => Incident::IDENTIFIED,
                        'content' => "We have found the cause and are working on a fix.\n\n"
                            . 'We will update again within the hour.',
                    ],
                    [
                        'at'      => NOW - 7000,
                        'state'   => Incident::INVESTIGATING,
                        'content' => 'We are investigating reports that shared files cannot be opened.',
                    ],
                ],
            ],
            [
                'title'       => 'Telephones are ringing engaged',
                'state'       => Incident::MONITORING,
                'started_at'  => NOW - 20000,
                'resolved_at' => 0,
                'updates'     => [
                    [
                        'at'      => NOW - 600,
                        'state'   => Incident::MONITORING,
                        'content' => 'Calls are connecting again. We are watching it.',
                    ],
                ],
            ],
            [
                'title'       => 'Email delivery was delayed',
                'state'       => Incident::RESOLVED,
                'started_at'  => NOW - 200000,
                'resolved_at' => NOW - 190000,
                'updates'     => [
                    [
                        'at'      => NOW - 190000,
                        'state'   => Incident::RESOLVED,
                        'content' => 'All delayed messages have been delivered.',
                    ],
                ],
                'postmortem'  => [
                    'content'      => "A mail store filled faster than it drained and messages waited in it.\n\n"
                        . 'We now watch the backlog, not only the servers.',
                    'published_at' => NOW - 100000,
                ],
            ],
        ],
        'maintenance' => [
            [
                'title'   => 'Overnight firewall upgrade',
                'content' => 'Internet access will drop briefly, twice.',
                'start'   => NOW + 86400,
                'end'     => NOW + 90000,
                'state'   => Maintenance::SCHEDULED,
            ],
            [
                'title'   => 'Backup server replacement',
                'content' => 'No visible effect is expected.',
                'start'   => NOW - 3600,
                'end'     => NOW + 3600,
                'state'   => Maintenance::IN_PROGRESS,
            ],
        ],
    ];
}

$html = Renderer::document(fixture());

section('It is a complete HTML document');

check('it begins with a doctype', str_starts_with($html, '<!DOCTYPE html>'));
check('the language is declared', str_contains($html, '<html lang="en">'));
check('there is a head', str_contains($html, '<head>') && str_contains($html, '</head>'));
check('there is a title element', (bool) preg_match('#<title>[^<]+</title>#', $html));
check('there is a body', str_contains($html, '<body>') && str_contains($html, '</body>'));
check('the document is closed', str_ends_with(trim($html), '</html>'));
check('a charset is declared', str_contains($html, '<meta charset="utf-8">'));
check('it is responsive', str_contains($html, 'name="viewport"'));
check('it asks not to be indexed', str_contains($html, 'name="robots"'));

foreach (['article', 'section', 'header', 'footer', 'ol', 'li', 'p', 'style', 'details', 'summary'] as $tag) {
    $open  = preg_match_all('#<' . $tag . '[\s>]#', $html);
    $close = preg_match_all('#</' . $tag . '>#', $html);
    check(
        "every <$tag> is closed",
        $open === $close,
        "$open opened, $close closed"
    );
}

section('It is self-contained');

check(
    'no external stylesheet',
    !str_contains($html, '<link'),
    'the whole stylesheet is inlined'
);
check('no script of any kind', !str_contains($html, '<script'));
check(
    'no webfont or other remote asset',
    !preg_match('#(src|href)\s*=\s*["\']https?://#i', $html),
    'a page loaded during an outage makes no request it does not have to'
);
check(
    'the logo travels inside the document',
    str_contains($html, 'src="data:image/png;base64,')
);
check('a stylesheet is present', str_contains($html, '<style>'));

section('It contains no vocabulary from inside the business');

// Word-boundary rather than substring: "identified" contains no banned word,
// and a substring check would fail on the CSS as often as on the prose.
$forbidden = [
    'ticket', 'tickets', 'glpi', 'entity', 'entities', 'requester', 'requesters',
    'followup', 'followups', 'itil', 'technician', 'technicians', 'itemtype',
    'helpdesk', 'sla', 'assignee', 'queue', 'commander',
];

foreach ($forbidden as $word) {
    check(
        "the word \"$word\" appears nowhere",
        preg_match('/\b' . preg_quote($word, '/') . '\b/i', $html) === 0
    );
}

check(
    'it says what a customer would say',
    str_contains($html, 'Current issues') && str_contains($html, 'Planned maintenance')
);

section('The customer sees the whole story');

check('the branding is present', str_contains($html, 'Northwind IT'));
check('every open incident is shown', str_contains($html, 'File sharing is unavailable')
    && str_contains($html, 'Telephones are ringing engaged'));
check('the recently resolved one is shown', str_contains($html, 'Email delivery was delayed'));
check('the update history is present, not just the latest', str_contains($html, 'We are investigating reports')
    && str_contains($html, 'We have found the cause'));
check('each update carries the state it was written under', str_contains($html, 'Investigating')
    && str_contains($html, 'Identified') && str_contains($html, 'Monitoring'));
check('maintenance windows are shown', str_contains($html, 'Overnight firewall upgrade')
    && str_contains($html, 'Backup server replacement'));
check('there is a last-updated stamp', str_contains($html, 'Last updated'));
check(
    'timestamps are machine-readable as well as legible',
    (bool) preg_match('#<time datetime="\d{4}-\d{2}-\d{2}T#', $html)
);
// Found on a real page rather than by reading: the card's meta line escaped
// when()'s output a second time, so every incident on the page said
// `Started <time datetime="...">22 Aug 2026, 12:23</time>` in so many words.
// Both halves are asserted — the tag that should be there, and the escaped tag
// that should not be anywhere.
check(
    'and none of them is printed as its own markup',
    !str_contains($html, '&lt;time') && !str_contains($html, '&amp;middot;'),
    'a stamp was escaped after being built'
);
check(
    'an incident says when it started, as an element',
    (bool) preg_match('#<p class="meta text-secondary">Started <time #', $html)
);
check(
    'the "last updated" stamp names its timezone',
    (bool) preg_match('#Last updated <time[^>]*>[^<]*\((GMT|BST|UTC|[A-Z]{2,5})\)</time>#', $html),
    'a customer elsewhere cannot act on a bare "14:20"'
);
check('the support contact is offered', str_contains($html, 'help@northwind.example'));
check('the footer note survives', str_contains($html, 'service desk is open'));

section('The summary line answers the question people came for');

check(
    'with issues open it says so, in words',
    str_contains($html, 'We are working on two service issues.'),
    'not "2 open incidents", which is a database summary'
);

$calm = Renderer::document(fixture(['incidents' => []]));
check(
    'with nothing open it says everything is fine',
    str_contains($calm, 'All services are operating normally.')
);
check(
    'the calm page still lists planned maintenance',
    str_contains($calm, 'Overnight firewall upgrade')
);
check(
    'the calm page mentions maintenance in progress at the top',
    str_contains($calm, 'Planned maintenance is in progress')
);

$one = Renderer::document(fixture([
    'incidents' => [[
        'title'      => 'One thing',
        'state'      => Incident::INVESTIGATING,
        'started_at' => NOW - 60,
        'updates'    => [['at' => NOW, 'state' => Incident::INVESTIGATING, 'content' => 'Looking.']],
    ]],
]));
check('one issue reads as one', str_contains($one, 'We are working on one service issue.'));

section('The page never carries its own address');

$token = str_repeat('a1b2c3d4', 6);
check('the token is 48 characters', strlen($token) === 48);
check('the shape check accepts it', Page::isTokenShaped($token));
check(
    'a rendered page cannot contain the token',
    !str_contains(Renderer::document(fixture()), $token),
    'the renderer is never given it — the filename is the secret'
);
check(
    'document() takes no token parameter at all',
    (new ReflectionMethod(Renderer::class, 'document'))->getNumberOfParameters() === 1,
    'structural, not a promise'
);

section('Token shape is the whole of the public endpoint\'s validation');

check('too short is refused', !Page::isTokenShaped(str_repeat('a', 47)));
check('too long is refused', !Page::isTokenShaped(str_repeat('a', 49)));
check('uppercase is refused', !Page::isTokenShaped(strtoupper($token)));
check('non-hex is refused', !Page::isTokenShaped(str_repeat('z', 48)));
check('empty is refused', !Page::isTokenShaped(''));
check(
    'path traversal is refused',
    !Page::isTokenShaped('../../../../etc/passwd')
        && !Page::isTokenShaped(substr($token, 0, 44) . '/../')
);
check('a null byte is refused', !Page::isTokenShaped($token . "\0"));
check(
    'a newline cannot smuggle a valid token past an unanchored check',
    !Page::isTokenShaped($token . "\n"),
    'PHP $ matches before a trailing newline; the pattern must not rely on it'
);

check(
    'the token is the filename, so it lives in the path',
    Page::fileFor($token) === GLPI_PLUGIN_DOC_DIR . '/glpimajor/status/' . $token . '.html'
);

// resolveFile() is the second guard under the shape check, and it is the one
// front/status.php actually opens with. The shape check makes traversal
// impossible on its own; this asserts the containment holds anyway, including
// against the one thing a regular expression over the *token* cannot see — a
// file inside the directory that points somewhere else.
$doc_dir = Page::dir();
if (@mkdir($doc_dir, 0o755, true) || is_dir($doc_dir)) {
    $real  = $doc_dir . '/' . $token . '.html';
    $wrote = @file_put_contents($real, '<!DOCTYPE html><html></html>') !== false;

    check('a published token resolves to its file', $wrote && Page::resolveFile($token) === realpath($real));
    check('a token of the wrong shape resolves to nothing', Page::resolveFile('../../../../etc/passwd') === null);
    check('a well-shaped token with no file resolves to nothing', Page::resolveFile(str_repeat('b', 48)) === null);

    // A symlink named like a token, pointing out of the directory: the shape
    // check passes it, is_file() would have passed it, and containment is the
    // only thing that says no.
    $escapee = str_repeat('c', 48);
    $outside = sys_get_temp_dir() . '/glpimajor-test-outside.html';
    @file_put_contents($outside, 'not a status page');
    $linked = @symlink($outside, $doc_dir . '/' . $escapee . '.html');

    check(
        'a token symlinked out of the directory resolves to nothing',
        !$linked || Page::resolveFile($escapee) === null,
        $linked ? 'containment refuses it' : 'symlinks unavailable here; check skipped'
    );

    @unlink($doc_dir . '/' . $escapee . '.html');
    @unlink($outside);
    @unlink($real);
} else {
    check('resolveFile containment', true, 'no writable doc dir here; check skipped');
}

section('Malformed input still yields a page');

$empty = Renderer::document([]);
check('an entirely empty payload renders', str_starts_with($empty, '<!DOCTYPE html>')
    && str_ends_with(trim($empty), '</html>'));
check('and falls back to a usable title', str_contains($empty, '<title>Service status</title>'));

$broken = Renderer::document(fixture([
    'timezone'  => 'Middle/Earth',
    'incidents' => [[
        'title'   => null,
        'state'   => 'nonsense',
        'updates' => [['at' => 0, 'content' => '']],
    ]],
]));
check('an unusable timezone does not blank the page', str_contains($broken, '<time datetime='));
check('an unknown state falls back rather than printing itself', !str_contains($broken, 'nonsense'));
check('a missing title falls back', str_contains($broken, 'Service issue'));
check(
    'an incident with no update yet says so',
    str_contains(
        Renderer::document(fixture([
            'incidents' => [[
                'title'      => 'Something is wrong',
                'state'      => Incident::INVESTIGATING,
                'started_at' => NOW,
                'updates'    => [],
            ]],
        ])),
        'will post an update shortly'
    ),
    'rather than an empty box that reads as a broken page'
);

section('Hostile content is escaped, not rendered');

$nasty = Renderer::document(fixture([
    'brand'     => ['name' => '<script>alert(1)</script>', 'logo' => '', 'note' => ''],
    'title'     => 'Status " onload="alert(1)',
    'incidents' => [[
        'title'      => '<img src=x onerror=alert(1)>',
        'state'      => Incident::INVESTIGATING,
        'started_at' => NOW,
        'updates'    => [[
            'at'      => NOW,
            'state'   => Incident::INVESTIGATING,
            'content' => "A <b>bold</b> claim & an ampersand\nand a newline",
        ]],
    ]],
    'maintenance' => [],
]));

check('no script tag survives', !str_contains($nasty, '<script>'));
check('no img tag survives', !str_contains($nasty, '<img src=x'));
check('the escaped form is present instead', str_contains($nasty, '&lt;script&gt;'));
check(
    'an attribute cannot be broken out of',
    !str_contains($nasty, 'onload="alert(1)"'),
    'the title lands in <title> and in prose, both escaped'
);
check('markup inside an update is shown as text', str_contains($nasty, '&lt;b&gt;bold&lt;/b&gt;'));
check('ampersands are encoded once', str_contains($nasty, 'an ampersand'));
check(
    'a single newline inside a paragraph becomes a break',
    str_contains($nasty, 'and a newline') && str_contains($nasty, '<br>')
);

$paragraphs = Renderer::document(fixture([
    'incidents' => [[
        'title'      => 'Paragraphs',
        'state'      => Incident::INVESTIGATING,
        'started_at' => NOW,
        'updates'    => [['at' => NOW, 'state' => Incident::INVESTIGATING,
            'content' => "First para.\n\nSecond para."]],
    ]],
    'maintenance' => [],
]));
check(
    'a blank line starts a new paragraph',
    str_contains($paragraphs, '<p>First para.</p><p>Second para.</p>')
);

section('The present is expanded; the past is one line each');

// The collapsing must be structural — a <details> element the browser closes
// by default — because the published file is static and must behave with no
// script at all. These assertions read the markup the way a scriptless
// browser would.
preg_match_all('#<details.*?</details>#s', $html, $folds);
$folds = $folds[0];
$fold  = $folds[0] ?? '';

check('exactly the resolved incident is a disclosure', count($folds) === 1);
check('the resolved incident is the collapsed one', str_contains($fold, 'Email delivery was delayed'));
check(
    'no open incident is collapsed',
    !str_contains(implode('', $folds), 'File sharing is unavailable')
        && !str_contains(implode('', $folds), 'Telephones are ringing engaged'),
    'an outage in progress is readable without interaction'
);
check(
    'open incidents render as plain expanded cards',
    preg_match_all('#<article class="card card-open">#', $html) === 2
);
check(
    'the summary line is the title',
    (bool) preg_match('#<summary class="card-header past-sum">\s*<h3 class="card-title">Email delivery was delayed</h3>#', $fold)
);
check(
    'the summary carries the final state',
    (bool) preg_match('#<summary.*?bg-success-lt.*?Resolved.*?</summary>#s', $fold)
);
check(
    'and when it was resolved, as a <time> element',
    (bool) preg_match('#<span class="past-at text-secondary"><time #', $fold)
);
check(
    'the full record is inside the fold, not gone',
    str_contains($fold, 'All delayed messages have been delivered.')
        && str_contains($fold, '<ol class="log list-group list-group-flush">')
);
check(
    'no <details> is forced open',
    !str_contains($html, '<details open') && !preg_match('#<details[^>]*\sopen#', $html),
    'the past starts collapsed; the present is not a <details> at all'
);
check(
    'no disclosure is left unexplained',
    str_contains($html, 'Select one for the full record'),
    'the section says the lines expand'
);

section('A published post-mortem leads the record');

check('the post-mortem text is present', str_contains($fold, 'A mail store filled faster than it drained'));
check('it is titled', str_contains($fold, '<h4 class="card-title">Post-mortem</h4>'));
check(
    'it carries its publication stamp',
    (bool) preg_match('#<p class="pm-at text-secondary">Published <time #', $fold)
);
check(
    'it comes before the update timeline',
    strpos($fold, '<section class="pm card card-sm">') !== false
        && strpos($fold, '<ol class="log list-group list-group-flush">') !== false
        && strpos($fold, '<section class="pm card card-sm">') < strpos($fold, '<ol class="log list-group list-group-flush">'),
    'the finished account beats the play-by-play'
);
check(
    'its paragraphs are prose, like an update\'s',
    str_contains($fold, '<p>We now watch the backlog, not only the servers.</p>')
);

$withpm = Renderer::document(fixture([
    'incidents' => [[
        'title'      => 'Still open, already explained',
        'state'      => Incident::MONITORING,
        'started_at' => NOW - 5000,
        'updates'    => [['at' => NOW - 100, 'state' => Incident::MONITORING, 'content' => 'Watching.']],
        'postmortem' => ['content' => 'An early account of the cause.', 'published_at' => NOW - 50],
    ]],
    'maintenance' => [],
]));
check(
    'an open incident can carry one too, first',
    strpos($withpm, '<section class="pm card card-sm">') !== false
        && strpos($withpm, '<section class="pm card card-sm">') < strpos($withpm, '<ol class="log list-group list-group-flush">')
);

$without = Renderer::document(fixture([
    'incidents' => [[
        'title'       => 'No account yet',
        'state'       => Incident::RESOLVED,
        'started_at'  => NOW - 2000,
        'resolved_at' => NOW - 1000,
        'updates'     => [['at' => NOW - 1000, 'state' => Incident::RESOLVED, 'content' => 'Fixed.']],
    ]],
    'maintenance' => [],
]));
check(
    'no post-mortem, no heading and no box',
    !str_contains($without, 'Post-mortem') && !str_contains($without, 'class="pm card')
);

$blankpm = Renderer::document(fixture([
    'incidents' => [[
        'title'       => 'Blank account',
        'state'       => Incident::RESOLVED,
        'started_at'  => NOW - 2000,
        'resolved_at' => NOW - 1000,
        'updates'     => [['at' => NOW - 1000, 'state' => Incident::RESOLVED, 'content' => 'Fixed.']],
        'postmortem'  => ['content' => "  \n  ", 'published_at' => NOW - 500],
    ]],
    'maintenance' => [],
]));
check('a blank post-mortem renders nothing', !str_contains($blankpm, 'Post-mortem'));

$malformedpm = Renderer::document(fixture([
    'incidents' => [[
        'title'       => 'Wrong shape',
        'state'       => Incident::RESOLVED,
        'started_at'  => NOW - 2000,
        'resolved_at' => NOW - 1000,
        'updates'     => [['at' => NOW - 1000, 'state' => Incident::RESOLVED, 'content' => 'Fixed.']],
        'postmortem'  => 'a string where the seam wants an array',
    ]],
    'maintenance' => [],
]));
check('a malformed post-mortem renders nothing', !str_contains($malformedpm, 'Post-mortem'));

$hostilepm = Renderer::document(fixture([
    'incidents' => [[
        'title'       => 'Hostile account',
        'state'       => Incident::RESOLVED,
        'started_at'  => NOW - 2000,
        'resolved_at' => NOW - 1000,
        'updates'     => [['at' => NOW - 1000, 'state' => Incident::RESOLVED, 'content' => 'Fixed.']],
        'postmortem'  => ['content' => '<script>alert(1)</script>', 'published_at' => NOW - 500],
    ]],
    'maintenance' => [],
]));
check(
    'a hostile post-mortem is escaped like any prose',
    !str_contains($hostilepm, '<script>') && str_contains($hostilepm, '&lt;script&gt;')
);

section('History cannot grow the page without bound');

$many = [];
for ($i = 1; $i <= Renderer::HISTORY_MAX + 8; $i++) {
    $many[] = [
        'title'       => sprintf('Old issue %02d', $i),
        'state'       => Incident::RESOLVED,
        'started_at'  => NOW - 5000 - $i,
        'resolved_at' => NOW - 4000 - $i,
        'updates'     => [['at' => NOW - 4000 - $i, 'state' => Incident::RESOLVED, 'content' => 'Fixed.']],
    ];
}
$big = Renderer::document(fixture(['incidents' => $many, 'maintenance' => []]));

check(
    'at most HISTORY_MAX resolved incidents are listed',
    preg_match_all('#<details#', $big) === Renderer::HISTORY_MAX
);
check(
    'the newest survive the cut, in the order given',
    str_contains($big, 'Old issue 01') && str_contains($big, 'Old issue ' . sprintf('%02d', Renderer::HISTORY_MAX))
);
check(
    'the oldest are dropped, not hidden',
    !str_contains($big, 'Old issue ' . sprintf('%02d', Renderer::HISTORY_MAX + 1))
);
check(
    'and the page says what it is not showing',
    str_contains($big, 'Showing the ' . Renderer::HISTORY_MAX . ' most recent of '
        . (Renderer::HISTORY_MAX + 8) . ' resolved issues')
);
check(
    'an uncapped page carries no such line',
    !str_contains($html, 'most recent of'),
    'the count line appears only when something was dropped'
);

section('It is not enormous');

check(
    'a busy page stays under 64 KB before the logo',
    strlen(Renderer::document(fixture(['brand' => ['name' => 'Northwind IT']]))) < 65536,
    strlen(Renderer::document(fixture(['brand' => ['name' => 'Northwind IT']]))) . ' bytes'
);

section('The signed-in view renders from the same body');

// The in-portal page is not a second renderer. PortalStatus prints
// Renderer::body() into the interface's chrome, and the published file wraps
// the identical call in its own document — so the two surfaces cannot drift
// into showing a customer different things, which is the whole reason the
// markup was converted to the interface's own vocabulary.

$body = Renderer::body(fixture());

check(
    'the published document contains the body verbatim',
    str_contains(Renderer::document(fixture()), $body),
    'one layout, two frames'
);

// The frame is the only difference. A body carrying any of these would be
// injecting a second document into the middle of a page that already has one.
foreach (['<!DOCTYPE', '<html', '<head', '<body', '<style', '</html>'] as $chrome) {
    check(
        "the body carries no $chrome",
        !str_contains($body, $chrome)
    );
}

check('the body still carries the banner', str_contains($body, 'alert-title'));
check(
    'the body still carries the sections',
    str_contains($body, 'Current issues') && str_contains($body, 'Planned maintenance')
);

// Signing in does not entitle a customer to more than the address does. The
// same redaction check the document gets, applied to what the portal prints.
foreach ($forbidden as $word) {
    check(
        "the body leaks no \"$word\" either",
        preg_match('/\b' . preg_quote($word, '/') . '\b/i', $body) === 0
    );
}

finish();
