// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
/**
 * The few things the incident page and the duplicate banner do without a reload.
 *
 * Deliberately small. Nothing here renders state — every panel is drawn by PHP,
 * and an action that changes what is on the page reloads it rather than patching
 * the DOM. That is not laziness: whether a review is locked, whether a promise
 * is overdue and whether a button should exist at all are all server decisions,
 * and a client that re-derived any of them would eventually disagree with the
 * server about what a technician is allowed to do.
 *
 * The one exception is the AI review's output, which is transient — it is shown,
 * acted on or ignored, and never persisted until Publish.
 */
(function () {
    'use strict';

    /** POST to the plugin's endpoint. Returns the decoded body, or null. */
    async function call(endpoint, csrf, payload) {
        const body = new URLSearchParams(payload);

        let response;
        try {
            response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    // The header form of the token is *preserved* by GLPI's CSRF
                    // listener rather than consumed, which is what lets one page
                    // make several of these without collecting a fresh token
                    // between each.
                    'X-Glpi-Csrf-Token': csrf,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString(),
                credentials: 'same-origin'
            });
        } catch (e) {
            return null;
        }

        try {
            return await response.json();
        } catch (e) {
            return null;
        }
    }

    /** Say something went wrong rather than leaving a button that did nothing. */
    function complain(result) {
        const message = (result && result.error)
            ? result.error
            : 'That did not work. Nothing was changed.';
        window.alert(message);
    }

    // ------------------------------------------------------------- the offer

    function wireOffer(root) {
        const button = root.querySelector('[data-glpimajor-action="attach"]');
        if (!button) {
            return;
        }

        button.addEventListener('click', async function () {
            button.disabled = true;

            const result = await call(
                root.dataset.glpimajorEndpoint,
                root.dataset.glpimajorCsrf,
                {
                    action: 'attach',
                    incidents_id: root.dataset.glpimajorOffer,
                    tickets_id: root.dataset.glpimajorTicket
                }
            );

            if (result && result.ok) {
                window.location.reload();
                return;
            }

            button.disabled = false;
            complain(result);
        });
    }

    // ------------------------------------------------------- the incident page

    function wireView(root) {
        const endpoint = root.dataset.glpimajorEndpoint;
        const csrf = root.dataset.glpimajorCsrf;
        const incident = root.dataset.glpimajorIncident;

        const textarea = root.querySelector('[data-glpimajor-update]');
        const reviewOut = root.querySelector('[data-glpimajor-review]');
        const postError = root.querySelector('[data-glpimajor-post-error]');

        // The composer's inline refusal line. The server validates before it
        // mutates, so a refused post changed nothing and deserves a sentence
        // in place, not an alert and not a reload.
        function refuse(message) {
            if (!postError) {
                window.alert(message);
                return;
            }
            postError.textContent = message;
            postError.hidden = false;
        }

        // The post-mortem panel's pieces. `pmDraft` is the AI draft exactly as
        // it was produced, remembered so save/publish can send it back for the
        // provenance record — the same mechanism as `reviewedText` below:
        // origin is established by evidence, not by asking the author.
        const pmField = root.querySelector('[data-glpimajor-pm-content]');
        const pmError = root.querySelector('[data-glpimajor-pm-error]');
        let pmDraft = null;

        function refusePm(message) {
            if (!pmError) {
                window.alert(message);
                return;
            }
            pmError.textContent = message;
            pmError.hidden = false;
        }

        // The state pills. The hidden [data-glpimajor-state] input keeps the
        // 0.1.1 contract with the server — '' means keep, anything else is
        // the target — while the pills are what a person actually reads and
        // presses. Choosing the current pill again is "keep"; choosing
        // Resolved reveals the outcome field, anything else hides it.
        const stateInput = root.querySelector('input[data-glpimajor-state]');
        const statePills = Array.from(root.querySelectorAll('[data-glpimajor-state-pill]'));
        const currentPill = root.querySelector('[data-glpimajor-current="1"]');
        const currentState = currentPill ? currentPill.dataset.glpimajorStatePill : '';
        const outcomeBlock = root.querySelector('[data-glpimajor-outcome-block]');

        function selectState(value) {
            const shown = value || currentState;
            statePills.forEach(function (pill) {
                pill.classList.toggle('is-selected', pill.dataset.glpimajorStatePill === shown);
            });
            if (stateInput) {
                stateInput.value = value;
            }
            if (outcomeBlock) {
                outcomeBlock.hidden = value !== 'resolved';
            }
        }

        statePills.forEach(function (pill) {
            pill.addEventListener('click', function () {
                const target = pill.dataset.glpimajorStatePill;
                selectState(target === currentState ? '' : target);
            });
        });

        // The cockpit's transition buttons land here: preselect and focus.
        root.selectComposerState = function (state) {
            selectState(state === currentState ? '' : state);
        };

        // The review the author was shown, and the text it was shown for. Both
        // are sent with Publish so "was it heeded" is decided by comparing the
        // two rather than by asking the author.
        let lastReview = null;
        let reviewedText = null;

        const snippet = root.querySelector('[data-glpimajor-snippet]');
        if (snippet && textarea) {
            snippet.addEventListener('change', function () {
                if (!snippet.value) {
                    return;
                }
                // Appended rather than substituted when there is already text:
                // silently discarding a half-written update because somebody
                // opened the wrong dropdown is unforgivable at 03:00.
                textarea.value = textarea.value.trim()
                    ? textarea.value.trim() + '\n\n' + snippet.value
                    : snippet.value;

                const option = snippet.options[snippet.selectedIndex];
                const audience = option && option.dataset ? option.dataset.audience : null;
                if (audience) {
                    const radio = root.querySelector(
                        'input[name="glpimajor_audience"][value="' + audience + '"]'
                    );
                    if (radio) {
                        radio.checked = true;
                    }
                }
            });
        }

        function audience() {
            const checked = root.querySelector('input[name="glpimajor_audience"]:checked');
            return checked ? checked.value : 'internal';
        }

        function renderReview(result) {
            if (!reviewOut) {
                return;
            }

            reviewOut.hidden = false;

            if (!result || !result.ok) {
                reviewOut.textContent = (result && result.error)
                    ? result.error
                    : 'The review could not be completed.';
                return;
            }

            if (!result.findings) {
                reviewOut.textContent = 'Nothing flagged. Your judgement still decides.';
                return;
            }

            reviewOut.textContent = '';

            const add = function (heading, items) {
                if (!items || !items.length) {
                    return;
                }
                const h = document.createElement('strong');
                h.textContent = heading;
                reviewOut.appendChild(h);
                const ul = document.createElement('ul');
                items.forEach(function (item) {
                    const li = document.createElement('li');
                    // textContent throughout: these strings came back from a
                    // model, and a model's output is never markup.
                    li.textContent = item;
                    ul.appendChild(li);
                });
                reviewOut.appendChild(ul);
            };

            add('Jargon a reader may not follow:', result.jargon);
            add('Detail that may not belong outside:', result.internal);

            if (result.missing_next_step) {
                const p = document.createElement('p');
                p.textContent = 'It does not say what happens next, or when they will hear again.';
                reviewOut.appendChild(p);
            }

            if (result.notes) {
                const p = document.createElement('p');
                p.textContent = result.notes;
                reviewOut.appendChild(p);
            }
        }

        root.addEventListener('click', async function (event) {
            const button = event.target.closest('[data-glpimajor-action]');
            if (!button || !root.contains(button)) {
                return;
            }

            const action = button.dataset.glpimajorAction;
            const payload = { action: action, incidents_id: incident };

            switch (action) {
                case 'review':
                    if (!textarea || !textarea.value.trim()) {
                        return;
                    }
                    payload.content = textarea.value;
                    break;

                case 'post': {
                    // One submit, three possible effects. The server decides
                    // what actually changed and refuses what does not add up —
                    // the only client-side gate is the publish confirmation,
                    // because there is no unsend.
                    const content = textarea ? textarea.value.trim() : '';
                    if (content && audience() === 'customer'
                        && !window.confirm('Publish this to the public status page?')) {
                        return;
                    }
                    if (postError) {
                        postError.hidden = true;
                    }
                    payload.content = content;
                    payload.audience = audience();
                    payload.state = stateInput ? stateInput.value : '';
                    const nextSelect = root.querySelector('[data-glpimajor-next]');
                    payload.next = nextSelect ? nextSelect.value : 'keep';
                    const outcome = root.querySelector('[data-glpimajor-outcome]');
                    payload.outcome = outcome ? outcome.value : '';
                    if (lastReview) {
                        payload.review = JSON.stringify(lastReview);
                        payload.reviewed_text = reviewedText || '';
                    }
                    break;
                }

                case 'promise':
                    payload.minutes = button.dataset.glpimajorMinutes || '60';
                    break;

                case 'pm-save':
                case 'pm-publish':
                    // Publishing asks first, as every public act on
                    // this page does — there is no unsend.
                    if (action === 'pm-publish'
                        && !window.confirm('Publish this post-mortem to the public status page?')) {
                        return;
                    }
                    if (pmError) {
                        pmError.hidden = true;
                    }
                    payload.content = pmField ? pmField.value : '';
                    if (pmDraft) {
                        payload.draft = pmDraft;
                    }
                    break;

                case 'pm-retract':
                case 'pm-draft':
                    if (pmError) {
                        pmError.hidden = true;
                    }
                    break;

                case 'detach':
                    payload.tickets_id = button.dataset.glpimajorTicket;
                    break;

                case 'pir-save':
                case 'pir-complete': {
                    root.querySelectorAll('[data-glpimajor-pir-field]').forEach(function (field) {
                        payload[field.dataset.glpimajorPirField] = field.value;
                    });
                    const problem = root.querySelector('[name="glpimajor_problems_id"]');
                    payload.problems_id = problem ? problem.value : 0;

                    // Complete takes the current text with it, so an author who
                    // types and presses Complete does not lose the typing.
                    if (action === 'pir-complete') {
                        const saved = await call(endpoint, csrf,
                            Object.assign({}, payload, { action: 'pir-save' }));
                        if (!saved || !saved.ok) {
                            complain(saved);
                            return;
                        }
                    }
                    break;
                }

                case 'action-add': {
                    const text = root.querySelector('[data-glpimajor-action-text]');
                    const owner = root.querySelector('[name="glpimajor_action_owner"]');
                    const due = root.querySelector('[data-glpimajor-action-due]');
                    if (!text || !text.value.trim()) {
                        return;
                    }
                    payload.content = text.value;
                    payload.users_id_owner = owner ? owner.value : 0;
                    payload.due_date = due ? due.value : '';
                    break;
                }

                case 'action-delete':
                case 'push':
                    payload.target = button.dataset.glpimajorTarget;
                    break;

                default:
                    break;
            }

            button.disabled = true;
            const result = await call(endpoint, csrf, payload);
            button.disabled = false;

            if (action === 'review') {
                lastReview = (result && result.ok) ? result : null;
                reviewedText = textarea ? textarea.value : null;
                renderReview(result);
                return;
            }

            if (action === 'pm-draft') {
                // The draft lands in the textarea for a human to edit;
                // publishing stays a separate, human act. Replacing typed text
                // asks first — discarding half a written post-mortem because
                // somebody pressed the wrong button is the snippet rule again.
                if (result && result.ok && result.content) {
                    if (pmField) {
                        if (pmField.value.trim()
                            && !window.confirm('Replace the current text with the AI draft?')) {
                            return;
                        }
                        pmField.value = result.content;
                        pmField.focus();
                    }
                    pmDraft = result.content;
                } else {
                    refusePm((result && result.error)
                        ? result.error
                        : 'The draft could not be produced.');
                }
                return;
            }

            if (result && result.ok) {
                if (result.reload) {
                    window.location.reload();
                } else if (action === 'pir-save' || action === 'pm-save') {
                    var idleLabel = action === 'pir-save' ? 'Save' : 'Save draft';
                    button.textContent = 'Saved';
                    window.setTimeout(function () {
                        button.textContent = idleLabel;
                    }, 1500);
                }
                return;
            }

            if (action === 'pm-save' || action === 'pm-publish' || action === 'pm-retract') {
                // A refused publish changed nothing; the "no" is a sentence in
                // the panel, mirroring the composer's inline refusals.
                refusePm((result && result.error)
                    ? result.error
                    : 'That did not work. Nothing was changed.');
                return;
            }

            if (action === 'post') {
                // A refusal changed nothing and reads as a sentence in the
                // composer. The one exception is a mid-sequence failure the
                // server could not pre-validate: something *did* change, the
                // response says to reload, and the alert has to come first or
                // the reload eats the explanation.
                const message = (result && result.error)
                    ? result.error
                    : 'That did not work. Nothing was changed.';
                if (result && result.reload) {
                    window.alert(message);
                    window.location.reload();
                } else {
                    refuse(message);
                }
                return;
            }

            complain(result);
        });
    }

    // ------------------------------------------------------- the cockpit

    /** "3h 40m" / "12m" / "less than a minute" — View::duration()'s twin. */
    function durationText(seconds) {
        const minutes = Math.floor(Math.max(0, seconds) / 60);
        if (minutes < 1) {
            return 'less than a minute';
        }
        if (minutes < 60) {
            return minutes + 'm';
        }
        return Math.floor(minutes / 60) + 'h ' + (minutes % 60) + 'm';
    }

    /**
     * The header above the incident form: the live duration, and the
     * transition buttons.
     *
     * Neither renders state. The duration re-derives presentation from a
     * server-stamped epoch — the same number, just not frozen at page load —
     * and the buttons only *drive the composer*: pre-select the state pill,
     * reveal what it reveals, put the cursor in the note field. The state
     * change itself still travels through the composer's one Post, so there
     * is exactly one path to the server and one set of refusals.
     */
    function wireCockpit(root) {
        const since = root.querySelector('[data-glpimajor-since]');
        if (since && since.dataset.glpimajorSince) {
            const declared = parseInt(since.dataset.glpimajorSince, 10);
            window.setInterval(function () {
                since.textContent = 'Ongoing for '
                    + durationText(Math.floor(Date.now() / 1000) - declared);
            }, 30000);
        }

        root.addEventListener('click', function (event) {
            const button = event.target.closest('[data-glpimajor-goto]');
            if (!button) {
                return;
            }

            const view = document.querySelector('.glpimajor-view');
            if (!view || typeof view.selectComposerState !== 'function') {
                return;
            }

            view.selectComposerState(button.dataset.glpimajorGoto);

            const menu = button.closest('details');
            if (menu) {
                menu.open = false;
            }

            const composer = view.querySelector('.glpimajor-composer');
            const note = view.querySelector('[data-glpimajor-update]');
            if (composer) {
                composer.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            if (note) {
                note.focus({ preventScroll: true });
            }
        });
    }

    // ----------------------------------------------------------- declaring

    /**
     * Give the declare control a form to belong to.
     *
     * It cannot have one of its own in the markup: the banner is rendered by
     * PRE_ITIL_INFO_SECTION, which puts it *inside* GLPI's `<form>` for the
     * ticket, and
     * HTML has no nested forms — the parser drops the inner tag and hands its
     * fields to the outer one. A `<form>` written there is not in the document
     * the browser builds, so the Declare button submitted the ticket to
     * `front/ticket.form.php`, which does not know what `declare=1` is. Nothing
     * happened and nothing said so.
     *
     * So the fields carry `form="glpimajor-declare-form"` from the server, and
     * this creates that form — appended to `<body>`, where no other form
     * encloses it. Re-setting the attribute afterwards is not superstition: it
     * forces the browser to recompute each field's form owner now that the
     * element it names exists.
     */
    function wireDeclare(root) {
        const id = root.dataset.glpimajorForm;
        const action = root.dataset.glpimajorDeclare;
        if (!id || !action) {
            return;
        }

        let form = document.getElementById(id);
        if (!form) {
            form = document.createElement('form');
            form.id = id;
            form.method = 'post';
            form.action = action;
            document.body.appendChild(form);
        }

        // Not the row's own collapse toggle: that is chrome, not a field, and
        // handing it a form owner only invites a future `type` change to
        // submit the declaration by accident.
        root.querySelectorAll('input, select, textarea, button:not([data-bs-toggle])').forEach(function (field) {
            field.setAttribute('form', id);
        });
    }

    /**
     * Wire whatever is on the page now. Safe to call again, and it is.
     *
     * Each root is marked once, because this runs every time the document
     * changes and a second listener on the same button would fire two attach
     * requests for one click.
     */
    function wireAll() {
        const roots = [
            ['[data-glpimajor-declare]', wireDeclare],
            ['[data-glpimajor-offer]', wireOffer],
            ['.glpimajor-view', wireView],
            ['.glpimajor-cockpit', wireCockpit]
        ];

        roots.forEach(function (pair) {
            document.querySelectorAll(pair[0]).forEach(function (root) {
                if (root.dataset.glpimajorWired) {
                    return;
                }
                root.dataset.glpimajorWired = '1';
                pair[1](root);
            });
        });
    }

    /**
     * Keep watching, because DOMContentLoaded is too early.
     *
     * GLPI 11 renders a ticket's form into the page *after* the document is
     * parsed, so at DOMContentLoaded neither banner exists yet: wiring once, at
     * boot, found nothing and left both the Declare control and the duplicate
     * offer's Attach button as decoration that did nothing when clicked. The
     * observer costs one guarded pass per animation frame in which the document
     * changed, and it is the difference between the plugin's two entry points
     * working and not.
     */
    function watch() {
        if (!window.MutationObserver) {
            return;
        }

        let queued = false;
        const observer = new MutationObserver(function () {
            if (queued) {
                return;
            }
            queued = true;
            window.requestAnimationFrame(function () {
                queued = false;
                wireAll();
            });
        });

        observer.observe(document.documentElement, { childList: true, subtree: true });
    }

    function boot() {
        wireAll();
        watch();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
