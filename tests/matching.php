<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Duplicate matching.
 *
 * The matcher decides what a technician is *offered*, so its mistakes are of
 * two kinds and only one of them is survivable. Offering nothing means twelve
 * people file twelve tickets and nobody notices — annoying, and exactly the
 * state of the world before this plugin. Offering the wrong incident means a
 * technician is told, on one entity's ticket, the name of a different
 * entity's outage. The first assertions here are about that.
 *
 * Usage, inside the GLPI container:
 *   php /var/www/glpi/plugins/glpimajor/tests/matching.php
 */

require __DIR__ . '/stubs.php';
require __DIR__ . '/../src/Matcher.php';

use GlpiPlugin\Glpimajor\Incident;
use GlpiPlugin\Glpimajor\Matcher;

const NOW = 1_700_000_000;

$rules = [
    'enabled'       => true,
    'same_category' => true,
    'same_location' => false,
    'keywords'      => false,
    'window_hours'  => 72,
    'min_word'      => 5,
];

$declared = static fn(int $hours_ago): string => date('Y-m-d H:i:s', NOW - ($hours_ago * 3600));

$incident = static function (array $overrides = []) use ($declared): array {
    return $overrides + [
        'id'                => 1,
        'entities_id'       => 4,
        'name'              => 'Email is slow',
        'state'             => Incident::INVESTIGATING,
        'date_declared'     => $declared(1),
        'itilcategories_id' => 7,
        'locations_id'      => 0,
    ];
};

$ticket = static function (array $overrides = []): array {
    return $overrides + [
        'id'                => 100,
        'entities_id'       => 4,
        'itilcategories_id' => 7,
        'locations_id'      => 0,
        'name'              => 'Cannot send email',
    ];
};

section('The tenant boundary');

check(
    'an incident in another entity is never offered',
    Matcher::match($ticket(), [$incident(['entities_id' => 5])], $rules, NOW) === [],
    'even with an identical category'
);
check(
    'a ticket with no entity matches nothing',
    Matcher::match($ticket(['entities_id' => -1]), [$incident()], $rules, NOW) === []
);
check(
    'entity 0 is a real entity, not a wildcard',
    Matcher::match(
        $ticket(['entities_id' => 0, 'itilcategories_id' => 7]),
        [$incident(['entities_id' => 0])],
        $rules,
        NOW
    ) !== []
);

section('Down the tree, and only down');

// The shape being asserted: entity 4 is an office of firm 2, which is under
// root 0. Entity 5 is a *different* firm, also under root — a sibling of 2 and
// no relation to 4. The ticket carries its own ancestry, which is what keeps
// the matcher pure and what makes the sibling case a test rather than a hope.
$office  = static fn(array $overrides = []) => $overrides + ['ancestors' => [0, 2]];
$branch  = static fn(array $o = []): array => $ticket($office($o));

