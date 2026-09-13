<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

use Html;
use Session;

/**
 * The panels below the incident's own form: comms, affected tickets, review.
 *
 * One page rather than three tabs. During an outage the question is never "what
 * does tab four say" — it is "what did we last tell them, who else is waiting,
 * and when did I say I would speak again", and all three of those have to be
 * answerable in one glance without navigating.
 *
 * Everything here echoes. Escaping is explicit at every interpolation, and the
 * one place raw markup is emitted deliberately — the public update's own text
 * — goes through nl2br(htmlspecialchars()) rather than any HTML filter.
 */
final class View
{
    /**
     * The cockpit: everything a commander glances at, above everything else.
     *
     * Modelled on what a good incident header does (OneUptime's, per the
     * screenshots that prompted this): the public title as the
     * biggest thing on the page, the state as a pill, how long it has been
     * going on, where it is in the progression, and the handful of numbers
     * that are actually asked for out loud — when it was declared, how long
     * people waited for the first word, how many tickets are riding on
     * it, and when the next word is due. The one prominent button is the
     * natural next transition; it drives the composer below rather than a
     * modal of its own, because the state change and the note that explains
     * it are one act.
     */
    public static function cockpit(Incident $incident): void
    {
        $incidents_id = (int) $incident->getID();
        if ($incidents_id <= 0) {
            return;
        }

        $can_edit = Session::haveRight(Incident::$rightname, UPDATE);
        $state    = (string) $incident->fields['state'];
        $is_open  = Incident::isOpenState($state);
        $tone     = self::tone($state);

        echo "<div class='glpimajor-cockpit glpimajor-cockpit--$tone'>";

        // ---- title line, and the one big action
        echo "<div class='glpimajor-cockpit-top'>";
        echo "<div class='glpimajor-cockpit-lede'>";
        echo "<div class='glpimajor-cockpit-flagline'>";
        echo "<span class='glpimajor-flag'>" . __s('Major incident', 'glpimajor') . '</span>';
        echo "<span class='glpimajor-pill glpimajor-pill--$tone'>"
           . self::e(Incident::stateLabel($state)) . '</span>';

        $declared = (string) ($incident->fields['date_declared'] ?? '');
        if ($is_open && $declared !== '') {
            // The live "Ongoing for …". Rendered by the server like everything
            // else; the epoch attribute lets major.js re-derive the same text
            // every half-minute, which is presentation, not state.
            echo "<span class='glpimajor-cockpit-since' data-glpimajor-since='"
               . (int) strtotime($declared) . "'>"
               . sprintf(__s('Ongoing for %s', 'glpimajor'), self::duration(time() - strtotime($declared)))
               . '</span>';
        } elseif ($declared !== '' && (string) ($incident->fields['date_resolved'] ?? '') !== '') {
            echo "<span class='glpimajor-cockpit-since'>"
               . sprintf(
                   __s('Resolved after %s', 'glpimajor'),
                   self::duration(strtotime((string) $incident->fields['date_resolved']) - strtotime($declared))
               )
               . '</span>';
        }
        echo '</div>';

        echo "<h2 class='glpimajor-cockpit-title'>" . self::e((string) $incident->fields['name']) . '</h2>';
        echo '</div>';

        if ($can_edit && $is_open) {
            self::cockpitActions($state);
        }
        echo '</div>';

        // ---- the progression strip
        $order   = array_keys(Incident::states());
        $at      = (int) array_search($state, $order, true);
        echo "<ol class='glpimajor-strip'>";
        foreach ($order as $i => $step) {
            $cls = $i < $at ? 'is-done' : ($i === $at ? 'is-current glpimajor-tone--' . self::tone($step) : 'is-todo');
            // Resolved marks the whole journey travelled, whichever states it
            // skipped — the strip reads "where are we", not "which boxes did
            // we tick".
            if ($state === Incident::RESOLVED && $i < $at) {
                $cls = 'is-done';
            }
            echo "<li class='$cls'><span class='glpimajor-strip-label'>"
               . self::e(Incident::stateLabel($step)) . '</span></li>';
        }
        echo '</ol>';

        // ---- the stat chips
        echo "<div class='glpimajor-cockpit-chips'>";

        echo "<span class='glpimajor-chip'><span class='glpimajor-chip-label'>"
           . __s('Declared', 'glpimajor') . "</span><span class='glpimajor-chip-value'>"
           . sprintf(
               __s('%s by %s', 'glpimajor'),
               self::e(Html::convDateTime($declared)),
               self::e(self::userName((int) $incident->fields['users_id_declared']))
           )
           . '</span></span>';

        $ticket = new \Ticket();
        if ($ticket->getFromDB((int) $incident->fields['tickets_id'])) {
            echo "<span class='glpimajor-chip'><span class='glpimajor-chip-label'>"
               . __s('Ticket') . "</span><span class='glpimajor-chip-value'>"
               . $ticket->getLink() . '</span></span>';
        }

        // The comms-discipline number this plugin exists for: how long the
        // people waited before anybody told them anything.
        $first = null;
        foreach (array_reverse(Update::forIncident($incidents_id, Update::EXTERNAL)) as $row) {
            $first = (string) $row['date_creation'];
            break;
        }
        if ($first !== null && $declared !== '') {
            echo "<span class='glpimajor-chip'><span class='glpimajor-chip-label'>"
               . __s('First public update', 'glpimajor') . "</span><span class='glpimajor-chip-value'>"
               . sprintf(__s('in %s', 'glpimajor'), self::duration(max(0, strtotime($first) - strtotime($declared))))
               . '</span></span>';
        } else {
            echo "<span class='glpimajor-chip" . ($is_open ? ' is-late' : '')
               . "'><span class='glpimajor-chip-label'>"
               . __s('First public update', 'glpimajor') . "</span><span class='glpimajor-chip-value'>"
               . ($is_open ? __s('none yet', 'glpimajor') : __s('none', 'glpimajor')) . '</span></span>';
        }

        echo "<span class='glpimajor-chip'><span class='glpimajor-chip-label'>"
           . __s('Affected tickets', 'glpimajor') . "</span><span class='glpimajor-chip-value'>"
           . (int) ($incident->fields['affected_count'] ?? 0) . '</span></span>';

        if ($is_open) {
            $next = (string) ($incident->fields['next_update_at'] ?? '');
            $late = $next !== '' ? Nag::overdueMinutes($next, time()) : 0;
            echo "<span class='glpimajor-chip" . ($late > 0 ? ' is-late' : '')
               . "'><span class='glpimajor-chip-label'>"
               . __s('Next update', 'glpimajor') . "</span><span class='glpimajor-chip-value'>";
            if ($next === '') {
                echo __s('none promised', 'glpimajor');
            } elseif ($late > 0) {
                echo sprintf(__s('was due %d minutes ago', 'glpimajor'), $late);
            } else {
                echo sprintf(__s('due %s', 'glpimajor'), self::e(Html::convDateTime($next)));
            }
            echo '</span></span>';
        }

        echo '</div>';
        echo '</div>';
    }

