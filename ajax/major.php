<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Everything the incident page and the duplicate banner do without a reload.
 *
 * GLPI 11 bootstraps the framework before executing plugin `ajax/` scripts, so
 * there is no includes.php to pull in here.
 *
 * Every action re-authorises against the object it addresses rather than
 * trusting the client's word. The incident id arrives from the browser, so
 * without a per-action check this endpoint would happily attach one customer's
 * ticket to another customer's outage for anyone who can guess two integers.
 *
 * No Session::checkCSRF(). GLPI 11's CheckCsrfListener already validated this
 * request before the script was reached; for an XHR carrying
 * `X-Glpi-Csrf-Token` the token is preserved, and for a plain POST body it is
 * consumed — so a second check here fails on exactly the requests that were
 * correct.
 */

use GlpiPlugin\Glpimajor\Affected;
use GlpiPlugin\Glpimajor\Improve;
use GlpiPlugin\Glpimajor\Incident;
use GlpiPlugin\Glpimajor\Pir;
use GlpiPlugin\Glpimajor\PirAction;
use GlpiPlugin\Glpimajor\Postmortem;
use GlpiPlugin\Glpimajor\Review;
use GlpiPlugin\Glpimajor\Settings;
use GlpiPlugin\Glpimajor\Update;

header('Content-Type: application/json; charset=UTF-8');
Html::header_nocache();

/** Emit a JSON payload and stop. */
$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
};

if ((int) Session::getLoginUserID() <= 0) {
    $respond(['ok' => false, 'error' => 'unauthenticated'], 401);
}

if (Session::getCurrentInterface() !== 'central') {
    $respond(['ok' => false, 'error' => 'forbidden'], 403);
}

$action = (string) ($_POST['action'] ?? '');

// ---------------------------------------------------------------- attaching
//
// Addressed by ticket rather than by incident, because this is the one action
// reached from a ticket's own page — the caller knows which ticket it is on and
// which incident it was offered.

if ($action === 'attach' || $action === 'detach') {
    $incidents_id = (int) ($_POST['incidents_id'] ?? 0);
    $tickets_id   = (int) ($_POST['tickets_id'] ?? 0);

    if (!Session::haveRight(Incident::$rightname, UPDATE)) {
        $respond(['ok' => false, 'error' => 'forbidden'], 403);
    }

    $ticket = new Ticket();
    if (!$ticket->getFromDB($tickets_id) || !$ticket->can($tickets_id, UPDATE)) {
        $respond(['ok' => false, 'error' => 'forbidden'], 403);
    }

    $incident = new Incident();
    if (!$incident->getFromDB($incidents_id) || !$incident->can($incidents_id, READ)) {
        $respond(['ok' => false, 'error' => 'forbidden'], 403);
    }

    if ($action === 'attach') {
        $ok = Affected::attach($incidents_id, $tickets_id);
    } else {
        $ok = Affected::detach($incidents_id, $tickets_id);
    }

    $respond(['ok' => $ok, 'reload' => true]);
}

// Everything below addresses an incident directly.

$incidents_id = (int) ($_POST['incidents_id'] ?? 0);
$incident     = new Incident();

if (!$incident->getFromDB($incidents_id) || !$incident->can($incidents_id, READ)) {
    $respond(['ok' => false, 'error' => 'forbidden'], 403);
}

$entities_id = (int) $incident->fields['entities_id'];
$can_edit    = $incident->can($incidents_id, UPDATE);

