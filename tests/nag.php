<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The reminder arithmetic.
 *
 * Worth its own suite because every mistake this code can make is invisible: a
 * reminder that never fires looks exactly like a promise that was kept, and one
 * that fires every five minutes gets muted within the hour — after which no
 * reminder ever fires again either. Neither shows up in a log anybody reads.
 *
 * Usage, inside the GLPI container:
 *   php /var/www/glpi/plugins/glpimajor/tests/nag.php
 */

require __DIR__ . '/stubs.php';
require __DIR__ . '/../src/Nag.php';

use GlpiPlugin\Glpimajor\Nag;

$at = static fn(int $offset_minutes): string => date('Y-m-d H:i:s', 1_700_000_000 + ($offset_minutes * 60));

const NOW = 1_700_000_000;

section('Nothing promised');

check(
    'no promise is never late',
    !Nag::isDue(null, null, 5, 30, NOW)
);
check(
    'an empty promise is never late',
    !Nag::isDue('', null, 5, 30, NOW)
);
check(
    'a zero date is never late',
    !Nag::isDue('0000-00-00 00:00:00', null, 5, 30, NOW)
);
check(
    'an unparseable promise is never late',
    !Nag::isDue('next tuesday-ish', null, 5, 30, NOW)
);

section('The grace period');

check(
    'a promise still in the future is not late',
    !Nag::isDue($at(10), null, 5, 30, NOW)
);
check(
    'a promise due right now is inside its grace',
    !Nag::isDue($at(0), null, 5, 30, NOW)
);
check(
    'four minutes past a five-minute grace is not late',
    !Nag::isDue($at(-4), null, 5, 30, NOW)
);
check(
    'exactly five minutes past a five-minute grace is late',
    Nag::isDue($at(-5), null, 5, 30, NOW)
);
check(
    'a zero grace makes the promise itself the deadline',
    Nag::isDue($at(0), null, 0, 30, NOW)
);
check(
    'a negative grace is treated as none rather than as time travel',
    Nag::isDue($at(0), null, -60, 30, NOW)
);

section('The repeat window');

check(
    'a reminder just sent is not sent again',
    !Nag::isDue($at(-60), $at(-5), 5, 30, NOW)
);
check(
    'a reminder sent 29 minutes ago waits',
    !Nag::isDue($at(-60), $at(-29), 5, 30, NOW)
);
check(
    'a reminder sent 30 minutes ago repeats',
    Nag::isDue($at(-60), $at(-30), 5, 30, NOW)
);
check(
    'a zero repeat is floored to one minute rather than firing every run',
    !Nag::isDue($at(-60), $at(0), 5, 0, NOW)
);

section('A new promise resets the reminder');

// The sequence that made this rule necessary: the owner is chased, answers by
// promising a new time, that time passes — and the stale nag timestamp would
// otherwise suppress the reminder for a whole repeat window.
check(
    'a reminder recorded before the current promise does not suppress it',
    Nag::isDue($at(-10), $at(-20), 5, 30, NOW),
    'nagged at -20, then promised -10, now 10 minutes past that'
);
check(
    'a reminder recorded after the current promise still counts',
    !Nag::isDue($at(-60), $at(-1), 5, 30, NOW)
);

section('When the next reminder can fire');

check(
    'nothing promised means no reminder is scheduled',
    Nag::nextAt(null, null, 5, 30) === null
);
check(
    'a fresh promise is chased at due + grace',
    Nag::nextAt($at(0), null, 5, 30) === NOW + 300,
    (string) Nag::nextAt($at(0), null, 5, 30)
);
check(
    'after a reminder, the next is a repeat window later',
    Nag::nextAt($at(-60), $at(-10), 5, 30) === NOW - 600 + 1800
);
check(
    'a reminder about an older promise does not delay the next one',
    Nag::nextAt($at(-10), $at(-20), 5, 30) === (NOW - 600) + 300
);

section('How late, in words a banner can use');

check('no promise is zero minutes late', Nag::overdueMinutes(null, NOW) === 0);
check('ninety minutes past reads as ninety', Nag::overdueMinutes($at(-90), NOW) === 90);
check(
    'a promise in the future reads as negative rather than as zero',
    Nag::overdueMinutes($at(30), NOW) === -30,
    'so a caller can tell "not yet" from "no promise"'
);

section('Values that contradict each other still terminate');

check(
    'an absurd grace does not make everything permanently on time by accident',
    !Nag::isDue($at(-10), null, 100000, 30, NOW)
);
check(
    'an absurd repeat still allows the first reminder',
    Nag::isDue($at(-10), null, 5, 100000, NOW)
);

finish();