    /**
     * The next transition as the one prominent button, everything else in a
     * small menu beside it — including regressions like monitoring back to
     * investigating, because "it turned out we had not fixed it" is a real
     * state change and hiding it teaches people to lie with Monitoring.
     * Every button drives the composer: the state pre-selected, the note
     * field focused, one mechanism.
     */
    private static function cockpitActions(string $current): void
    {
        $order = array_keys(Incident::states());
        $at    = (int) array_search($current, $order, true);
        $next  = $order[$at + 1] ?? null;

        echo "<div class='glpimajor-cockpit-actions'>";

        if ($next !== null) {
            $btn = $next === Incident::RESOLVED ? 'btn-success' : 'btn-primary';
            echo "<button type='button' class='btn $btn' data-glpimajor-goto='"
               . self::e($next) . "'>" . self::e(self::actionLabel($next)) . '</button>';
        }

        $others = array_filter($order, static fn($s) => $s !== $current && $s !== $next);
        if ($others !== []) {
            echo "<details class='glpimajor-cockpit-menu'>";
            echo "<summary class='btn btn-outline-secondary' title='"
               . __s('Other transitions', 'glpimajor') . "'>&#8964;</summary>";
            echo "<div class='glpimajor-cockpit-menu-list'>";
            foreach ($others as $s) {
                echo "<button type='button' class='glpimajor-cockpit-menu-item' data-glpimajor-goto='"
                   . self::e($s) . "'>" . self::e(self::actionLabel($s)) . '</button>';
            }
            echo '</div></details>';
        }

        echo '</div>';
    }

    /** What the button that takes you to a state should say. */
    private static function actionLabel(string $state): string
    {
        return match ($state) {
            Incident::INVESTIGATING => __('Back to investigating', 'glpimajor'),
            Incident::IDENTIFIED    => __('Mark identified', 'glpimajor'),
            Incident::MONITORING    => __('Start monitoring', 'glpimajor'),
            Incident::RESOLVED      => __('Resolve', 'glpimajor'),
            default                 => Incident::stateLabel($state),
        };
    }

    /** The banner's tone map, shared so the cockpit and the feed agree with it. */
    private static function tone(string $state): string
    {
        return match ($state) {
            Incident::RESOLVED   => 'success',
            Incident::MONITORING => 'warning',
            default              => 'danger',
        };
    }

    /** "3h 40m", "12m", "less than a minute". Minutes are this page's unit. */
    private static function duration(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);
        if ($minutes < 1) {
            return __('less than a minute', 'glpimajor');
        }
        if ($minutes < 60) {
            return sprintf(__('%dm', 'glpimajor'), $minutes);
        }