switch ($action) {
    case 'review':
        if (!$can_edit) {
            $respond(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $result = Review::of((string) ($_POST['content'] ?? ''), $entities_id);

        $respond($result + ['findings' => Review::hasFindings($result)]);
        // no break — respond() never returns

    case 'publish':
        if (!$can_edit) {
            $respond(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $content  = (string) ($_POST['content'] ?? '');
        $audience = (string) ($_POST['audience'] ?? Update::INTERNAL);

        // The reviewed text is sent back so "was the review heeded" can be
        // decided by comparison rather than by asking the author, who has every
        // incentive to say yes.
        $review        = null;
        $reviewed_text = null;
        $raw_review    = (string) ($_POST['review'] ?? '');
        if ($raw_review !== '') {
            $decoded = json_decode($raw_review, true);
            if (is_array($decoded)) {
                $review        = $decoded;
                $reviewed_text = (string) ($_POST['reviewed_text'] ?? '');
            }
        }

        $id = Update::publish($incident, $audience, $content, $review, $reviewed_text);

        $respond(['ok' => $id !== false, 'reload' => true]);

    case 'promise':
        if (!$can_edit) {
            $respond(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $minutes = (int) ($_POST['minutes'] ?? 0);
        $when    = $minutes > 0
            ? date('Y-m-d H:i:s', time() + ($minutes * MINUTE_TIMESTAMP))
            : null;

        $respond(['ok' => $incident->promise($when), 'reload' => true]);

    case 'state':
        if (!$can_edit) {
            $respond(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $ok = $incident->setState(
            (string) ($_POST['state'] ?? ''),
            (string) ($_POST['outcome'] ?? (string) $incident->fields['outcome'])
        );

        $respond(['ok' => $ok, 'reload' => true]);

    case 'post':
        // The composer's one submit: publish, then state, then promise — each
        // only when it changed. The three operations stay what they were;
        // this case is one authorisation, one validation pass, one reload.
        //
        // Everything refusable is refused *before* anything mutates, so a "no"
        // is a sentence the composer can show inline rather than a page that
        // reloads half-changed. The one failure mode that cannot be
        // pre-validated — a write failing mid-sequence — answers with a
        // sentence that says exactly what did happen, and reloads, because at
        // that point the page is stale whether we like it or not.
        if (!$can_edit) {
            $respond(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $content  = trim((string) ($_POST['content'] ?? ''));
        $audience = (string) ($_POST['audience'] ?? Update::INTERNAL);
        $state    = (string) ($_POST['state'] ?? '');
        $next     = (string) ($_POST['next'] ?? 'keep');
        $outcome  = trim((string) ($_POST['outcome'] ?? ''));

        if ($state !== '' && !array_key_exists($state, Incident::states())) {
            $respond(['ok' => false, 'error' => 'unknown state'], 400);
        }
        $state_change = $state !== '' && $state !== (string) $incident->fields['state'];

        $promise_change = $next !== 'keep';
        $promise_when   = null;
        if ($promise_change && $next !== 'none') {
            $minutes = (int) $next;
            if ($minutes <= 0) {
                $respond(['ok' => false, 'error' => 'unknown promise'], 400);
            }
            $promise_when = date('Y-m-d H:i:s', time() + ($minutes * MINUTE_TIMESTAMP));
        }

        // A post that would do nothing is a mistake worth saying no to, not a
        // silent success the author walks away from believing something landed.
        if ($content === '' && !$state_change && !$promise_change) {
            $respond([
                'ok'    => false,
                'error' => __('Nothing to post. Write an update, move the state, or promise the '
                    . 'next update.', 'glpimajor'),
            ], 400);
        }

        // A state-only or promise-only post is legitimate — but not to the
        // customer. Customer-visible means something lands on a status page,
        // and an empty something is not a thing to publish.
        if ($audience === Update::CUSTOMER && $content === '') {
            $respond([
                'ok'    => false,
                'error' => __('A customer-visible update needs something to say. Write it, or '
                    . 'post internally.', 'glpimajor'),
            ], 400);
        }

        if ($state_change && $state === Incident::RESOLVED) {
            // Promising a further update while resolving contradicts itself —
            // and setState() would clear the promise this same post just made.
            if ($promise_when !== null) {
                $respond([
                    'ok'    => false,
                    'error' => __('A resolved incident promises no further updates. Resolve, or '
                        . 'promise — not both.', 'glpimajor'),
                ], 400);
            }

            // Mirrors setState()'s own refusal, moved ahead of the publish so
            // an empty outcome refuses the whole post rather than publishing
            // the note and then balking at the state.
            if ($outcome === '') {
                $outcome = trim((string) ($incident->fields['outcome'] ?? ''));
            }
            if ($outcome === '' && Settings::flag('pir_required')) {
                $respond([
                    'ok'    => false,
                    'error' => __('Say what happened before resolving. One or two sentences is '
                        . 'enough — this is the only moment anyone reliably remembers.', 'glpimajor'),
                ], 400);
            }
        }

        $published = false;
        if ($content !== '') {
            // The review payload rides along exactly as it does on 'publish',
            // so "Review before publishing" keeps its meaning on the combined
            // submit.
            $review        = null;
            $reviewed_text = null;
            $raw_review    = (string) ($_POST['review'] ?? '');
            if ($raw_review !== '') {
                $decoded = json_decode($raw_review, true);
                if (is_array($decoded)) {
                    $review        = $decoded;
                    $reviewed_text = (string) ($_POST['reviewed_text'] ?? '');
                }
            }

            $id = Update::publish($incident, $audience, $content, $review, $reviewed_text);
            if ($id === false) {
                $respond([
                    'ok'    => false,
                    'error' => __('The update could not be published. Nothing was changed.', 'glpimajor'),
                ], 500);
            }
            $published = true;
        }

        if ($state_change && !$incident->setState($state, $outcome)) {
            $respond([
                'ok'     => false,
                'error'  => $published
                    ? __('The update was published, but the state change failed.', 'glpimajor')
                    : __('The state change failed. Nothing was changed.', 'glpimajor'),
                'reload' => $published,
            ], 500);
        }

        if ($promise_change && !$incident->promise($promise_when)) {
            $respond([
                'ok'     => false,
                'error'  => __('The next-update promise failed to save; everything before it in '
                    . 'this post went through.', 'glpimajor'),
                'reload' => $published || $state_change,
            ], 500);
        }

        $respond(['ok' => true, 'reload' => true]);

    // ---------------------------------------------------- public post-mortem
    //
    // Draft saves are allowed while the incident is open; Postmortem::publish()
    // itself refuses a blank text and an unresolved incident, and its refusals
    // are sentences written for the panel to show inline — validate-before-
    // mutate, exactly as the composer's post.
    //
    // `draft` is the text the AI assist produced this session, sent back the
    // way the composer sends `reviewed_text`: so "the published account
    // originated as a model's draft" is recorded by evidence rather than by
    // asking the author.

    case 'pm-save':
        if (!$can_edit) {
            $respond(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $ok = Postmortem::save(
            $incident,
            (string) ($_POST['content'] ?? ''),
            trim((string) ($_POST['draft'] ?? '')) !== ''
        );

        $respond(['ok' => $ok]);

    case 'pm-publish':
        if (!$can_edit) {
            $respond(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $refusal = Postmortem::publish(
            $incident,
            (string) ($_POST['content'] ?? ''),
            trim((string) ($_POST['draft'] ?? '')) !== ''
        );

        if ($refusal !== null) {
            $respond(['ok' => false, 'error' => $refusal], 400);
        }

        $respond(['ok' => true, 'reload' => true]);

    case 'pm-retract':
        if (!$can_edit) {
            $respond(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $respond(['ok' => Postmortem::retract($incident), 'reload' => true]);

    case 'pm-draft':
        if (!$can_edit) {
            $respond(['ok' => false, 'error' => 'forbidden'], 403);
        }

        // The draft goes to the caller's textarea, never to storage and never
        // to the page: the model proposes, a human edits and publishes.
        $respond(Postmortem::draft($incident));

    // ------------------------------------------------------------------ PIR

    case 'pir-save':
    case 'pir-complete':
    case 'pir-unlock':
    case 'pir-lock':
    case 'action-add':
    case 'action-delete':
    case 'push':
        if (!$can_edit) {
            $respond(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $pir = Pir::forIncident($incidents_id, $entities_id);
        if ($pir === null) {
            $respond(['ok' => false, 'error' => 'no review'], 500);
        }

        $pirs_id = (int) $pir['id'];

        switch ($action) {
            case 'pir-save':
                $respond(['ok' => Pir::save($pirs_id, [
                    'what_happened' => (string) ($_POST['what_happened'] ?? ''),
                    'impact'        => (string) ($_POST['impact'] ?? ''),
                    'root_cause'    => (string) ($_POST['root_cause'] ?? ''),
                    'problems_id'   => (int) ($_POST['problems_id'] ?? 0),
                ])]);

            case 'pir-complete':
                $respond(['ok' => Pir::complete($pirs_id), 'reload' => true]);

            case 'pir-unlock':
            case 'pir-lock':
                $respond([
                    'ok'     => Pir::setUnlocked($pirs_id, $action === 'pir-unlock'),
                    'reload' => true,
                ]);

            case 'action-add':
                $id = PirAction::add(
                    $pirs_id,
                    (string) ($_POST['content'] ?? ''),
                    (int) ($_POST['users_id_owner'] ?? 0),
                    (string) ($_POST['due_date'] ?? '') ?: null
                );

                $respond(['ok' => $id !== false, 'reload' => true]);

            case 'action-delete':
                $target = PirAction::byId((int) ($_POST['target'] ?? 0));
                if ($target === null || (int) $target['plugin_glpimajor_pirs_id'] !== $pirs_id) {
                    $respond(['ok' => false, 'error' => 'forbidden'], 403);
                }

                $respond(['ok' => PirAction::delete((int) $target['id']), 'reload' => true]);

            case 'push':
                if (!Settings::flag('improve_push')) {
                    $respond(['ok' => false, 'error' => 'disabled'], 400);
                }

                $target = PirAction::byId((int) ($_POST['target'] ?? 0));
                if ($target === null || (int) $target['plugin_glpimajor_pirs_id'] !== $pirs_id) {
                    $respond(['ok' => false, 'error' => 'forbidden'], 403);
                }

                $candidate = Improve::offer($target, $entities_id);

                $respond([
                    'ok'     => $candidate !== null,
                    'reload' => $candidate !== null,
                    'error'  => $candidate === null
                        ? __('The improvement register is not available, or declined it.', 'glpimajor')
                        : '',
                ]);
        }

        $respond(['ok' => false, 'error' => 'unknown action'], 400);
}

$respond(['ok' => false, 'error' => 'unknown action'], 400);
