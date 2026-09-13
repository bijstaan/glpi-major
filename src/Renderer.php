<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

/**
 * The status page, as one self-contained HTML document.
 *
 * Pure on purpose: an array in, a string out. No database, no session, no
 * globals, no clock of its own. This is the only piece of the plugin whose
 * output a stranger reads, and making it a function is what lets the tests
 * assert "this document is complete, and contains no word from the inside of
 * the business" without standing up a GLPI.
 *
 * It never sees the page's token. The token is the *filename* the publisher
 * writes to, so there is no code path by which it could end up in the markup —
 * a reader who saves this page and mails it on has not handed over the URL.
 *
 * Everything is inlined: styles, the two glyphs, the logo when it is small
 * enough to embed. A page loaded during an outage is not the moment to depend
 * on a second request succeeding.
 *
 * ## It looks like GLPI, without being able to load GLPI's stylesheet
 *
 * The markup uses Tabler's vocabulary — `card`, `card-header`, `card-title`,
 * `card-status-start`, `badge bg-*-lt`, `list-group`, `status-dot`, `alert`,
 * `text-secondary` — and css() below reproduces just enough of Tabler to render
 * those shapes, using the token values GLPI 11 actually ships. So a reader
 * moving between the helpdesk portal and this page sees one product.
 *
 * It cannot simply *link* GLPI's stylesheet, for the same reason it cannot be a
 * logged-in page: the document has to stand alone. It is written to disk once,
 * served without touching PHP's session or the database, and is expected to
 * survive being saved and mailed on. A `<link>` to a build-hashed asset would
 * break on the next GLPI upgrade and add a second request to the one page whose
 * whole job is to work when things are going badly.
 *
 * The palette is GLPI's **stock** one, not the running instance's. A
 * whitelabelled primary colour lives in a stylesheet this page cannot read, and
 * your branding already arrives through {@see Brand} — the logo, the name
 * and the wording.
 *
 * No word from GLPI's vocabulary appears anywhere in the output. Not "ticket",
 * not "entity", not "requester" — a reader does not have any of those, and
 * seeing one tells them they are reading someone's internal tooling.
 */
final class Renderer
{
    /** States, in the order a reader expects to see them progress. */
    public const STATES = [
        Incident::INVESTIGATING,
        Incident::IDENTIFIED,
        Incident::MONITORING,
        Incident::RESOLVED,
    ];

    /**
     * The most resolved incidents the page will list.
     *
     * The publisher already windows history by `status_history_days`; this is
     * the presentation-side bound, so a terrible month cannot grow the
     * document without limit. Everything past this line is dropped and the
     * page says how many it is not showing, rather than silently ending —
     * each entry is only a collapsed line, but its full record still travels
     * inside the <details>, and twenty-five of those is plenty for a page
     * whose job is "what is happening now".
     */
    public const HISTORY_MAX = 25;

    /**
     * @param array{
     *   title?:string,
     *   brand?:array<string,string>,
     *   generated_at?:int,
     *   timezone?:string,
     *   history_days?:int,
     *   incidents?:array<int,array{
     *     title?:string,
     *     state?:string,
     *     started_at?:int,
     *     resolved_at?:int,
     *     updates?:array<int,array{at?:int,state?:string,content?:string}>,
     *     postmortem?:array{content:string,published_at?:int}
     *   }>,
     *   maintenance?:array<int,array<string,mixed>>
     * } $data
     */
    public static function document(array $data): string
    {
        $title = self::str($data['title'] ?? '') !== '' ? self::str($data['title']) : 'Service status';
        $brand = is_array($data['brand'] ?? null) ? $data['brand'] : [];
        $tz    = self::zone(self::str($data['timezone'] ?? ''));
        $now   = (int) ($data['generated_at'] ?? 0);

        $html  = "<!DOCTYPE html>\n";
        $html .= "<html lang=\"en\">\n<head>\n";
        $html .= "<meta charset=\"utf-8\">\n";
        $html .= "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
        // A status page is for the people who were given its address. It is not
        // a thing to be found by searching for the entity's name.
        $html .= "<meta name=\"robots\" content=\"noindex, nofollow\">\n";
        $html .= '<title>' . self::e($title) . "</title>\n";
        $html .= "<style>\n" . self::css() . "</style>\n";
        $html .= "</head>\n<body>\n";

        $html .= self::header($title, $brand);
        $html .= "<main>\n";
        $html .= self::body($data);
        $html .= "</main>\n";
        $html .= self::footer($brand, $now, $tz);
        $html .= "</body>\n</html>\n";

        return $html;
    }