check(
    'an incident declared at the firm above, marked as covering sub-entities, is offered',
    Matcher::match(
        $branch(),
        [$incident(['entities_id' => 2, 'is_recursive' => 1])],
        $rules,
        NOW
    ) !== [],
    'one organisation with two offices is one organisation'
);
check(
    'the same incident without the flag is not',
    Matcher::match(
        $branch(),
        [$incident(['entities_id' => 2, 'is_recursive' => 0])],
        $rules,
        NOW
    ) === [],
    'coverage is a decision somebody made, not a consequence of the tree'
);
check(
    'a missing flag reads as not covering',
    Matcher::match($branch(), [$incident(['entities_id' => 2])], $rules, NOW) === [],
    'back-compatibility: rows written before the column existed cover nothing'
);
check(
    'a covering incident in a SIBLING firm is never offered',
    Matcher::match(
        $branch(),
        [$incident(['entities_id' => 5, 'is_recursive' => 1])],
        $rules,
        NOW
    ) === [],
    'the flag says "downwards", and a sibling is in nobody\'s ancestry'
);
check(
    'nor is one in a sibling OFFICE of the same firm',
    Matcher::match(
        $branch(),
        [$incident(['entities_id' => 9, 'is_recursive' => 1])],
        $rules,
        NOW
    ) === [],
    'entity 9 is the London office; Manchester is not underneath it'
);
check(
    'and a ticket at the firm is not offered its own office\'s incident',
    Matcher::match(
        $ticket(['entities_id' => 2, 'ancestors' => [0]]),
        [$incident(['entities_id' => 4, 'is_recursive' => 1])],
        $rules,
        NOW
    ) === [],
    'recursion never travels upwards, whatever the flag says'
);
check(
    'a ticket with no ancestry given falls back to its own entity only',
    Matcher::match(
        $ticket(),
        [$incident(['entities_id' => 2, 'is_recursive' => 1])],
        $rules,
        NOW
    ) === [],
    'a caller that forgot to pass the tree gets the narrow answer, not the wide one'
);
check(
    'the incident\'s own entity still wins without any ancestry at all',
    Matcher::match($branch(), [$incident(['entities_id' => 4])], $rules, NOW) !== []
);

section('Only open incidents, only recent ones');

check(
    'a resolved incident is not offered',
    Matcher::match($ticket(), [$incident(['state' => Incident::RESOLVED])], $rules, NOW) === []
);
check(
    'monitoring still counts as open',
    Matcher::match($ticket(), [$incident(['state' => Incident::MONITORING])], $rules, NOW) !== []
);
check(
    'an incident older than the window is not offered',
    Matcher::match($ticket(), [$incident(['date_declared' => $declared(100)])], $rules, NOW) === []
);
check(
    'an incident inside the window is offered',
    Matcher::match($ticket(), [$incident(['date_declared' => $declared(71)])], $rules, NOW) !== []
);
check(
    'an incident with no declared date is not offered',
    Matcher::match($ticket(), [$incident(['date_declared' => null])], $rules, NOW) === [],
    'rather than being treated as declared at the epoch'
);
check(
    'an incident declared in the future is not offered',
    Matcher::match($ticket(), [$incident(['date_declared' => $declared(-5)])], $rules, NOW) === [],
    'a clock disagreement must not become a permanent match'
);

section('Category matching');

check(
    'the same category matches',
    Matcher::match($ticket(), [$incident()], $rules, NOW) !== []
);
check(
    'a different category does not',
    Matcher::match($ticket(['itilcategories_id' => 9]), [$incident()], $rules, NOW) === []
);
check(
    'two uncategorised things are not "the same category"',
    Matcher::match(
        $ticket(['itilcategories_id' => 0]),
        [$incident(['itilcategories_id' => 0])],
        $rules,
        NOW
    ) === [],
    'or every uncategorised ticket matches every uncategorised incident'
);

section('Signals can be switched off and on');

$off = ['enabled' => false] + $rules;
check('disabled matches nothing at all', Matcher::match($ticket(), [$incident()], $off, NOW) === []);

$location_only = ['same_category' => false, 'same_location' => true] + $rules;
check(
    'with only location on, a shared category is not enough',
    Matcher::match($ticket(), [$incident()], $location_only, NOW) === []
);
check(
    'with only location on, a shared location is enough',
    Matcher::match(
        $ticket(['locations_id' => 3]),
        [$incident(['locations_id' => 3])],
        $location_only,
        NOW
    ) !== []
);
check(
    'location 0 is "not set", not a match',
    Matcher::match($ticket(), [$incident()], $location_only, NOW) === []
);

section('Keyword matching, and its floor');

$keywords = ['same_category' => false, 'keywords' => true] + $rules;

