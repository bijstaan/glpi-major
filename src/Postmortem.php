<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

/**
 * The public post-mortem: the account the customer reads when it is over.
 *
 * Deliberately a separate thing from the PIR. The post-incident review is the
 * house being honest with itself — root causes, internal hostnames, what we
 * got wrong — and it must stay blunt to be worth writing. The post-mortem is
 * the same story told across the counter: what happened, what it meant for
 * the customer, what we did, what we are changing. One document cannot be
 * both, because the moment the internal one might be published, people stop
 * writing the truth in it.
 *
 * One row per incident (the UNIQUE key enforces it), draftable from the
 * moment the incident exists — half of the story is best written while it is
 * happening — but **publishable only once the incident is resolved**. A
 * "post-mortem" on an outage that is still going is a contradiction the
 * customer notices immediately: it declares the incident over in the very
 * paragraph the live timeline above it says otherwise. Retract works at any
 * time; taking something off the page must never have a precondition.
 *
 * `ai_drafted` mirrors the update log's `ai_reviewed` honesty: when the text
 * in the box originated as a model's draft, the row says so permanently —
 * "the model drafted it and a human edited and accepted it" is a fact worth
 * keeping, and it is only a fact if it cannot be quietly dropped by a later
 * save. Publishing itself is always a human act: the draft lands in the
 * textarea, never on the page.
 */
final class Postmortem
{
    public const TABLE = 'glpi_plugin_glpimajor_postmortems';

    /** Ceiling on stored prose. Far beyond any post-mortem worth reading. */
    private const MAX_CHARS = 20000;