    /**
     * The content, with no page chrome around it.
     *
     * Two surfaces render this: the published file, which wraps it in its own
     * document and its own copy of the stylesheet, and the in-portal view,
     * which drops it into the interface's chrome and lets the real stylesheet
     * style it. Both get the same markup because the markup is the interface's
     * own vocabulary — that is what the conversion to it bought, and the reason
     * this can be one method instead of two that drift.
     *
     * Still pure, and still the same allow-listed array in both cases: a
     * signed-in reader does not become entitled to more than a reader
     * holding the address, so there is no "internal" variant of this and no
     * flag that would produce one.
     */
    public static function body(array $data): string
    {
        $tz       = self::zone(self::str($data['timezone'] ?? ''));
        $now      = (int) ($data['generated_at'] ?? 0);
        $open     = [];
        $resolved = [];

        foreach (($data['incidents'] ?? []) as $incident) {
            if (!is_array($incident)) {
                continue;
            }
            if ((string) ($incident['state'] ?? '') === Incident::RESOLVED) {
                $resolved[] = $incident;
            } else {
                $open[] = $incident;
            }
        }

        $maintenance = array_values(array_filter(
            $data['maintenance'] ?? [],
            static fn($m): bool => is_array($m)
        ));

        $html  = self::summary($open, $maintenance, $now, $tz);
        $html .= "<div class=\"container-narrow\">\n";
        $html .= self::openSection($open, $tz);
        $html .= self::maintenanceSection($maintenance, $tz);
        $html .= self::historySection($resolved, $tz, (int) ($data['history_days'] ?? 30));
        $html .= "</div>\n";

        return $html;
    }

    // ------------------------------------------------------------ sections

    private static function header(string $title, array $brand): string
    {
        $logo = self::str($brand['logo'] ?? '');
        $name = self::str($brand['name'] ?? '');

        $html = "<header class=\"page-header\">\n<div class=\"container-narrow page-header-inner\">\n";

        if ($logo !== '') {
            $alt = $name !== '' ? $name : $title;
            $html .= '<img class="logo" src="' . self::e($logo) . '" alt="' . self::e($alt) . "\">\n";
        } elseif ($name !== '') {
            $html .= '<span class="navbar-brand">' . self::e($name) . "</span>\n";
        }

        $html .= '<h1 class="page-title">' . self::e($title) . "</h1>\n";
        $html .= "</div>\n</header>\n";

        return $html;
    }

    /**
     * The one line somebody refreshing the page is here to read.
     *
     * Above everything, before any detail, and phrased as a state of the
     * service rather than a count of records — "two services are affected" is
     * an answer; "2 open incidents" is a database summary.
     */
    private static function summary(array $open, array $maintenance, int $now, \DateTimeZone $tz): string
    {
        $active = array_values(array_filter(
            $maintenance,
            static fn(array $m): bool => (string) ($m['state'] ?? '') === Maintenance::IN_PROGRESS
        ));

        if ($open === []) {
            $tone = 'ok';
            $line = 'All services are operating normally.';
            $sub  = $active !== []
                ? 'Planned maintenance is in progress — see below.'
                : '';
        } else {
            $worst = self::worst($open);
            $tone  = $worst === Incident::MONITORING ? 'warn' : 'down';
            $line  = count($open) === 1
                ? 'We are working on one service issue.'
                : 'We are working on ' . self::spell(count($open)) . ' service issues.';
            $sub = match ($worst) {
                Incident::MONITORING  => 'A fix is in place and we are watching it.',
                Incident::IDENTIFIED  => 'We know what is wrong and are working on it.',
                default               => 'We are investigating.',
            };
        }

        // Tabler's own alert, in the same shape the rest of the suite emits one:
        // a title line with an icon, and a muted line under it.
        $html  = "<div class=\"container-narrow\">\n";
        $html .= '<div class="alert alert-' . self::alertTone($tone) . '" role="alert">' . "\n";
        $html .= '<h2 class="alert-title">' . self::icon($tone) . self::e($line) . "</h2>\n";
        if ($sub !== '') {
            $html .= '<div class="text-secondary">' . self::e($sub) . "</div>\n";
        }
        $html .= "</div>\n</div>\n";

        return $html;
    }

    private static function openSection(array $open, \DateTimeZone $tz): string
    {
        if ($open === []) {
            return '';
        }

        $html = "<h2 class=\"h3 section-head\">Current issues</h2>\n";
        foreach ($open as $incident) {
            $html .= self::card($incident, $tz);
        }

        return $html;
    }

