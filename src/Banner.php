<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

use Html;
use Ticket;

/**
 * What a technician sees at the top of a ticket.
 *
 * Two mutually exclusive things, in the record rather than behind a tab —
 * glpi-sop's placement philosophy, for the same reason: a tab is a thing people
 * stop opening, and "nobody noticed this ticket was part of the outage" is the
 * failure this plugin exists to prevent.
 *
 * If glpi-presence is installed it already shows who is *here*. This shows who
 * holds the *roles*. Two bars saying different things about the same ticket is
 * exactly right; two bars saying the same thing would not be, which is why
 * nothing here lists participants.
 *
 * Rendered server-side and complete. Unlike a presence bar there is nothing to
 * poll — an incident's roles do not change every eight seconds — so the markup
 * that arrives is the markup that stays, and the page needs no JavaScript to
 * look right.
 */
final class Banner
{
    public static function render(Ticket $ticket): void
    {
        if (!\Session::haveRight(Incident::$rightname, READ)) {
            return;
        }

        // Technician-facing. A requester looking at their own ticket in the
        // helpdesk interface must not be shown internal role assignments, and
        // the thing they *should* see — the public narrative — is the
        // status page, which says only what somebody decided to publish.
        if (\Session::getCurrentInterface() !== 'central') {
            return;
        }

        $tickets_id = (int) $ticket->getID();

        $incident = Incident::forTicket($tickets_id);
        if ($incident !== null) {
            self::incidentBanner($incident, $ticket);

            return;
        }

        $attached = Affected::incidentFor($tickets_id);
        if ($attached !== null) {
            self::attachedBanner($attached);

            return;
        }

        self::offerBanner($ticket);
        self::declareControl($ticket);
    }

    /**
     * The way in.
     *
     * Deliberately small and deliberately last: a full-width call to action on
     * every ticket in the instance would make declaring a major incident feel
     * like a routine button, which is the opposite of what it is. It renders
     * only for the people who hold the right to use it, which on a default
     * install is administrators — the brief's "not a whim" made structural.
     *
     * The form is inline rather than a page of its own. At the moment somebody
     * decides an outage is a major incident they have already spent the time
     * they had; a second screen is a second reason to do it later.
     */
    /**
     * The id of the form the declare control's fields belong to.
     *
     * They cannot be wrapped in a `<form>` here, and this is not a style
     * choice: PRE_ITIL_INFO_SECTION renders *inside* GLPI's own `<form>` for
     * the ticket, and HTML has no nested forms. The parser silently drops the inner
     * tag and adopts its fields into the outer one, so a `<form>` written here
     * does not exist in the DOM the browser builds — pressing Declare submits
     * the *ticket*, to `front/ticket.form.php`, which has no idea what
     * `declare=1` means. Nothing was declared and nothing said so.
     *
     * The fix is HTML's own answer to exactly this: every field carries
     * `form="<id>"`, which overrides the ancestor form and binds it to an
     * element created — outside every form — by `major.js`. Before that script
     * runs the fields reference an id that is not there yet, so they belong to
     * no form at all and cannot leak into a ticket somebody saves.
     */
    private const FORM_ID = 'glpimajor-declare-form';

    /** The id of the collapsible body the declare row expands. */
    private const DECLARE_BODY_ID = 'glpimajor-declare-body';