    /** The one row for an incident, or null — never created on read. */
    public static function forIncident(int $incidents_id): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['plugin_glpimajor_incidents_id' => $incidents_id],
                'LIMIT' => 1,
            ]) as $row
        ) {
            return $row;
        }

        return null;
    }

    /**
     * The published account, or null — what the status page is allowed to see.
     *
     * A row that is marked published but has had its text emptied is treated
     * as unpublished rather than rendered as a blank box; the renderer would
     * refuse it anyway, and agreeing with the renderer here keeps "the page
     * shows it" and "publishedFor() returns it" the same statement.
     */
    public static function publishedFor(int $incidents_id): ?array
    {
        $row = self::forIncident($incidents_id);

        if (
            $row === null
            || (int) ($row['is_published'] ?? 0) !== 1
            || trim((string) ($row['content'] ?? '')) === ''
        ) {
            return null;
        }

        return $row;
    }

    /**
     * Save the draft text. Allowed whenever the incident exists — most of the
     * story is known while it is happening and none of it is known a month
     * later — and saving never touches the published flag: a draft edit is
     * not a publication.
     *
     * `$was_drafted` is true when the text in the box originated from an AI
     * draft in this authoring session (the client sends the fact along, the
     * same way the composer sends the reviewed text back). The flag is
     * sticky on the row: a human editing an AI draft is still working from
     * an AI draft, and honesty about origins does not expire on the first
     * edit.
     */
    public static function save(Incident $incident, string $content, bool $was_drafted): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $incidents_id = (int) $incident->getID();
        if ($incidents_id <= 0) {
            return false;
        }

        $content = mb_substr(trim($content), 0, self::MAX_CHARS);
        $now     = date('Y-m-d H:i:s');
        $row     = self::forIncident($incidents_id);

        if ($row === null) {
            $DB->insert(self::TABLE, [
                'plugin_glpimajor_incidents_id' => $incidents_id,
                'entities_id'                   => (int) $incident->fields['entities_id'],
                'content'                       => $content,
                'is_published'                  => 0,
                'users_id_author'               => (int) \Session::getLoginUserID(),
                'ai_drafted'                    => $was_drafted ? 1 : 0,
                'date_creation'                 => $now,
                'date_mod'                      => $now,
            ]);

            // insert() returns true, not the id; insertId() is where the id is.
            $ok = (int) $DB->insertId() > 0;
        } else {
            $ok = (bool) $DB->update(self::TABLE, [
                'content'         => $content,
                // The last human who shaped the text. The publisher column
                // remembers who put it on the page; this one remembers who
                // wrote what is in the box.
                'users_id_author' => (int) \Session::getLoginUserID(),
                'ai_drafted'      => ($was_drafted || (int) ($row['ai_drafted'] ?? 0) === 1) ? 1 : 0,
                'date_mod'        => $now,
            ], ['id' => (int) $row['id']]);
        }

        if ($ok) {
            Events::record(
                $incidents_id,
                Events::PM_SAVED,
                $was_drafted ? 'from an AI draft' : '',
                (int) $incident->fields['entities_id']
            );

            // A published post-mortem whose text just changed is already on
            // the customer's page in its old wording; a draft is nowhere.
            // Only the former needs the page rewritten — but that path goes
            // through publish(), which refreshes the stamp. A save on a
            // published row is possible only through code, not the UI, so no
            // republish here.
        }

        return $ok;
    }

    /**
     * Publish — put the account on the status page. A human act, always.
     *
     * @return string|null a refusal the panel can print inline, or null on success
     */
    public static function publish(Incident $incident, string $content, bool $was_drafted): ?string
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (trim($content) === '') {
            return __('There is nothing to publish. Write the post-mortem first — or draft it '
                . 'with the assistant and edit.', 'glpimajor');
        }

        if ((string) $incident->fields['state'] !== Incident::RESOLVED) {
            return __('A post-mortem is published once the incident is resolved. Until then it '
                . 'would tell the customer the outage is over while the timeline above it says '
                . 'otherwise.', 'glpimajor');
        }

        if (!self::save($incident, $content, $was_drafted)) {
            return __('The post-mortem could not be saved. Nothing was published.', 'glpimajor');
        }

        $row = self::forIncident((int) $incident->getID());
        if ($row === null) {
            return __('The post-mortem could not be saved. Nothing was published.', 'glpimajor');
        }

        $republish = (int) ($row['is_published'] ?? 0) === 1;

        $ok = (bool) $DB->update(self::TABLE, [
            'is_published'       => 1,
            // Refreshed on every publish, including a re-publish of edited
            // text: the stamp on the page answers "when was what I am reading
            // put here", not "when was the first version put here".
            'published_at'       => date('Y-m-d H:i:s'),
            'users_id_publisher' => (int) \Session::getLoginUserID(),
            'date_mod'           => date('Y-m-d H:i:s'),
        ], ['id' => (int) $row['id']]);

        if (!$ok) {
            return __('The post-mortem was saved but could not be published.', 'glpimajor');
        }

        Events::record(
            (int) $incident->getID(),
            Events::PM_PUBLISHED,
            self::provenance($row, $was_drafted, $republish),
            (int) $incident->fields['entities_id']
        );

        Publisher::onChange(
            (int) $incident->fields['entities_id'],
            $incident->coversSubEntities()
        );

        return null;
    }

    /** Take it off the page. The text stays as a draft; unpublishing is not deletion. */
    public static function retract(Incident $incident): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::forIncident((int) $incident->getID());
        if ($row === null || (int) ($row['is_published'] ?? 0) !== 1) {
            return false;
        }

        $ok = (bool) $DB->update(self::TABLE, [
            'is_published' => 0,
            // The stamp belongs to the published state; a retracted draft has
            // no "published" date to show anybody.
            'published_at' => null,
            'date_mod'     => date('Y-m-d H:i:s'),
        ], ['id' => (int) $row['id']]);

        if ($ok) {
            Events::record(
                (int) $incident->getID(),
                Events::PM_RETRACTED,
                '',
                (int) $incident->fields['entities_id']
            );

            Publisher::onChange(
                (int) $incident->fields['entities_id'],
                $incident->coversSubEntities()
            );
        }

        return $ok;
    }

    // ------------------------------------------------------------- AI draft

    /**
     * Why a draft cannot be offered here, or null when it can.
     *
     * Exactly the review's gate — plugin present, interface intact, provider
     * ready, entity permitted, and the same local switch. One switch for both
     * assists on purpose: they are the same trust decision ("may a model read
     * this incident's record and this entity's text"), and two switches would
     * mean an administrator who turned one off believing they had turned off
     * "the AI" was wrong in a way nothing on screen said.
     */
    public static function refusal(int $entities_id): ?string
    {
        return Review::refusal($entities_id);
    }

    /**
     * Draft a customer-facing post-mortem from the incident's own record.
     *
     * The draft lands in the textarea, never on the page: the model proposes,
     * a human edits and publishes. That is the house charter, and it is also
     * simply the failure mode: a generative model writing directly to a
     * customer about an outage it never saw is the worst tool in the worst
     * place. Reading the record and producing a first draft for a person to
     * correct is where it earns its keep.
     *
     * @return array{ok:bool,content?:string,error?:string}
     */
    public static function draft(Incident $incident): array
    {
        $entities_id = (int) $incident->fields['entities_id'];

        $refusal = self::refusal($entities_id);
        if ($refusal !== null) {
            return ['ok' => false, 'error' => $refusal];
        }

        $prompt_class = 'GlpiPlugin\\Glpiai\\Prompt';
        $client_class = 'GlpiPlugin\\Glpiai\\Client';

        try {
            $prompt = $prompt_class::make(self::material($incident), self::instruction())
                // The quality tier: this is exactly the workload the split
                // exists for — low volume, and the output goes, after a human
                // pass, in front of a customer.
                ->withTier($prompt_class::TIER_QUALITY)
                // The draft itself is ~350 tokens, but reasoning models
                // (Gemini's flash line among them) spend the same output
                // budget on thinking before a word of prose appears — 900
                // was measured cutting a live draft off mid-sentence with
                // the thinking bill paid and the answer half-written.
                ->withMaxTokens(2500);

            // Longer than the review's 30s: nobody is mid-sentence waiting on
            // this, and the quality model is allowed to be the slower one.
            $prompt->timeout = 60;

            $completion = $client_class::complete($prompt, $entities_id);
        } catch (\Throwable $e) {
            // Degrade honestly: a visible "could not draft", never a silent
            // nothing that looks like a button that does not work.
            return [
                'ok'    => false,
                'error' => sprintf(
                    __('The draft could not be produced: %s', 'glpimajor'),
                    $e->getMessage()
                ),
            ];
        }

        $text = trim((string) $completion->text);
        if ($text === '') {
            return [
                'ok'    => false,
                'error' => __('The model returned nothing usable. Write it yourself — the '
                    . 'assembled timeline in the review below has the sequence.', 'glpimajor'),
            ];
        }

        return ['ok' => true, 'content' => mb_substr($text, 0, self::MAX_CHARS)];
    }

    /**
     * Everything the model is given: the incident's own record, and only
     * that. Title, the state journey with times, the outcome, the whole
     * update log (both audiences — the internal notes are what keep the
     * draft factual; the instruction forbids repeating their internals), and
     * the PIR's answers where they exist.
     */
    private static function material(Incident $incident): string
    {
        $incidents_id = (int) $incident->getID();
        $f            = $incident->fields;

        $lines   = [];
        $lines[] = 'Customer-visible incident title: ' . (string) $f['name'];
        $lines[] = 'Declared: ' . (string) ($f['date_declared'] ?? '');
        if ((string) ($f['date_resolved'] ?? '') !== '') {
            $lines[] = 'Resolved: ' . (string) $f['date_resolved'];
            $declared = strtotime((string) ($f['date_declared'] ?? ''));
            $resolved = strtotime((string) $f['date_resolved']);
            if ($declared !== false && $resolved !== false && $resolved > $declared) {
                $lines[] = 'Total duration: ' . round(($resolved - $declared) / 60) . ' minutes';
            }
        } else {
            $lines[] = 'Current state: ' . Incident::stateLabel((string) $f['state'])
                . ' (not yet resolved)';
        }

        if (trim((string) ($f['outcome'] ?? '')) !== '') {
            $lines[] = '';
            $lines[] = 'Outcome, as recorded at resolution: ' . trim((string) $f['outcome']);
        }

        $lines[] = '';
        $lines[] = 'State timeline:';
        $lines[] = '- ' . (string) ($f['date_declared'] ?? '') . ' declared, investigating';
        foreach (StateChange::forIncident($incidents_id) as $change) {
            $lines[] = '- ' . (string) $change['date_creation'] . ' moved to '
                . (string) $change['state_to'] . ' (from ' . (string) $change['state_from'] . ')';
        }

        $updates = array_reverse(Update::forIncident($incidents_id));
        if ($updates !== []) {
            $lines[] = '';
            $lines[] = 'Update log (oldest first; "internal" entries were never shown to the '
                . 'customer and their internal detail must not surface in your draft):';
            foreach (array_slice($updates, -40) as $update) {
                $lines[] = '- [' . (string) $update['audience'] . '] '
                    . (string) $update['date_creation'] . ' (state at the time: '
                    . (string) $update['state_at_time'] . '): '
                    . mb_substr(trim((string) $update['content']), 0, 400);
            }
        }

        $pir = Pir::forIncident($incidents_id, 0, false);
        if ($pir !== null) {
            $answers = [
                'What happened (internal review)' => (string) ($pir['what_happened'] ?? ''),
                'Impact (internal review)'        => (string) ($pir['impact'] ?? ''),
                'Root cause (internal review)'    => (string) ($pir['root_cause'] ?? ''),
            ];
            foreach ($answers as $label => $answer) {
                if (trim($answer) !== '') {
                    $lines[] = '';
                    $lines[] = $label . ': ' . mb_substr(trim($answer), 0, 1500);
                }
            }
        }

        return mb_substr(implode("\n", $lines), 0, 12000);
    }

    private static function instruction(): string
    {
        // Written at the model as the review's instruction is: a colleague
        // with a specific job, not an oracle. The output constraints mirror
        // the status page's renderer exactly — plain prose, blank lines as
        // paragraph breaks, no markup — because the page escapes everything
        // and a draft full of headings would publish as literal hash signs.
        return "You are drafting a public post-mortem for an IT service provider's customer-facing "
             . "status page, from the internal record of a resolved incident. The reader is the "
             . "customer: non-technical, and outside the provider's organisation.\n\n"
             . "Cover, in order: what happened; what it meant for the customer (who could not do "
             . "what, roughly for how long); what we did to restore service; and what we are "
             . "changing so it is less likely to happen again. Write 3 to 5 short paragraphs, "
             . "under 250 words in total.\n\n"
             . "Hard rules:\n"
             . "- Plain prose only. No headings, no bullet points, no markdown, no greetings or "
             . "sign-offs. Separate paragraphs with a blank line.\n"
             . "- Use only facts present in the record. Do not invent causes, durations, or "
             . "remedies. If the record does not say what is changing, say the review is under "
             . "way rather than inventing an improvement.\n"
             . "- Nothing internal may surface: no server or host names, no vendor or product "
             . "names, no staff names, no reference numbers, no IP addresses, and no internal "
             . "team vocabulary.\n"
             . "- No blame, and no speculation stated as fact. Write as \"we\" throughout, in "
             . "calm plain language, and do not promise anything the record does not support.";
    }

    /** The publish event's detail line: what the audit trail says about origins. */
    private static function provenance(array $row, bool $was_drafted, bool $republish): string
    {
        $parts = [];
        if ($republish) {
            $parts[] = 'published text updated';
        }
        if ($was_drafted || (int) ($row['ai_drafted'] ?? 0) === 1) {
            $parts[] = 'text originated as an AI draft';
        }

        return implode('; ', $parts);
    }
}