    private static function maintenanceSection(array $maintenance, \DateTimeZone $tz): string
    {
        $shown = array_values(array_filter(
            $maintenance,
            static fn(array $m): bool => in_array(
                (string) ($m['state'] ?? ''),
                [Maintenance::SCHEDULED, Maintenance::IN_PROGRESS],
                true
            )
        ));

        if ($shown === []) {
            return '';
        }

        $html = "<h2 class=\"h3 section-head\">Planned maintenance</h2>\n";

        foreach ($shown as $window) {
            $state   = (string) ($window['state'] ?? Maintenance::SCHEDULED);
            $badge   = $state === Maintenance::IN_PROGRESS ? 'In progress' : 'Scheduled';
            $tone    = $state === Maintenance::IN_PROGRESS ? 'warn' : 'plan';
            $start   = (int) ($window['start'] ?? 0);
            $end     = (int) ($window['end'] ?? 0);
            $content = self::str($window['content'] ?? '');

            $html .= "<article class=\"card\">\n";
            $html .= "<div class=\"card-header\">\n";
            $html .= '<h3 class="card-title">'
                   . self::e(self::str($window['title'] ?? 'Planned maintenance')) . "</h3>\n";
            $html .= '<span class="badge bg-' . self::alertTone($tone) . '-lt">'
                   . self::e($badge) . "</span>\n";
            $html .= "</div>\n<div class=\"card-body\">\n";

            if ($start > 0) {
                $html .= '<p class="text-secondary">' . self::when($start, $tz);
                if ($end > $start) {
                    $html .= ' &ndash; ' . self::when($end, $tz);
                }
                $html .= "</p>\n";
            }

            if ($content !== '') {
                $html .= '<div class="body">' . self::prose($content) . "</div>\n";
            }

            $html .= "</div>\n</article>\n";
        }

        return $html;
    }

    /**
     * The archive, compressed to one line per incident.
     *
     * The past is context, not news: each resolved incident is its title, its
     * final state and when it was resolved, until the reader asks for more.
     * Native <details>/<summary>, so the page stays a document — expanding
     * needs no script, works printed-to-PDF-badly-but-honestly, and the
     * summary is a real focusable disclosure control that a screen reader
     * announces as one.
     */
    private static function historySection(array $resolved, \DateTimeZone $tz, int $days): string
    {
        if ($resolved === []) {
            return '';
        }

        $days  = max(1, $days);
        $total = count($resolved);
        $shown = array_slice($resolved, 0, self::HISTORY_MAX);

        $html  = '<h2 class="h3 section-head">Recently resolved</h2>' . "\n";
        $html .= '<p class="text-secondary section-note">Issues resolved in the last '
               . self::e(self::spell($days)) . ' days. Select one for the full record.</p>' . "\n";

        foreach ($shown as $incident) {
            $html .= self::pastCard($incident, $tz);
        }

        if ($total > count($shown)) {
            $html .= '<p class="text-secondary section-note">Showing the ' . self::e(self::spell(count($shown)))
                   . ' most recent of ' . self::e(self::spell($total))
                   . ' resolved issues from this period.</p>' . "\n";
        }

        return $html;
    }

    /**
     * One open incident, fully expanded.
     *
     * Somebody arriving during an outage must not have to click to find out
     * what is happening: the whole public record — the post-mortem
     * if one has been published, then the update timeline — is on the page
     * before any interaction.
     */
    private static function card(array $incident, \DateTimeZone $tz): string
    {
        // `card-status-start` is Tabler's own left accent stripe, which is what
        // the hand-rolled `border-left` used to be.
        $html  = "<article class=\"card card-open\">\n";
        $html .= '<div class="card-status-start bg-'
               . self::alertTone(self::tone((string) ($incident['state'] ?? Incident::INVESTIGATING)))
               . "\"></div>\n";
        $html .= self::head($incident);
        $html .= "<div class=\"card-body\">\n";
        $html .= self::record($incident, $tz);
        $html .= "</div>\n</article>\n";

        return $html;
    }

    /**
     * One resolved incident, collapsed to its summary line.
     *
     * Opening it reveals exactly the record an open incident shows — dates,
     * post-mortem first when one exists, then the timeline. The <details>
     * element does the collapsing, so a browser with no script (or a saved
     * copy of the page) behaves identically to a live one.
     */
    private static function pastCard(array $incident, \DateTimeZone $tz): string
    {
        $state = (string) ($incident['state'] ?? Incident::RESOLVED);
        $ended = (int) ($incident['resolved_at'] ?? 0);
        $stamp = $ended > 0 ? $ended : (int) ($incident['started_at'] ?? 0);

        $html  = "<details class=\"card card-past\">\n";
        $html .= "<summary class=\"card-header past-sum\">\n";
        $html .= '<h3 class="card-title">'
               . self::e(self::str($incident['title'] ?? 'Service issue')) . "</h3>\n";
        $html .= '<span class="badge bg-' . self::alertTone(self::tone($state)) . '-lt">'
               . self::e(self::stateLabel($state)) . "</span>\n";
        if ($stamp > 0) {
            $html .= '<span class="past-at text-secondary">' . self::when($stamp, $tz) . "</span>\n";
        }
        $html .= "</summary>\n";
        $html .= "<div class=\"card-body past-body\">\n";
        $html .= self::record($incident, $tz);
        $html .= "</div>\n</details>\n";

        return $html;
    }