check(
    'a shared long word matches',
    Matcher::match(
        $ticket(['name' => 'Fileserver share unavailable']),
        [$incident(['name' => 'Fileserver outage'])],
        $keywords,
        NOW
    ) !== []
);
check(
    'nothing in common does not match',
    Matcher::match(
        $ticket(['name' => 'Printer jam']),
        [$incident(['name' => 'Fileserver outage'])],
        $keywords,
        NOW
    ) === []
);
check(
    'short words do not count',
    Matcher::match(
        $ticket(['name' => 'The mail is not the best']),
        [$incident(['name' => 'The wifi is not the best'])],
        $keywords,
        NOW
    ) === [],
    'a five-character floor keeps "the" and "not" out of it'
);
check(
    'matching is case-insensitive',
    Matcher::match(
        $ticket(['name' => 'EXCHANGE down']),
        [$incident(['name' => 'exchange degraded'])],
        $keywords,
        NOW
    ) !== []
);
check(
    'punctuation does not hide a word',
    Matcher::match(
        $ticket(['name' => 'Exchange: down!']),
        [$incident(['name' => '(exchange) degraded'])],
        $keywords,
        NOW
    ) !== []
);
check(
    'an empty title matches nothing',
    Matcher::match(
        $ticket(['name' => '']),
        [$incident(['name' => 'Fileserver outage'])],
        $keywords,
        NOW
    ) === []
);

section('Word extraction');

check(
    'words are lowercased, deduplicated and filtered',
    Matcher::words('Server SERVER down, server!', 5) === ['server'],
    implode('|', Matcher::words('Server SERVER down, server!', 5))
);
check(
    'the floor is never below three, whatever is configured',
    Matcher::words('a ab abc abcd', 1) === ['abc', 'abcd'],
    implode('|', Matcher::words('a ab abc abcd', 1))
);
check(
    'digits count as part of a word',
    Matcher::words('exch01 exch02', 5) === ['exch01', 'exch02']
);
check(
    'accented letters survive',
    Matcher::words('téléphonie', 5) === ['téléphonie']
);

section('Ranking: the offer is one thing, not a list');

$both = ['same_category' => true, 'same_location' => true, 'keywords' => true] + $rules;

$candidates = [
    $incident(['id' => 1, 'name' => 'Email is slow', 'date_declared' => $declared(10)]),
    $incident([
        'id'            => 2,
        'name'          => 'Cannot send email anywhere',
        'locations_id'  => 3,
        'date_declared' => $declared(1),
    ]),
];

$best = Matcher::best($ticket(['locations_id' => 3]), $candidates, $both, NOW);

check('the strongest match wins', $best !== null && (int) $best['incident']['id'] === 2);
check(
    'its reasons are reported so a technician can disagree',
    $best !== null && count($best['reasons']) >= 2,
    $best !== null ? implode(', ', $best['reasons']) : 'none'
);
check(
    'best() returns null rather than an empty array when nothing matches',
    Matcher::best($ticket(['itilcategories_id' => 99]), [$incident()], $rules, NOW) === null
);

$tied = [
    $incident(['id' => 10, 'date_declared' => $declared(20)]),
    $incident(['id' => 11, 'date_declared' => $declared(2)]),
];
$best_tied = Matcher::best($ticket(), $tied, $rules, NOW);
check(
    'on a tie the more recent incident wins',
    $best_tied !== null && (int) $best_tied['incident']['id'] === 11,
    'during an outage the answer to "which of these" is the one declared twenty minutes ago'
);

section('Explanations are words, not codes');

$explained = Matcher::explain(['category', 'keywords']);
check(
    'reasons render as prose',
    str_contains($explained, 'category') && str_contains($explained, 'wording'),
    $explained
);
check('an unknown reason passes through rather than vanishing', Matcher::explain(['weather']) === 'weather');

section('Malformed rows do not throw');

check(
    'an incident row missing everything is skipped, not fatal',
    Matcher::match($ticket(), [['id' => 1]], $rules, NOW) === []
);
check(
    'an empty candidate list is fine',
    Matcher::match($ticket(), [], $rules, NOW) === []
);

finish();