        return sprintf(__('%dh %dm', 'glpimajor'), intdiv($minutes, 60), $minutes % 60);
    }

    public static function panels(Incident $incident): void
    {
        $incidents_id = (int) $incident->getID();
        if ($incidents_id <= 0) {
            return;
        }

        $can_edit = Session::haveRight(Incident::$rightname, UPDATE);

        echo "<div class='glpimajor-view' data-glpimajor-endpoint='"
           . self::e(Url::to('ajax/major.php')) . "' data-glpimajor-csrf='"
           . self::e(Session::getNewCSRFToken()) . "' data-glpimajor-incident='"
           . $incidents_id . "'>";

        self::commsPanel($incident, $can_edit);
        self::affectedPanel($incident, $can_edit);
        // Public account above the internal review — the same order the
        // public page gives it, and the same argument: the finished
        // account is worth more to a reader than the working papers.
        self::postmortemPanel($incident, $can_edit);
        self::pirPanel($incident, $can_edit);

        echo '</div>';
    }

    // --------------------------------------------------------------- comms

    private static function commsPanel(Incident $incident, bool $can_edit): void
    {
        $incidents_id = (int) $incident->getID();
        $entities_id  = (int) $incident->fields['entities_id'];
        $state        = (string) $incident->fields['state'];

        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
           . __s('Communications', 'glpimajor') . '</h3></div><div class="card-body">';

        // The promise, first. It is the one field on this page that expires.
        $next = (string) ($incident->fields['next_update_at'] ?? '');
        $late = $next !== '' ? Nag::overdueMinutes($next, time()) : 0;

        echo "<div class='glpimajor-promise " . ($late > 0 ? 'is-late' : '') . "'>";
        if ($next === '') {
            echo "<span class='glpimajor-promise-label'>"
               . __s('No next update promised.', 'glpimajor') . '</span>';
        } elseif ($late > 0) {
            echo "<span class='glpimajor-promise-label'>"
               . sprintf(
                   __s('Next update was due %s — %d minutes ago.', 'glpimajor'),
                   self::e(Html::convDateTime($next)),
                   $late
               )
               . '</span>';
        } else {
            echo "<span class='glpimajor-promise-label'>"
               . sprintf(__s('Next update due %s.', 'glpimajor'), self::e(Html::convDateTime($next)))
               . '</span>';
        }

        if ($can_edit && Incident::isOpenState($state)) {
            $default = (int) Settings::get('update_interval');
            echo "<span class='glpimajor-promise-actions'>";
            echo "<button type='button' class='btn btn-sm btn-outline-secondary' "
               . "data-glpimajor-action='promise' data-glpimajor-minutes='" . $default . "'>"
               . sprintf(__s('In %d minutes', 'glpimajor'), $default) . '</button>';
            foreach ([15, 30, 120] as $minutes) {
                if ($minutes === $default) {
                    continue;
                }
                echo "<button type='button' class='btn btn-sm btn-outline-secondary' "
                   . "data-glpimajor-action='promise' data-glpimajor-minutes='" . $minutes . "'>"
                   . sprintf(__s('%d min', 'glpimajor'), $minutes) . '</button>';
            }
            echo '</span>';
        }
        echo '</div>';

        if ($can_edit && Incident::isOpenState($state)) {
            self::composer($incident, $entities_id);
        }

        // The feed: updates, state changes and the declaration, one order,
        // newest first — the last thing said is what the room is asking for.
        $feed = array_reverse(Feed::forIncident($incident));
        if ($feed === []) {
            echo "<p class='text-muted mb-0'>"
               . __s('Nothing has been said yet, to anybody.', 'glpimajor') . '</p>';
        } else {
            echo "<ol class='glpimajor-log'>";
            foreach ($feed as $entry) {
                match ($entry['kind']) {
                    Feed::UPDATE   => self::logEntry($entry['update']),
                    Feed::STATE    => self::stateEntry($entry),
                    Feed::DECLARED => self::declaredEntry($entry),
                    default        => null,
                };
            }
            echo '</ol>';
        }

        echo '</div></div>';
    }

    /** A recorded state transition, as a feed entry. */
    private static function stateEntry(array $entry): void
    {
        $to = (string) $entry['to'];

        echo "<li class='glpimajor-log-entry glpimajor-feed-state'>";
        echo "<div class='glpimajor-log-head'>";
        echo "<span class='glpimajor-feed-verb'>" . __s('Moved to', 'glpimajor') . '</span>';
        echo "<span class='glpimajor-pill glpimajor-pill--" . self::tone($to) . "'>"
           . self::e(Incident::stateLabel($to)) . '</span>';
        if ((string) $entry['from'] !== '') {
            echo "<span class='glpimajor-feed-from'>"
               . sprintf(__s('from %s', 'glpimajor'), self::e(Incident::stateLabel((string) $entry['from'])))
               . '</span>';
        }
        echo "<span class='glpimajor-log-who'>" . self::e(self::userName((int) $entry['users_id'])) . '</span>';
        echo "<span class='glpimajor-log-at'>"
           . self::e(Html::convDateTime((string) $entry['at'])) . '</span>';
        echo '</div>';

        // The outcome as it was written at the moment of resolving — a
        // snapshot on the transition row, not a read of the incident's
        // current (editable) column.
        if ((string) ($entry['outcome'] ?? '') !== '') {
            echo "<div class='glpimajor-log-body'>"
               . nl2br(self::e((string) $entry['outcome']), false) . '</div>';
        }

        echo '</li>';
    }

    /** The declaration, synthesised from the incident row — every feed starts here. */
    private static function declaredEntry(array $entry): void
    {
        $ticket = new \Ticket();
        $link   = $ticket->getFromDB((int) $entry['tickets_id'])
            ? $ticket->getLink()
            : self::e(sprintf(__('ticket #%d', 'glpimajor'), (int) $entry['tickets_id']));

        echo "<li class='glpimajor-log-entry glpimajor-feed-declared'>";
        echo "<div class='glpimajor-log-head'>";
        echo "<span class='glpimajor-feed-verb'>" . __s('Declared a major incident', 'glpimajor') . '</span>';
        echo "<span class='glpimajor-pill glpimajor-pill--" . self::tone((string) $entry['state']) . "'>"
           . self::e(Incident::stateLabel((string) $entry['state'])) . '</span>';
        echo "<span class='glpimajor-log-who'>" . self::e(self::userName((int) $entry['users_id'])) . '</span>';
        echo "<span class='glpimajor-log-at'>"
           . self::e(Html::convDateTime((string) $entry['at'])) . '</span>';
        echo '</div>';
        echo "<div class='glpimajor-feed-fromticket'>"
           . sprintf(__s('Promoted from %s', 'glpimajor'), $link) . '</div>';
        echo '</li>';
    }

    /**
     * The composer: one post, up to three effects.
     *
     * "We mitigated it, we're monitoring, next update in 30 minutes" is one
     * sentence, and it used to be three separate actions with three full
     * reloads — publish, then a state change on the main form, then a promise
     * button. So the composer carries the state and a next-update select
     * alongside the text, both defaulting to "keep", and one Post applies
     * whatever changed. The three underlying operations stay separate on the
     * server; this is one submit, not one concept.
     *
     * Since 0.1.2 the state is a pill row (current pill selected = keep) and
     * the audience a segmented control, but the payload the server reads is
     * exactly 0.1.1's — presentation moved, the contract did not. The
     * audience is radios underneath and defaults to internal: a default of
     * "public" would mean one mis-click publishing an internal note
     * to a status page, and there is no unsend.
     */
    private static function composer(Incident $incident, int $entities_id): void
    {
        $snippets = Snippet::forEntity($entities_id);

        echo "<div class='glpimajor-composer mb-3'>";

        if ($snippets !== []) {
            echo "<div class='mb-2'>";
            echo "<label class='form-label'>" . __s('Start from a template', 'glpimajor') . '</label>';
            echo "<select class='form-select form-select-sm' data-glpimajor-snippet>";
            echo "<option value=''>" . __s('— none —', 'glpimajor') . '</option>';
            foreach ($snippets as $snippet) {
                echo "<option value='" . self::e($snippet['content'])
                   . "' data-audience='" . self::e($snippet['audience']) . "'>"
                   . self::e($snippet['name']) . '</option>';
            }
            echo '</select></div>';
        }

        echo "<textarea class='form-control' rows='4' data-glpimajor-update "
           . "placeholder='" . __s('What has changed, and what happens next. Optional when only '
               . 'the state moves.', 'glpimajor')
           . "'></textarea>";

        // The state, as a pill row rather than a dropdown. The current state
        // is selected — "keep" *is* the current pill staying selected — and
        // choosing another pill is the state change riding this post. A
        // dropdown made the most consequential field on the page the least
        // visible one.
        $current = (string) $incident->fields['state'];
        echo "<div class='glpimajor-composer-states' role='group' aria-label='"
           . __s('State', 'glpimajor') . "'>";
        echo "<span class='glpimajor-composer-caption'>" . __s('State', 'glpimajor') . '</span>';
        foreach (Incident::states() as $state => $label) {
            $is_current = $state === $current;
            echo "<button type='button' class='glpimajor-statepill glpimajor-statepill--"
               . self::tone($state)
               . ($is_current ? ' is-current is-selected' : '')
               . "' data-glpimajor-state-pill='" . self::e($state) . "'"
               . ($is_current ? " data-glpimajor-current='1' title='" . __s('The current state', 'glpimajor') . "'" : '')
               . '>' . self::e($label) . '</button>';
        }
        // What the server reads: '' means keep, anything else is the target.
        // The contract with ajax/major.php's `post` action is unchanged.
        echo "<input type='hidden' data-glpimajor-state value=''>";
        echo '</div>';

        echo "<div class='glpimajor-composer-bar'>";

        // The audience as a segmented control that says what the choice
        // *does*, not just who it is for. Internal stays the default: a
        // default of "public" would mean one mis-click publishing
        // an internal note to a status page, and there is no unsend.
        echo "<div class='glpimajor-audience' role='group' aria-label='"
           . __s('Audience', 'glpimajor') . "'>";
        echo "<label class='glpimajor-audience-opt'>";
        echo "<input type='radio' name='glpimajor_audience' value='" . Update::INTERNAL
           . "' checked='checked'>";
        echo '<span>' . __s('Internal note', 'glpimajor') . '</span></label>';
        echo "<label class='glpimajor-audience-opt'>";
        echo "<input type='radio' name='glpimajor_audience' value='" . Update::EXTERNAL . "'>";
        echo '<span>' . __s('Public — updates the status page', 'glpimajor') . '</span></label>';
        echo '</div>';

        // The next-update promise, also defaulting to "keep". The minute
        // choices are the promise line's quick buttons plus the configured
        // default, deduplicated and in order.
        $has_promise = (string) ($incident->fields['next_update_at'] ?? '') !== '';
        $minutes     = array_unique([15, 30, (int) Settings::get('update_interval'), 120]);
        sort($minutes);

        echo "<label class='glpimajor-composer-field'>";
        echo "<span class='glpimajor-composer-caption'>" . __s('Next update', 'glpimajor') . '</span>';
        echo "<select class='form-select form-select-sm' data-glpimajor-next>";
        echo "<option value='keep' selected='selected'>"
           . self::e($has_promise
               ? sprintf(
                   __('Keep: due %s', 'glpimajor'),
                   Html::convDateTime((string) $incident->fields['next_update_at'])
               )
               : __('Keep: none promised', 'glpimajor'))
           . '</option>';
        echo "<option value='none'>" . __s('No next update', 'glpimajor') . '</option>';
        foreach ($minutes as $m) {
            echo "<option value='" . (int) $m . "'>"
               . sprintf(__s('In %d minutes', 'glpimajor'), (int) $m) . '</option>';
        }
        echo '</select></label>';

        $refusal = Review::refusal($entities_id);
        if ($refusal === null) {
            echo "<button type='button' class='btn btn-sm btn-outline-secondary' "
               . "data-glpimajor-action='review'>" . __s('Review before publishing', 'glpimajor')
               . '</button>';
        } else {
            // Degrade honestly. The button is absent and the reason is written
            // down, rather than a button that does nothing.
            echo "<span class='text-muted small glpimajor-review-off' title='"
               . self::e($refusal) . "'>" . __s('Review unavailable', 'glpimajor') . '</span>';
        }

        echo "<button type='button' class='btn btn-sm btn-primary' data-glpimajor-action='post'>"
           . __s('Post', 'glpimajor') . '</button>';

        echo '</div>';

        // Revealed by the JS when Resolved is chosen; prefilled from the
        // record so an outcome already written on the main form is not asked
        // for twice. The refusal for an empty one is an inline sentence — the
        // server validates before it mutates, so nothing needs a reload to say
        // no.
        echo "<div class='glpimajor-composer-outcome' data-glpimajor-outcome-block hidden>";
        echo "<label class='form-label'>" . __s('Outcome summary', 'glpimajor') . '</label>';
        echo "<textarea class='form-control' rows='3' data-glpimajor-outcome placeholder='"
           . __s('What actually happened. One or two sentences is enough.', 'glpimajor') . "'>"
           . self::e((string) ($incident->fields['outcome'] ?? '')) . '</textarea>';
        echo "<div class='form-text'>"
           . __s('Required to resolve — this is the only moment anyone reliably remembers, and it '
               . 'becomes the proposed solution on every attached ticket.', 'glpimajor')
           . '</div></div>';

        echo "<div class='glpimajor-composer-error' data-glpimajor-post-error hidden></div>";

        echo "<div class='glpimajor-review-out' data-glpimajor-review hidden></div>";
        echo '</div>';
    }

    private static function logEntry(array $entry): void
    {
        $is_public = (string) $entry['audience'] === Update::EXTERNAL;

        echo "<li class='glpimajor-log-entry glpimajor-feed-update "
           . ($is_public ? 'is-public' : 'is-internal') . "'>";
        echo "<div class='glpimajor-log-head'>";
        echo "<span class='glpimajor-log-audience'>"
           . self::e($is_public ? __('Public', 'glpimajor') : __('Internal only', 'glpimajor'))
           . '</span>';
        echo "<span class='glpimajor-log-state'>"
           . self::e(Incident::stateLabel((string) $entry['state_at_time'])) . '</span>';
        echo "<span class='glpimajor-log-who'>" . self::e(self::userName((int) $entry['users_id'])) . '</span>';
        echo "<span class='glpimajor-log-at'>"
           . self::e(Html::convDateTime((string) $entry['date_creation'])) . '</span>';
        echo '</div>';

        echo "<div class='glpimajor-log-body'>"
           . nl2br(self::e((string) $entry['content']), false) . '</div>';

        if ((int) $entry['ai_reviewed'] === 1) {
            // Recorded and shown. "The model flagged three things and the
            // author published unchanged" is a fact somebody may need after the
            // fact, and it is only meaningful next to what was published.
            echo "<div class='glpimajor-log-review'>"
               . self::e(
                   (int) $entry['ai_heeded'] === 1
                       ? __('Reviewed before publishing; the wording was changed afterwards.', 'glpimajor')
                       : __('Reviewed before publishing; published as drafted.', 'glpimajor')
               )
               . '</div>';
        }

        echo '</li>';
    }

    // ------------------------------------------------------------ affected

    private static function affectedPanel(Incident $incident, bool $can_edit): void
    {
        $incidents_id = (int) $incident->getID();
        $rows         = Affected::forIncident($incidents_id);

        // The declaring ticket is the outage's first affected ticket, so it is
        // the first row and it is in the count — matching what recount() now
        // stores. It is drawn from the incident's own fields rather than from
        // the attach table, where it deliberately never has a row (attach()
        // refuses it; see the note on Affected::recount()).
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
           . sprintf(__s('Affected tickets (%d)', 'glpimajor'), count($rows) + 1)
           . '</h3></div><div class="card-body">';

        echo "<table class='table table-sm glpimajor-affected'>";
        echo '<thead><tr>'
           . '<th>' . __s('Ticket') . '</th>'
           . '<th>' . __s('Attached by', 'glpimajor') . '</th>'
           . '<th>' . __s('When', 'glpimajor') . '</th>'
           . '<th>' . __s('Resolution', 'glpimajor') . '</th>'
           . '<th></th></tr></thead><tbody>';

        $declaring = new \Ticket();
        $declaring_link = $declaring->getFromDB((int) $incident->fields['tickets_id'])
            ? $declaring->getLink()
            : sprintf(__s('#%d (gone)', 'glpimajor'), (int) $incident->fields['tickets_id']);

        echo "<tr class='glpimajor-declaring-row'>";
        echo '<td>' . $declaring_link . '</td>';
        echo '<td>' . sprintf(
            __s('declared by %s', 'glpimajor'),
            self::e(self::userName((int) $incident->fields['users_id_declared']))
        ) . '</td>';
        echo '<td>' . self::e(Html::convDateTime((string) $incident->fields['date_declared'])) . '</td>';
        // No proposed solution: the incident's outcome lives on this ticket's
        // own record, not as a proposal back onto itself.
        echo "<td><span class='text-muted'>" . __s('—') . '</span></td>';
        // And no detach button. An incident is a mode on this ticket; detaching
        // it would be un-declaring, which is a different decision.
        echo '<td></td></tr>';

        foreach ($rows as $row) {
            $ticket = new \Ticket();
            $link   = $ticket->getFromDB((int) $row['tickets_id'])
                ? $ticket->getLink()
                : sprintf(__s('#%d (gone)', 'glpimajor'), (int) $row['tickets_id']);

            echo '<tr>';
            echo '<td>' . $link . '</td>';
            echo '<td>' . self::e(self::userName((int) $row['users_id'])) . '</td>';
            echo '<td>' . self::e(Html::convDateTime((string) $row['date_creation'])) . '</td>';
            echo '<td>';
            if ((int) $row['itilsolutions_id'] > 0) {
                echo "<span class='glpimajor-proposed'>"
                   . sprintf(
                       __s('proposed %s', 'glpimajor'),
                       self::e(Html::convDateTime((string) $row['date_proposed']))
                   )
                   . '</span>';
            } else {
                echo "<span class='text-muted'>" . __s('—') . '</span>';
            }
            echo '</td>';
            echo '<td>';
            if ($can_edit) {
                echo "<button type='button' class='btn btn-sm btn-ghost-secondary' "
                   . "data-glpimajor-action='detach' data-glpimajor-ticket='"
                   . (int) $row['tickets_id'] . "'>" . __s('Detach', 'glpimajor') . '</button>';
            }
            echo '</td></tr>';
        }

        echo '</tbody></table>';

        if ($rows === []) {
            // The table always holds the declaring ticket now, so the empty
            // state is about the *others* — and the hint about where attaching
            // happens is the useful half of the old sentence.
            echo "<p class='text-muted small mb-0'>"
               . __s('No other tickets have been attached yet. Tickets that look like this '
                   . 'incident are offered the attach button on their own page.', 'glpimajor')
               . '</p>';
        } else {
            echo "<p class='text-muted small mb-0'>"
               . __s('Attached tickets are linked as children of this incident\'s ticket. On '
                   . 'resolution each receives the outcome as a solution pending approval; none of '
                   . 'them is closed.', 'glpimajor')
               . '</p>';
        }
        echo '</div></div>';
    }

    // ----------------------------------------------------------------- PIR

    // -------------------------------------------------------- post-mortem

    /**
     * The public post-mortem: authored here, read on the status page.
     *
     * Draftable from the moment the incident exists — half the story is best
     * written while it is fresh — but the Publish button appears only once
     * the incident is resolved, and the server refuses it before then too.
     * A "post-mortem" on a live outage declares the incident over in the
     * very paragraph the timeline above it contradicts.
     *
     * The AI assist degrades exactly as the composer's review does: the
     * button exists when the same four guards hold, and otherwise its
     * absence states the reason. The draft lands in this textarea for a
     * human to edit; publishing is never the model's act.
     */
    private static function postmortemPanel(Incident $incident, bool $can_edit): void
    {
        $incidents_id = (int) $incident->getID();
        $entities_id  = (int) $incident->fields['entities_id'];
        $resolved     = (string) $incident->fields['state'] === Incident::RESOLVED;

        $pm        = Postmortem::forIncident($incidents_id);
        $content   = (string) ($pm['content'] ?? '');
        $published = (int) ($pm['is_published'] ?? 0) === 1;
        $drafted   = (int) ($pm['ai_drafted'] ?? 0) === 1;

        echo "<div class='card mb-3 glpimajor-pm' data-glpimajor-postmortem>";
        echo "<div class='card-header'><h3 class='card-title'>"
           . __s('Public post-mortem', 'glpimajor') . '</h3>';
        echo "<div class='card-actions'>";
        if ($published) {
            echo "<span class='badge bg-green-lt'>" . __s('Published', 'glpimajor') . '</span>';
        } elseif (trim($content) !== '') {
            echo "<span class='badge bg-secondary-lt'>" . __s('Draft', 'glpimajor') . '</span>';
        }
        echo '</div></div><div class="card-body">';

        echo "<p class='text-muted small'>"
           . __s('This is what the reader reads on the status page, in their language — '
               . 'distinct from the internal post-incident review below, which stays in the '
               . 'house. Plain prose; blank lines become paragraphs.', 'glpimajor')
           . '</p>';

        if ($published) {
            echo "<div class='glpimajor-pm-meta text-muted small'>"
               . sprintf(
                   __s('Published %s by %s. It leads this incident\'s entry on the status '
                       . 'page.', 'glpimajor'),
                   self::e(Html::convDateTime((string) ($pm['published_at'] ?? ''))),
                   self::e(self::userName((int) ($pm['users_id_publisher'] ?? 0)))
               )
               . '</div>';

            if (!$resolved) {
                echo "<div class='alert alert-secondary py-2'>"
                   . __s('The incident is open again, so the published post-mortem is held off '
                       . 'the status page until it is resolved once more. Retracting it is the '
                       . 'other option.', 'glpimajor')
                   . '</div>';
            }
        }

        echo "<textarea class='form-control' rows='8' data-glpimajor-pm-content "
           . "placeholder='" . __s('What happened, what it meant for the people affected, what we did, '
               . 'and what we are changing.', 'glpimajor') . "'"
           . ($can_edit ? '' : " disabled='disabled'")
           . '>' . self::e($content) . '</textarea>';

        if ($drafted) {
            // The origin, kept honest — the same argument as the update log's
            // "reviewed before publishing" line.
            echo "<div class='glpimajor-pm-origin text-muted small'>"
               . __s('The first draft came from the AI assistant; a human has edited and '
                   . 'owns what is above.', 'glpimajor')
               . '</div>';
        }

        echo "<div class='glpimajor-composer-error' data-glpimajor-pm-error hidden></div>";

        if ($can_edit) {
            echo "<div class='glpimajor-pm-bar'>";

            $refusal = Postmortem::refusal($entities_id);
            if ($refusal === null) {
                echo "<button type='button' class='btn btn-sm btn-outline-secondary' "
                   . "data-glpimajor-action='pm-draft'>" . __s('Draft with AI', 'glpimajor')
                   . '</button>';
            } else {
                // Degrade honestly, exactly as the review button does: absent
                // with the reason written down, never a button that does
                // nothing.
                echo "<span class='text-muted small glpimajor-review-off' title='"
                   . self::e($refusal) . "'>" . __s('AI drafting unavailable', 'glpimajor')
                   . '</span>';
            }

            if (!$published) {
                echo "<button type='button' class='btn btn-sm btn-outline-secondary' "
                   . "data-glpimajor-action='pm-save'>" . __s('Save draft', 'glpimajor') . '</button>';
            }

            if ($resolved) {
                echo "<button type='button' class='btn btn-sm btn-primary' "
                   . "data-glpimajor-action='pm-publish'>"
                   . ($published
                       ? __s('Update the published text', 'glpimajor')
                       : __s('Publish to the status page', 'glpimajor'))
                   . '</button>';
            } elseif (!$published) {
                echo "<span class='text-muted small'>"
                   . __s('Publishing opens when the incident is resolved — a post-mortem on an '
                       . 'open incident declares it over while the feed above says otherwise. '
                       . 'Draft freely meanwhile.', 'glpimajor')
                   . '</span>';
            }

            if ($published) {
                echo "<button type='button' class='btn btn-sm btn-outline-danger' "
                   . "data-glpimajor-action='pm-retract'>"
                   . __s('Retract from the status page', 'glpimajor') . '</button>';
            }

            echo '</div>';
        }

        echo '</div></div>';
    }

    private static function pirPanel(Incident $incident, bool $can_edit): void
    {
        $incidents_id = (int) $incident->getID();
        $state        = (string) $incident->fields['state'];

        // Offered before resolution too, because half of what goes into it is
        // known while it is happening and none of it is known a week later.
        $pir = Pir::forIncident($incidents_id, (int) $incident->fields['entities_id'], $can_edit);

        if ($pir === null) {
            // Nobody has started one and this visitor cannot. Rendered as an
            // empty review rather than skipped, so the page has the same shape
            // for everybody and "there is no review yet" is visible rather than
            // being indistinguishable from a panel that failed to load.
            $pir = [
                'id'            => 0,
                'status'        => Pir::DRAFT,
                'is_unlocked'   => 0,
                'what_happened' => '',
                'impact'        => '',
                'root_cause'    => '',
                'problems_id'   => 0,
            ];
        }

        $locked   = Pir::isLocked($pir);
        $editable = $can_edit && !$locked;

        echo "<div class='card mb-3' data-glpimajor-pir='" . (int) $pir['id'] . "'>";
        echo "<div class='card-header'><h3 class='card-title'>"
           . __s('Post-incident review', 'glpimajor') . '</h3>';

        echo "<div class='card-actions'>";
        if ((string) $pir['status'] === Pir::COMPLETE) {
            echo "<span class='badge bg-green-lt me-2'>" . __s('Complete', 'glpimajor') . '</span>';
        }
        if ($can_edit && $locked) {
            echo "<button type='button' class='btn btn-sm btn-ghost-secondary' "
               . "data-glpimajor-action='pir-unlock'>" . __s('Unlock to edit', 'glpimajor') . '</button>';
        } elseif ($can_edit && (string) $pir['status'] === Pir::COMPLETE && Settings::flag('pir_lock_on_complete')) {
            echo "<button type='button' class='btn btn-sm btn-ghost-secondary' "
               . "data-glpimajor-action='pir-lock'>" . __s('Lock', 'glpimajor') . '</button>';
        }
        echo '</div></div><div class="card-body">';

        if ($locked) {
            echo "<div class='alert alert-secondary py-2'>"
               . __s('This review is complete and locked. Unlocking it is recorded.', 'glpimajor')
               . '</div>';
        }

        $disabled = $editable ? '' : " disabled='disabled'";

        foreach (
            [
                'what_happened' => [
                    __('What happened', 'glpimajor'),
                    __('The sequence, in plain language. The timeline below is assembled for you.', 'glpimajor'),
                ],
                'impact' => [
                    __('Impact', 'glpimajor'),
                    __('Who could not do what, and for how long.', 'glpimajor'),
                ],
                'root_cause' => [
                    __('Root cause', 'glpimajor'),
                    __('What actually caused it — not what triggered it.', 'glpimajor'),
                ],
            ] as $field => [$label, $help]
        ) {
            echo "<div class='mb-3'><label class='form-label'>" . self::e($label) . '</label>';
            echo "<textarea class='form-control' rows='3' data-glpimajor-pir-field='"
               . self::e($field) . "'$disabled>" . self::e((string) ($pir[$field] ?? '')) . '</textarea>';
            echo "<div class='form-text'>" . self::e($help) . '</div></div>';
        }

        echo "<div class='mb-3' style='max-width:420px'>";
        echo "<label class='form-label'>" . __s('Linked problem', 'glpimajor') . '</label>';
        \Problem::dropdown([
            'name'     => 'glpimajor_problems_id',
            'value'    => (int) ($pir['problems_id'] ?? 0),
            'entity'   => (int) $incident->fields['entities_id'],
            'comments' => false,
        ]);
        echo "<div class='form-text'>"
           . __s('The root cause lives in a problem record, not in this box. This is the pointer '
               . 'to it.', 'glpimajor')
           . '</div></div>';

        self::actions($pir, $editable, (int) $incident->fields['entities_id']);
        self::timeline($incidents_id);

        if ($editable) {
            echo "<div class='text-end'>";
            echo "<button type='button' class='btn btn-outline-secondary me-2' "
               . "data-glpimajor-action='pir-save'>" . __s('Save', 'glpimajor') . '</button>';
            if ((string) $pir['status'] !== Pir::COMPLETE) {
                echo "<button type='button' class='btn btn-primary' "
                   . "data-glpimajor-action='pir-complete'>"
                   . __s('Mark the review complete', 'glpimajor') . '</button>';
            }
            echo '</div>';
        }

        if ($state !== Incident::RESOLVED) {
            echo "<p class='text-muted small mb-0 mt-2'>"
               . __s('The incident is still open. You can fill this in as you go — most of it is '
                   . 'known now and none of it is known in a fortnight.', 'glpimajor')
               . '</p>';
        }

        echo '</div></div>';
    }

    private static function actions(array $pir, bool $editable, int $entities_id): void
    {
        $rows = (int) $pir['id'] > 0 ? PirAction::forPir((int) $pir['id']) : [];

        echo "<div class='glpimajor-actions-block mb-3'>";
        echo "<label class='form-label'>" . __s('Actions', 'glpimajor') . '</label>';

        if ($rows === []) {
            echo "<p class='text-muted small'>"
               . __s('Nothing agreed yet.', 'glpimajor') . '</p>';
        } else {
            echo "<table class='table table-sm'><tbody>";
            foreach ($rows as $action) {
                echo '<tr>';
                echo '<td>' . self::e((string) $action['content']) . '</td>';
                echo '<td>' . self::e(self::userName((int) $action['users_id_owner'])) . '</td>';
                echo '<td>' . self::e((string) ($action['due_date'] ?? '')) . '</td>';
                echo '<td>' . self::e(PirAction::statuses()[(string) $action['status']] ?? '') . '</td>';
                echo '<td>';
                if ((int) $action['improve_candidates_id'] > 0) {
                    echo "<span class='text-muted small'>"
                       . __s('in the improvement register', 'glpimajor') . '</span>';
                } elseif ($editable && Improve::available() && Settings::flag('improve_push')) {
                    echo "<button type='button' class='btn btn-sm btn-ghost-secondary' "
                       . "data-glpimajor-action='push' data-glpimajor-target='"
                       . (int) $action['id'] . "'>" . __s('Send to improvements', 'glpimajor')
                       . '</button>';
                }
                echo '</td>';
                echo '<td>';
                if ($editable) {
                    echo "<button type='button' class='btn btn-sm btn-ghost-danger' "
                       . "data-glpimajor-action='action-delete' data-glpimajor-target='"
                       . (int) $action['id'] . "'>" . __s('Remove', 'glpimajor') . '</button>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }

        if ($editable) {
            echo "<div class='row g-2 align-items-end'>";
            echo "<div class='col-md-6'><input type='text' class='form-control form-control-sm' "
               . "data-glpimajor-action-text placeholder='"
               . __s('What we will do differently', 'glpimajor') . "'></div>";
            echo "<div class='col-md-3'>";
            \User::dropdown([
                'name'     => 'glpimajor_action_owner',
                'value'    => 0,
                'entity'   => $entities_id,
                'right'    => 'all',
                'width'    => '100%',
            ]);
            echo '</div>';
            echo "<div class='col-md-2'><input type='date' class='form-control form-control-sm' "
               . 'data-glpimajor-action-due></div>';
            echo "<div class='col-md-1'><button type='button' class='btn btn-sm btn-outline-secondary w-100' "
               . "data-glpimajor-action='action-add'>" . __s('Add') . '</button></div>';
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * The assembled timeline.
     *
     * Read-only and not editable, because it is a derivation rather than a
     * field: it is the update log and the audit rows in one order. Letting
     * somebody edit it would mean the report and the record could disagree, and
     * the report is the one people read.
     */
    private static function timeline(int $incidents_id): void
    {
        $rows = Pir::timeline($incidents_id);
        if ($rows === []) {
            return;
        }

        echo "<details class='glpimajor-timeline mb-3'>";
        echo '<summary>' . __s('Assembled timeline', 'glpimajor') . '</summary>';
        echo '<ol>';
        foreach ($rows as $row) {
            echo '<li>';
            echo "<span class='glpimajor-tl-at'>"
               . self::e(Html::convDateTime($row['at'])) . '</span> ';
            echo "<span class='glpimajor-tl-label'>" . self::e($row['label']) . '</span>';
            if ($row['detail'] !== '') {
                echo "<div class='glpimajor-tl-detail'>" . self::e($row['detail']) . '</div>';
            }
            echo '</li>';
        }
        echo '</ol></details>';
    }

    // ------------------------------------------------------------- helpers

    private static function userName(int $users_id): string
    {
        if ($users_id <= 0) {
            return __('nobody', 'glpimajor');
        }

        $user = new \User();

        return $user->getFromDB($users_id) ? $user->getFriendlyName() : (string) $users_id;
    }

    private static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
