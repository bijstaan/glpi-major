<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Feed::merge() — the one order the incident feed tells its story in.
 *
 * Pure: three arrays in, one array out, no database. The recording side
 * (setState() writing a row, the declaration synthesised from the incident's
 * own columns) is covered by tests/db-live.php, which has an incident to do
 * it to.
 */

require __DIR__ . '/stubs.php';
require __DIR__ . '/../src/Feed.php';

use GlpiPlugin\Glpimajor\Feed;

$declared = static fn(string $at): array => [
    'kind' => Feed::DECLARED, 'at' => $at, 'id' => 0, 'users_id' => 7, 'tickets_id' => 42,
    'state' => 'investigating',
];
$update = static fn(string $at, int $id): array => [
    'kind' => Feed::UPDATE, 'at' => $at, 'id' => $id, 'users_id' => 7, 'update' => ['id' => $id],
];
$state = static fn(string $at, int $id, string $from, string $to): array => [
    'kind' => Feed::STATE, 'at' => $at, 'id' => $id, 'users_id' => 7,
    'from' => $from, 'to' => $to, 'outcome' => '',
];

$kinds = static fn(array $rows): string => implode(',', array_map(static fn($r) => $r['kind'], $rows));
$ids   = static fn(array $rows): string => implode(',', array_map(static fn($r) => (string) $r['id'], $rows));

section('Chronology');

$out = Feed::merge(
    $declared('2026-08-22 09:00:00'),
    [$update('2026-08-22 09:20:00', 11), $update('2026-08-22 09:05:00', 10)],
    [$state('2026-08-22 09:10:00', 1, 'investigating', 'identified')]
);
check(
    'oldest first, whatever order the sources arrived in',
    $kinds($out) === 'declared,update,state,update',
    $kinds($out)
);
check('the declaration is the first entry', $out[0]['kind'] === Feed::DECLARED);

section('The same second');

// The composer's combined post: the note is published, then the state moves,
// inside one second. The note was written under the old state, so the story
// must read note-then-transition.
$out = Feed::merge(
    $declared('2026-08-22 09:00:00'),
    [$update('2026-08-22 09:30:00', 12)],
    [$state('2026-08-22 09:30:00', 2, 'identified', 'monitoring')]
);
check(
    'an update precedes a state change written in the same second',
    $kinds($out) === 'declared,update,state',
    $kinds($out)
);

$out = Feed::merge(
    $declared('2026-08-22 09:00:00'),
    [],
    [$state('2026-08-22 09:00:00', 3, '', 'investigating')]
);
check(
    'and the declaration precedes everything, even in its own second',
    $kinds($out) === 'declared,state',
    $kinds($out)
);

$out = Feed::merge(
    null,
    [$update('2026-08-22 09:30:00', 21), $update('2026-08-22 09:30:00', 20)],
    []
);
check(
    'same kind, same second falls back to insertion id',
    $ids($out) === '20,21',
    $ids($out)
);

section('Degenerate shapes');

check('no sources at all is an empty feed, not an error', Feed::merge(null, [], []) === []);

$out = Feed::merge(null, [], [$state('2026-08-22 09:10:00', 1, 'investigating', 'identified')]);
check(
    'a missing declaration (no date_declared on the row) merges what remains',
    $kinds($out) === 'state',
    $kinds($out)
);

$out = Feed::merge($declared('2026-08-22 09:00:00'), [], []);
check('a freshly declared incident is a one-entry story', $kinds($out) === 'declared');

finish();