    private static function declareControl(Ticket $ticket): void
    {
        if (!\Session::haveRight(Incident::$rightname, CREATE) || !$ticket->canUpdateItem()) {
            return;
        }

        if (
            in_array((int) $ticket->fields['status'], $ticket->getClosedStatusArray(), true)
        ) {
            return;
        }

        $e           = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $entities_id = (int) $ticket->fields['entities_id'];
        $action      = Url::to('front/incident.form.php');
        $form        = " form='" . self::FORM_ID . "'";

        // Core's own section furniture — accordion-item / accordion-header /
        // accordion-button / accordion-collapse — rather than a bare
        // <details>, which arrived with the browser's default disclosure
        // triangle and none of the panel's type, padding or rules. A
        // disclosure is exactly what the other sections in this panel are, so
        // the only honest thing to reuse is their markup; the chevron is kept
        // for the same reason it is kept on Actors and Items.
        echo "<section class='accordion-item glpimajor-declare'"
           . " data-glpimajor-declare='" . $e($action) . "'"
           . " data-glpimajor-form='" . self::FORM_ID . "'>";
        echo "<div class='accordion-header' id='" . self::DECLARE_BODY_ID . "-heading'>";
        echo "<button class='accordion-button collapsed' type='button' data-bs-toggle='collapse'"
           . " data-bs-target='#" . self::DECLARE_BODY_ID . "' aria-expanded='false'"
           . " aria-controls='" . self::DECLARE_BODY_ID . "'>";
        echo "<i class='ti ti-urgent'></i>";
        echo "<span class='item-title'>" . __s('Declare a major incident', 'glpimajor') . '</span>';
        echo '</button></div>';
        // `collapse`, deliberately not `accordion-collapse`. Core's field panel
        // treats every `.accordion-collapse` inside #itil-data as one of its
        // own sections: it force-collapses them below 768px and persists their
        // open/closed state to the user's itil_layout preference. This is a
        // form disclosure, not a section of the record, and it should always
        // open closed — so it stays out of both.
        echo "<div id='" . self::DECLARE_BODY_ID . "' class='collapse'"
           . " aria-labelledby='" . self::DECLARE_BODY_ID . "-heading'>";
        echo "<div class='accordion-body'>";
        echo "<input type='hidden' name='_glpi_csrf_token' value='"
           . $e(\Session::getNewCSRFToken()) . "'$form>";
        echo "<input type='hidden' name='tickets_id' value='" . (int) $ticket->getID() . "'$form>";

        echo "<label class='form-label'>" . __s('Public title', 'glpimajor') . '</label>';
        echo "<input type='text' class='form-control' name='name' value='"
           . $e($ticket->fields['name']) . "' required$form>";
        echo "<div class='form-text'>"
           . __s('Pre-filled from this ticket, which is usually the wrong thing to publish. '
               . 'This is what readers see on the status page.', 'glpimajor')
           . '</div>';

        echo "<div class='row g-2 mt-1'>";
        echo "<div class='col-md-6'><label class='form-label'>"
           . __s('Commander', 'glpimajor') . '</label>';
        \User::dropdown([
            'name'   => 'users_id_commander',
            'value'  => (int) \Session::getLoginUserID(),
            'entity' => $entities_id,
            'right'  => 'all',
            'width'  => '100%',
        ]);
        echo '</div>';

        echo "<div class='col-md-6'><label class='form-label'>"
           . __s('Comms owner', 'glpimajor') . '</label>';
        \User::dropdown([
            'name'   => 'users_id_comms',
            'value'  => 0,
            'entity' => $entities_id,
            'right'  => 'all',
            'width'  => '100%',
        ]);
        echo '</div></div>';

        // Offered only where it can mean anything. An organisation with one site
        // never sees the question; an organisation with three offices is asked it
        // once, at the only moment anybody knows the answer.
        if (Tree::hasChildren($entities_id)) {
            $children = Tree::descendants($entities_id);
            $default  = Settings::flag('recursive_default');

            echo "<label class='form-check mt-2 glpimajor-declare-recursive'>";
            echo "<input type='checkbox' class='form-check-input' name='is_recursive' value='1'"
               . ($default ? " checked='checked'" : '') . "$form>";
            echo "<span class='form-check-label'>"
               . __s('Also covers sub-entities', 'glpimajor') . '</span></label>';
            echo "<div class='form-text'>"
               . sprintf(
                   __s('This entity has %d sub-entities. Ticked, the incident is published to '
                     . 'their status pages too and their tickets are offered the attach. It never '
                     . 'reaches another entity.', 'glpimajor'),
                   count($children)
               )
               . '</div>';
        }

        echo "<div class='mt-2'><button type='submit' name='declare' value='1' "
           . "class='btn btn-sm btn-danger'$form>"
           . __s('Declare', 'glpimajor') . '</button></div>';

        echo '</div></div></section>';
    }