    private static function head(array $incident): string
    {
        $state = (string) ($incident['state'] ?? Incident::INVESTIGATING);

        $html  = "<div class=\"card-header\">\n";
        $html .= '<h3 class="card-title">'
               . self::e(self::str($incident['title'] ?? 'Service issue')) . "</h3>\n";
        $html .= '<span class="badge bg-' . self::alertTone(self::tone($state)) . '-lt">'
               . self::e(self::stateLabel($state)) . "</span>\n";
        $html .= "</div>\n";

        return $html;
    }

    /**
     * The body of an incident's entry: the dates, the post-mortem when one
     * has been published, then the whole update history newest-first.
     *
     * The history is the point of the page. A reader who checks back after
     * two hours wants to see what changed while they were away, in order, with
     * the state we believed at each step — not only the latest line, which
     * tells them nothing about whether anyone has been working.
     */
    private static function record(array $incident, \DateTimeZone $tz): string
    {
        $state   = (string) ($incident['state'] ?? Incident::INVESTIGATING);
        $started = (int) ($incident['started_at'] ?? 0);
        $ended   = (int) ($incident['resolved_at'] ?? 0);
        $updates = array_values(array_filter(
            $incident['updates'] ?? [],
            static fn($u): bool => is_array($u)
        ));

        $html = '';

        $meta = [];
        if ($started > 0) {
            $meta[] = 'Started ' . self::when($started, $tz);
        }
        if ($ended > 0) {
            $meta[] = 'Resolved ' . self::when($ended, $tz);
        }
        if ($meta !== []) {
            // Not escaped again. Each part is a literal word plus when(), which
            // escapes what it interpolates and returns a <time> element —
            // passing that through e() a second time printed the tags as text
            // on the one page in this plugin a reader reads.
            $html .= '<p class="meta text-secondary">' . implode(' &middot; ', $meta) . "</p>\n";
        }

        // The finished account of an incident is worth more to a reader than
        // the play-by-play that led to it, so it comes first.
        $html .= self::postmortem($incident, $tz);

        if ($updates === []) {
            // Degrade honestly. An incident with no public update yet
            // says so, rather than rendering an empty box that reads as a page
            // that has stopped working.
            $html .= '<p class="body text-secondary">We are working on this and will post an update shortly.</p>' . "\n";

            return $html;
        }

        // Tabler's list group, and its own status dot for the state marker. An
        // <ol> because the order is the point: this is a chronology, and a
        // screen reader should say so.
        $html .= "<ol class=\"log list-group list-group-flush\">\n";
        foreach ($updates as $update) {
            $at   = (int) ($update['at'] ?? 0);
            $ustate = (string) ($update['state'] ?? $state);
            $html .= "<li class=\"entry list-group-item\">\n";
            $html .= '<div class="entry-head">'
                   . '<span class="status-dot bg-' . self::alertTone(self::tone($ustate)) . '"></span>'
                   . '<span class="entry-state">' . self::e(self::stateLabel($ustate)) . '</span>'
                   . '<span class="entry-at text-secondary">' . self::when($at, $tz) . '</span>'
                   . "</div>\n";
            $html .= '<div class="body">' . self::prose(self::str($update['content'] ?? '')) . "</div>\n";
            $html .= "</li>\n";
        }
        $html .= "</ol>\n";

        return $html;
    }

    /**
     * The post-mortem, when one has been published. This is the seam the
     * authoring side feeds; the contract is exactly:
     *
     *   'postmortem' => [
     *     'content'      => string, // the published text. Plain prose, same
     *                               // rules as an update's content: escaped,
     *                               // blank lines are paragraph breaks.
     *     'published_at' => int,    // unix seconds; 0 or absent hides the stamp
     *   ]
     *
     * Absent key, non-array value, or blank content: nothing renders — no
     * heading, no empty box. No page today carries one; Publisher::gather()
     * will supply the key when the post-mortem authoring feature lands, and
     * "the key is absent" must keep meaning "none has been published".
     */
    private static function postmortem(array $incident, \DateTimeZone $tz): string
    {
        $pm = $incident['postmortem'] ?? null;
        if (!is_array($pm)) {
            return '';
        }

        $content = self::str($pm['content'] ?? '');
        if ($content === '') {
            return '';
        }

        $at = (int) ($pm['published_at'] ?? 0);

        $html  = "<section class=\"pm card card-sm\">\n";
        $html .= "<div class=\"card-header\"><h4 class=\"card-title\">Post-mortem</h4></div>\n";
        $html .= "<div class=\"card-body\">\n";
        if ($at > 0) {
            $html .= '<p class="pm-at text-secondary">Published ' . self::when($at, $tz) . "</p>\n";
        }
        $html .= '<div class="body">' . self::prose($content) . "</div>\n";
        $html .= "</div>\n</section>\n";

        return $html;
    }

