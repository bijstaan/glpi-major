<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

/**
 * When is a promised update late enough to say something about?
 *
 * Pure arithmetic, kept away from the cron that uses it, because every mistake
 * this code can make is a mistake you cannot see: a reminder that never fires
 * looks exactly like a promise that was kept, and a reminder that fires every
 * five minutes gets muted within the hour — after which no reminder ever fires
 * again either.
 *
 * Three rules, and the interactions between them are the whole job:
 *
 *   - grace: nothing is late the instant it is due; people are mid-sentence.
 *   - repeat: once said, do not say it again until the repeat window passes.
 *   - a re-promise resets everything. Moving the promise forward is the comms
 *     owner answering the reminder, so a nag recorded before the new promise
 *     must not suppress the nag for the new one.
 */
final class Nag
{
    /**
     * @param string|null $next_update_at DATETIME, or null when nothing was promised
     * @param string|null $last_nagged    DATETIME of the last reminder, or null
     * @param int         $grace_minutes  how long past due before it counts as late
     * @param int         $repeat_minutes minimum gap between two reminders
     * @param int         $now            unix time
     */
    public static function isDue(
        ?string $next_update_at,
        ?string $last_nagged,
        int $grace_minutes,
        int $repeat_minutes,
        int $now
    ): bool {
        $due = self::stamp($next_update_at);

        // No promise, nothing to break. An incident whose comms owner has not
        // said when they will speak again is a different problem, and one this
        // reminder cannot fix by shouting.
        if ($due === null) {
            return false;
        }

        $late_at = $due + (max(0, $grace_minutes) * 60);
        if ($now < $late_at) {
            return false;
        }

        $nagged = self::stamp($last_nagged);
        if ($nagged === null) {
            return true;
        }

        // A reminder recorded before the promise it would be reminding about is
        // about a previous promise, and says nothing about this one. Without
        // this the sequence "nag → owner promises a new time → new time passes"
        // stays silent for a whole repeat window.
        if ($nagged < $due) {
            return true;
        }

        return ($now - $nagged) >= (max(1, $repeat_minutes) * 60);
    }

    /**
     * When the next reminder could fire, as unix time, or null if none can.
     *
     * Used by the settings page to say what the reminder will actually do,
     * rather than making an administrator infer it from three numbers.
     */
    public static function nextAt(
        ?string $next_update_at,
        ?string $last_nagged,
        int $grace_minutes,
        int $repeat_minutes
    ): ?int {
        $due = self::stamp($next_update_at);
        if ($due === null) {
            return null;
        }

        $late_at = $due + (max(0, $grace_minutes) * 60);
        $nagged  = self::stamp($last_nagged);

        if ($nagged === null || $nagged < $due) {
            return $late_at;
        }

        return max($late_at, $nagged + (max(1, $repeat_minutes) * 60));
    }

    /** How overdue, in whole minutes. Negative before the promise falls due. */
    public static function overdueMinutes(?string $next_update_at, int $now): int
    {
        $due = self::stamp($next_update_at);
        if ($due === null) {
            return 0;
        }

        return (int) floor(($now - $due) / 60);
    }

    private static function stamp(?string $value): ?int
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000')) {
            return null;
        }

        $at = strtotime($value);

        return $at === false ? null : $at;
    }
}