    /** The ticket that *is* the incident. */
    private static function incidentBanner(array $row, Ticket $ticket): void
    {
        $e     = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $state = (string) $row['state'];
        $tone  = match ($state) {
            Incident::RESOLVED   => 'success',
            Incident::MONITORING => 'warning',
            default              => 'danger',
        };

        $url = Url::to('front/incident.form.php?id=' . (int) $row['id']);

        echo "<div class='accordion-item glpimajor-banner glpimajor-banner--$tone'>";
        echo "<div class='glpimajor-banner-head'>";
        echo "<span class='glpimajor-flag'>" . __s('Major incident', 'glpimajor') . '</span>';
        echo "<span class='glpimajor-state'>" . $e(Incident::stateLabel($state)) . '</span>';
        echo "<a class='glpimajor-open' href='" . $e($url) . "'>"
           . __s('Open the incident', 'glpimajor') . '</a>';
        echo '</div>';

        echo "<div class='glpimajor-title'>" . $e($row['name']) . '</div>';
        echo "<div class='glpimajor-sub'>"
           . __s('The title above is what the reader reads. It is not this ticket\'s title.', 'glpimajor')
           . '</div>';

        echo "<div class='glpimajor-roles'>";
        echo self::role(__('Commander', 'glpimajor'), (int) $row['users_id_commander']);
        echo self::role(__('Comms', 'glpimajor'), (int) $row['users_id_comms']);
        echo '</div>';

        $bits = [];

        $affected = (int) $row['affected_count'];
        $bits[]   = $affected === 1
            ? __s('1 affected ticket', 'glpimajor')
            : sprintf(__s('%d affected tickets', 'glpimajor'), $affected);

        $next = (string) ($row['next_update_at'] ?? '');
        if ($next !== '' && Incident::isOpenState($state)) {
            $late = Nag::overdueMinutes($next, time());
            if ($late > 0) {
                $bits[] = "<span class='glpimajor-late'>"
                        . sprintf(__s('update overdue by %d min', 'glpimajor'), $late)
                        . '</span>';
            } else {
                $bits[] = sprintf(
                    __s('next update %s', 'glpimajor'),
                    $e(\Html::convDateTime($next))
                );
            }
        } elseif (Incident::isOpenState($state)) {
            $bits[] = "<span class='glpimajor-late'>"
                    . __s('no next update promised', 'glpimajor') . '</span>';
        }

        echo "<div class='glpimajor-facts'>" . implode(' &middot; ', $bits) . '</div>';
        echo '</div>';
    }

    /** A ticket already attached to somebody else's incident. */
    private static function attachedBanner(array $attached): void
    {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $incident = new Incident();
        if (!$incident->getFromDB((int) $attached['plugin_glpimajor_incidents_id'])) {
            return;
        }

        $state = (string) $incident->fields['state'];
        $url   = Url::to('front/incident.form.php?id=' . (int) $incident->getID());

        echo "<div class='accordion-item glpimajor-banner glpimajor-banner--info'>";
        echo "<div class='glpimajor-banner-head'>";
        echo "<span class='glpimajor-flag'>" . __s('Part of a major incident', 'glpimajor') . '</span>';
        echo "<span class='glpimajor-state'>" . $e(Incident::stateLabel($state)) . '</span>';
        echo "<a class='glpimajor-open' href='" . $e($url) . "'>"
           . __s('Open the incident', 'glpimajor') . '</a>';
        echo '</div>';
        echo "<div class='glpimajor-title'>" . $e($incident->fields['name']) . '</div>';
        echo "<div class='glpimajor-sub'>"
           . __s('When the incident is resolved this ticket receives the resolution as a proposed '
               . 'solution, pending the requester\'s approval. It is never closed for you.', 'glpimajor')
           . '</div>';
        echo '</div>';
    }