    private static function footer(array $brand, int $now, \DateTimeZone $tz): string
    {
        $email = self::str($brand['support_email'] ?? '');
        $phone = self::str($brand['support_phone'] ?? '');
        $note  = self::str($brand['note'] ?? '');
        $name  = self::str($brand['name'] ?? '');

        $html = "<footer class=\"footer\">\n<div class=\"container-narrow\">\n";

        if ($note !== '') {
            $html .= '<p class="foot-note text-secondary">' . self::prose($note) . "</p>\n";
        }

        $contact = [];
        if ($email !== '') {
            $contact[] = '<a href="mailto:' . self::e($email) . '">' . self::e($email) . '</a>';
        }
        if ($phone !== '') {
            $contact[] = '<span>' . self::e($phone) . '</span>';
        }
        if ($contact !== []) {
            $html .= '<p class="foot-contact">Need help? ' . implode(' &middot; ', $contact) . "</p>\n";
        }

        $html .= '<p class="foot-stamp text-secondary">Last updated ' . self::when($now, $tz, true) . "</p>\n";

        if ($name !== '') {
            $html .= '<p class="foot-name">' . self::e($name) . "</p>\n";
        }

        $html .= "</div>\n</footer>\n";

        return $html;
    }

    // ------------------------------------------------------------- helpers

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            Incident::INVESTIGATING => 'Investigating',
            Incident::IDENTIFIED    => 'Identified',
            Incident::MONITORING    => 'Monitoring',
            Incident::RESOLVED      => 'Resolved',
            default                 => 'Investigating',
        };
    }

    private static function tone(string $state): string
    {
        return match ($state) {
            Incident::RESOLVED   => 'ok',
            Incident::MONITORING => 'warn',
            Incident::IDENTIFIED => 'down',
            default              => 'down',
        };
    }

    /** The least-recovered state among the open incidents. */
    private static function worst(array $open): string
    {
        $rank = [
            Incident::INVESTIGATING => 3,
            Incident::IDENTIFIED    => 2,
            Incident::MONITORING    => 1,
        ];

        $worst = Incident::MONITORING;
        $best  = 0;
        foreach ($open as $incident) {
            $state = (string) ($incident['state'] ?? Incident::INVESTIGATING);
            $score = $rank[$state] ?? 3;
            if ($score > $best) {
                $best  = $score;
                $worst = $state;
            }
        }

        return $worst;
    }

    /**
     * A timestamp, as a `<time>` element with a machine-readable value.
     *
     * The offset is spelled out because a reader in a different timezone
     * reading "14:20" has no way to know whether that has happened yet.
     */
    private static function when(int $stamp, \DateTimeZone $tz, bool $with_zone = false): string
    {
        if ($stamp <= 0) {
            return '<span class="unknown">not recorded</span>';
        }

        $dt = (new \DateTimeImmutable('@' . $stamp))->setTimezone($tz);

        $label = $dt->format('j M Y, H:i');
        if ($with_zone) {
            $label .= ' (' . $dt->format('T') . ')';
        }

        return '<time datetime="' . self::e($dt->format(\DateTimeInterface::ATOM)) . '">'
            . self::e($label) . '</time>';
    }

    /**
     * An update's text, as paragraphs.
     *
     * Escaped first, then broken on blank lines. Updates are written in a
     * textarea by somebody under pressure; they are not markup, and treating
     * them as markup during an outage is how a stray angle bracket takes the
     * page down.
     */
    private static function prose(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') {
            return '';
        }

        $out = '';
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            $out .= '<p>' . nl2br(self::e($para), false) . '</p>';
        }

        return $out;
    }

    /** Small numbers read better as words in a sentence a reader is scanning. */
    private static function spell(int $n): string
    {
        return [
            1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five',
            6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten',
        ][$n] ?? (string) $n;
    }

    private static function icon(string $tone): string
    {
        // Inline, because an icon font is a second request and an emoji is a
        // different picture on every platform.
        $paths = [
            'ok'   => '<circle cx="12" cy="12" r="9"/><path d="m8.5 12.2 2.4 2.4 4.6-5"/>',
            'warn' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><path d="M12 16.2v.1"/>',
            'down' => '<circle cx="12" cy="12" r="9"/><path d="m9.2 9.2 5.6 5.6"/><path d="m14.8 9.2-5.6 5.6"/>',
        ];

        return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" fill="none" '
            . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
            . ($paths[$tone] ?? $paths['down'])
            . '</svg>';
    }

    private static function zone(string $name): \DateTimeZone
    {
        if ($name !== '') {
            try {
                return new \DateTimeZone($name);
            } catch (\Throwable) {
                // A misconfigured timezone must not blank the page. UTC is
                // wrong for somebody, and a page is right for everybody.
            }
        }

        return new \DateTimeZone('UTC');
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * The whole stylesheet.
     *
     * Calm, high contrast, one accent per state, and it follows the reader's
     * light/dark preference — somebody checking a status page at 2am should not
     * be handed a white rectangle. System fonts only: a webfont is a request to
     * somewhere else, and this page makes none.
     */
    /**
     * This page's tone words, in Tabler's colour vocabulary.
     *
     * The page speaks in service terms — ok, warn, down, plan — because that is
     * what a reader is reading about. Tabler speaks in success / warning /
     * danger / info. One map, in one place, rather than the two vocabularies
     * being interleaved through the markup.
     */
    private static function alertTone(string $tone): string
    {
        return match ($tone) {
            'ok'   => 'success',
            'warn' => 'warning',
            'plan' => 'info',
            default => 'danger',
        };
    }

    /**
     * The whole stylesheet, inlined.
     *
     * Just enough Tabler to render the components this page uses, with the token
     * values GLPI 11 actually ships — read off a running instance rather than
     * guessed, and the stock palette rather than a whitelabelled one.
     *
     * **The comments in the returned CSS are part of the document.** Everything
     * this method returns is served to a stranger, so the reasoning lives here,
     * in a docblock that is not emitted, and the CSS itself carries only short
     * structural markers. The suite's forbidden-word check reads the whole
     * document including the `<style>` block, and it caught exactly this: an
     * explanatory comment mentioning GLPI put the product's name on a page that
     * must never carry it.
     *
     * For the same reason no comment here contains an HTML tag name — a literal
     * `<summary>` inside a comment is harmless to a browser and makes any
     * tag-balance check on the document meaningless.
     *
     * Dark mode follows the reader's own preference. The page is a standalone
     * file with no way to know what theme the instance is using, and the
     * reader's preference is the only signal it has — the right one for a page
     * somebody opens at two in the morning.
     *
     * ## Every theme-varying colour is a custom property
     *
     * Not a rule inside the media query. A component that states its colour
     * only in `@media (prefers-color-scheme: dark)` loses to any later rule of
     * equal specificity, and since the dark block sits at the top of this
     * stylesheet, *every* light component rule below it is later. The first
     * cut of this did exactly that and rendered every dark-mode banner as a
     * grey slab. The dark block now redefines tokens and nothing else, so
     * source order stops being able to matter.
     *
     * ## The one place the palette is deliberately not the product's
     *
     * Badge and banner backgrounds, sizes, weights and radii are copied
     * exactly. The **label ink** is darkened. The stock pairing is a brand
     * colour on a 10% wash of itself, which measures 2.48:1 for the green and
     * 1.97:1 for the amber against this page's own grounds — well under the
     * 4.5:1 body text needs. That is defensible inside an application, where
     * the reader signed in on a screen they chose and has every other cue
     * around them. It is not defensible here: this page is read by whoever the
     * outage hit, on whatever device is to hand, and the badge is the word that
     * says whether their morning is ruined. Same hue, same saturation, less
     * lightness — near-identical to look at, and legible. Measured after the
     * change: badges 4.53–4.83:1, banner headings 4.50–4.52:1, body ink
     * 9.28–10.31:1, on both the card and the page ground.
     *
     * The dark scheme needs no such treatment — the tints sit on a dark ground
     * and the stock inks already clear the bar — except the red banner heading,
     * which its own wash pulls to 4.10:1 and which is nudged to 4.52:1.
     *
     * The status dots keep the stock colours untouched. A dot is not text and
     * never carries meaning alone: the state is written in words beside it
     * every time, so the dot is decoration that agrees with the label.
     */
    private static function css(): string
    {
        return <<<'CSS'
:root{
  --tblr-body-bg:#f5f7fb; --tblr-body-color:#374151; --tblr-secondary:#606f91;
  --tblr-border-color:rgba(4,32,69,.1); --tblr-bg-surface:#fff;
  --tblr-bg-surface-secondary:#fafbfc;
  --tblr-primary:#206bc4; --tblr-success:#2fb344; --tblr-warning:#f59f00;
  --tblr-danger:#d63939; --tblr-info:#80abe4;
  --tblr-border-radius:6px;
  --tblr-box-shadow-card:rgba(31,41,55,.04) 0 0 4px 0;

  /*
   * Tints, as tokens rather than as rules.
   *
   * A component whose colour is only stated inside a media block is a component
   * whose colour loses to any later rule of equal specificity — which is how the
   * first attempt rendered every dark-mode banner as a grey slab. Components
   * below reference these and never a literal, so the dark block has only to
   * redefine the token and order stops mattering.
   */
  --alert-success-bg:rgba(236,248,238,.55); --alert-success-bd:rgba(47,179,68,.2);
  --alert-warning-bg:rgba(254,246,232,.55); --alert-warning-bd:rgba(245,159,0,.2);
  --alert-danger-bg:rgba(252,236,236,.55);  --alert-danger-bd:rgba(214,57,57,.2);
  --lt-success-bg:rgba(47,179,68,.1);
  --lt-warning-bg:rgba(245,159,0,.1);
  --lt-danger-bg:rgba(214,57,57,.1);
  --lt-info-bg:rgba(128,171,228,.16);

  /* Label inks, darkened for contrast. See the docblock. */
  --lt-success-fg:#207b2f;              /* 4.83:1 on a card, 4.53:1 on the page */
  --lt-warning-fg:#966200;              /* 4.80 / 4.50 */
  --lt-danger-fg:#c72929;               /* 4.81 / 4.51 */
  --lt-info-fg:#2969bf;                 /* 4.82 / 4.51 */
  --alert-success-fg:#228232;           /* 4.51:1 on its own banner */
  --alert-warning-fg:#9d6600;           /* 4.50 */
  --alert-danger-fg:#d32d2d;            /* 4.52 */
}
/* ---------------------------------------------------------- dark preference */
@media (prefers-color-scheme:dark){
  :root{
    --tblr-body-bg:#1a2234; --tblr-body-color:#e5e7eb; --tblr-secondary:#8a94a6;
    --tblr-border-color:rgba(72,110,149,.24); --tblr-bg-surface:#182433;
    --tblr-bg-surface-secondary:#1e2b3d;
    --tblr-primary:#4d8fd6; --tblr-success:#4bbf5c; --tblr-warning:#f7b32b;
    --tblr-danger:#e35d5d; --tblr-info:#80abe4;
    --tblr-box-shadow-card:rgba(0,0,0,.24) 0 0 4px 0;

    --alert-success-bg:rgba(47,179,68,.12); --alert-success-bd:rgba(75,191,92,.28);
    --alert-warning-bg:rgba(245,159,0,.12); --alert-warning-bd:rgba(247,179,43,.28);
    --alert-danger-bg:rgba(214,57,57,.14);  --alert-danger-bd:rgba(227,93,93,.3);
    --lt-success-bg:rgba(75,191,92,.16);  --lt-success-fg:#7fd68c;
    --lt-warning-bg:rgba(247,179,43,.16); --lt-warning-fg:#f7c260;
    --lt-danger-bg:rgba(227,93,93,.18);   --lt-danger-fg:#f09a9a;
    --lt-info-bg:rgba(128,171,228,.18);   --lt-info-fg:#9dc2ec;
    --alert-success-fg:#4bbf5c; --alert-warning-fg:#f7b32b; --alert-danger-fg:#e56a6a;
  }
}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{
  margin:0; background:var(--tblr-body-bg); color:var(--tblr-body-color);
  font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,
              "Helvetica Neue",Arial,sans-serif;
  font-size:.875rem; line-height:1.4285714286;
}
h1,h2,h3,h4{margin:0; font-weight:600; line-height:1.25; color:inherit}
p{margin:0 0 .5rem}
p:last-child{margin-bottom:0}
a{color:var(--tblr-primary)}

/* -------------------------------------------------------------- container */
.container-narrow{max-width:45rem;margin:0 auto;padding:0 1rem}

/* ------------------------------------------------------------- page header */
.page-header{
  background:var(--tblr-bg-surface);
  border-bottom:1px solid var(--tblr-border-color);
  margin-bottom:1.5rem;
}
.page-header-inner{display:flex;align-items:center;gap:.75rem;padding-top:1rem;padding-bottom:1rem}
.logo{max-height:2.5rem;max-width:12rem;width:auto;height:auto;display:block}
.navbar-brand{font-weight:600;font-size:1rem}
.page-title{font-size:1rem;font-weight:500;color:var(--tblr-secondary);margin-left:auto}

/* --------------------------------------------------------------- headings */
.h3{font-size:1rem}
.h4{font-size:.875rem}
.section-head{margin:1.5rem 0 .75rem}
.section-note{margin:-.5rem 0 .75rem;font-size:.8125rem}

/* ------------------------------------------------------------------ alert */
/*
 * Tinted ground, a fifth-opacity border, and text at the ordinary body colour.
 * Only the icon and the headline take the tone — colouring the whole block is
 * the thing that makes a banner look like it belongs to a different product.
 */
.alert{
  position:relative;padding:.75rem 1rem;margin-bottom:1.5rem;
  border:1px solid transparent;border-radius:var(--tblr-border-radius);
  color:var(--tblr-body-color);
}
.alert-title{
  display:flex;align-items:center;gap:.5rem;
  font-size:.875rem;font-weight:600;margin-bottom:.25rem;
}
.alert-success{background:var(--alert-success-bg);border-color:var(--alert-success-bd)}
.alert-warning{background:var(--alert-warning-bg);border-color:var(--alert-warning-bd)}
.alert-danger{background:var(--alert-danger-bg);border-color:var(--alert-danger-bd)}
.alert-success>.alert-title{color:var(--alert-success-fg)}
.alert-warning>.alert-title{color:var(--alert-warning-fg)}
.alert-danger>.alert-title{color:var(--alert-danger-fg)}
.ico{width:1.25rem;height:1.25rem;flex:none}

/* ------------------------------------------------------------------- card */
.card{
  position:relative;background:var(--tblr-bg-surface);
  border:1px solid var(--tblr-border-color);
  border-radius:var(--tblr-border-radius);
  box-shadow:var(--tblr-box-shadow-card);
  margin-bottom:1rem;
}
.card-header{
  display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;
  padding:.75rem 1rem;border-bottom:1px solid var(--tblr-border-color);
  min-height:3.5rem;
}
.card-title{font-size:.875rem;font-weight:600;margin:0;flex:1 1 14rem;overflow-wrap:anywhere}
.card-body{padding:1rem}
.card-sm>.card-header{padding:.5rem .75rem;min-height:0}
.card-sm>.card-body{padding:.75rem}

/* ----------------------------------------------------------- accent bar */
.card-status-start{
  position:absolute;top:0;bottom:0;left:0;width:2px;
  border-top-left-radius:var(--tblr-border-radius);
  border-bottom-left-radius:var(--tblr-border-radius);
}
.bg-success{background:var(--tblr-success)}
.bg-warning{background:var(--tblr-warning)}
.bg-danger{background:var(--tblr-danger)}
.bg-info{background:var(--tblr-info)}

/* ------------------------------------------------------------------ badge */
.badge{
  flex:none;display:inline-block;padding:.25rem .5rem;border-radius:100rem;
  font-size:.75rem;font-weight:500;line-height:1;white-space:nowrap;
}
.bg-success-lt{background:var(--lt-success-bg);color:var(--lt-success-fg)}
.bg-warning-lt{background:var(--lt-warning-bg);color:var(--lt-warning-fg)}
.bg-danger-lt{background:var(--lt-danger-bg);color:var(--lt-danger-fg)}
.bg-info-lt{background:var(--lt-info-bg);color:var(--lt-info-fg)}

/* ------------------------------------------------------------- list group */
.list-group{list-style:none;margin:0;padding:0}
.list-group-item{padding:.75rem 0;border-top:1px solid var(--tblr-border-color)}
.list-group-item:first-child{border-top:0;padding-top:.25rem}
.list-group-item:last-child{padding-bottom:0}
.log{margin-top:.75rem}

/* ------------------------------------------------------------ state dot */
.status-dot{
  width:.5rem;height:.5rem;border-radius:100rem;flex:none;display:inline-block;
}
.entry-head{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.25rem}
.entry-state{font-weight:600}
.entry-at{margin-left:auto;font-size:.8125rem}

/* --------------------------------------------------------------- specifics */
.meta{margin-bottom:.75rem}
.text-secondary{color:var(--tblr-secondary)}
.body{overflow-wrap:anywhere}
.body p{margin:.5em 0}
.body p:first-child{margin-top:0}
.body p:last-child{margin-bottom:0}

.pm{background:var(--tblr-bg-surface-secondary);margin:.75rem 0}

/* ------------------------------------------------- the resolved disclosure */
.past-sum{cursor:pointer;list-style:none}
.past-sum::-webkit-details-marker{display:none}
.past-sum::marker{content:""}
.past-sum::before{
  content:"";width:.45rem;height:.45rem;flex:none;margin-top:-.2rem;
  border-right:2px solid var(--tblr-secondary);border-bottom:2px solid var(--tblr-secondary);
  transform:rotate(-45deg);
}
details[open]>.past-sum::before{transform:rotate(45deg);margin-top:-.35rem}
details.card-past>.past-sum{border-bottom:0}
details[open].card-past>.past-sum{border-bottom:1px solid var(--tblr-border-color)}
.past-sum:hover .card-title{text-decoration:underline}
.past-sum:focus-visible{outline:2px solid var(--tblr-primary);outline-offset:-2px}
.past-at{font-size:.8125rem;margin-left:auto}

/* ----------------------------------------------------------------- footer */
.footer{
  border-top:1px solid var(--tblr-border-color);background:var(--tblr-bg-surface);
  padding:1.5rem 0 2rem;margin-top:2rem;color:var(--tblr-secondary);font-size:.8125rem;
}
.footer p{margin:.25rem 0}
.footer a{color:inherit}
.foot-name{margin-top:.75rem;font-weight:600;color:var(--tblr-body-color);opacity:.7}
main{padding-bottom:1rem}
CSS;
    }
}