    /**
     * The offer.
     *
     * One match or none. A list of three "possible duplicates" is a decision
     * handed back to the person who was asking for help with it, and during an
     * outage the answer to "which of these" is always "the obvious one" — so
     * the matcher picks, the banner shows its reasoning, and a technician who
     * disagrees ignores it.
     */
    private static function offerBanner(Ticket $ticket): void
    {
        if (!Settings::flag('match_enabled')) {
            return;
        }

        if (!\Session::haveRight(Incident::$rightname, UPDATE) || !$ticket->canUpdateItem()) {
            return;
        }

        // A ticket already answered is not waiting on anything.
        if (
            in_array((int) $ticket->fields['status'], $ticket->getSolvedStatusArray(), true)
            || in_array((int) $ticket->fields['status'], $ticket->getClosedStatusArray(), true)
        ) {
            return;
        }

        $entities_id = (int) $ticket->fields['entities_id'];
        // Its own entity's open incidents, plus any declared at an ancestor as
        // covering its sub-entities — so a ticket from the Manchester office is
        // offered the outage declared at the firm.
        $open = Incident::openFor($entities_id);
        if ($open === []) {
            return;
        }

        $best = Matcher::best(
            [
                'id'                => (int) $ticket->getID(),
                'entities_id'       => $entities_id,
                'ancestors'         => Tree::ancestors($entities_id),
                'itilcategories_id' => (int) $ticket->fields['itilcategories_id'],
                'locations_id'      => (int) ($ticket->fields['locations_id'] ?? 0),
                'name'              => (string) $ticket->fields['name'],
            ],
            $open,
            Settings::matchRules()
        );

        if ($best === null) {
            return;
        }

        $e        = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $incident = $best['incident'];

        echo "<div class='accordion-item glpimajor-banner glpimajor-banner--offer'"
           . " data-glpimajor-offer='" . (int) $incident['id'] . "'"
           . " data-glpimajor-ticket='" . (int) $ticket->getID() . "'"
           . " data-glpimajor-endpoint='" . $e(Url::to('ajax/major.php')) . "'"
           . " data-glpimajor-csrf='" . $e(\Session::getNewCSRFToken()) . "'>";

        echo "<div class='glpimajor-banner-head'>";
        echo "<span class='glpimajor-flag'>" . __s('Possible duplicate', 'glpimajor') . '</span>';
        echo "<span class='glpimajor-state'>"
           . $e(Incident::stateLabel((string) $incident['state'])) . '</span>';
        echo '</div>';

        echo "<div class='glpimajor-title'>" . $e($incident['name']) . '</div>';
        echo "<div class='glpimajor-sub'>"
           . sprintf(
               __s('Matched on %s. Attaching links this ticket to the incident and records that '
                 . 'you did it; nothing else changes.', 'glpimajor'),
               $e(Matcher::explain($best['reasons']))
           )
           . '</div>';

        echo "<div class='glpimajor-actions'>";
        echo "<button type='button' class='btn btn-sm btn-primary' data-glpimajor-action='attach'>"
           . __s('Attach as affected', 'glpimajor') . '</button>';
        echo "<a class='btn btn-sm btn-outline-secondary' href='"
           . $e(Url::to('front/incident.form.php?id=' . (int) $incident['id'])) . "'>"
           . __s('Look at the incident first', 'glpimajor') . '</a>';
        echo '</div>';

        echo '</div>';
    }

    private static function role(string $label, int $users_id): string
    {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        if ($users_id <= 0) {
            // Named as a gap, not left blank. "Comms: —" reads as a rendering
            // bug; "Comms: nobody yet" reads as the job it is.
            return "<span class='glpimajor-role glpimajor-role--empty'>"
                 . '<b>' . $e($label) . '</b> ' . __s('nobody yet', 'glpimajor') . '</span>';
        }

        $user = new \User();
        $name = $user->getFromDB($users_id) ? $user->getFriendlyName() : (string) $users_id;

        return "<span class='glpimajor-role'><b>" . $e($label) . '</b> ' . $e($name) . '</span>';
    }
}
