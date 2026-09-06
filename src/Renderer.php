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
 * a customer who saves this page and mails it on has not handed over the URL.
 *
 * Everything is inlined: styles, the two glyphs, the logo when it is small
 * enough to embed. A page loaded during an outage is not the moment to depend
 * on a second request succeeding.
 *
 * No word from GLPI's vocabulary appears anywhere in the output. Not "ticket",
 * not "entity", not "requester" — a customer does not have any of those, and
 * seeing one tells them they are reading someone's internal tooling.
 */
final class Renderer
{
    /** States, in the order a customer expects to see them progress. */
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
        $title    = self::str($data['title'] ?? '') !== '' ? self::str($data['title']) : 'Service status';
        $brand    = is_array($data['brand'] ?? null) ? $data['brand'] : [];
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

        $html  = "<!DOCTYPE html>\n";
        $html .= "<html lang=\"en\">\n<head>\n";
        $html .= "<meta charset=\"utf-8\">\n";
        $html .= "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
        // A status page is for the people who were given its address. It is not
        // a thing to be found by searching for the customer's name.
        $html .= "<meta name=\"robots\" content=\"noindex, nofollow\">\n";
        $html .= '<title>' . self::e($title) . "</title>\n";
        $html .= "<style>\n" . self::css() . "</style>\n";
        $html .= "</head>\n<body>\n";

        $html .= self::header($title, $brand);
        $html .= self::summary($open, $maintenance, $now, $tz);

        $html .= "<main class=\"wrap\">\n";

        $html .= self::openSection($open, $tz);
        $html .= self::maintenanceSection($maintenance, $tz);
        $html .= self::historySection($resolved, $tz, (int) ($data['history_days'] ?? 30));

        $html .= "</main>\n";
        $html .= self::footer($brand, $now, $tz);
        $html .= "</body>\n</html>\n";

        return $html;
    }

    // ------------------------------------------------------------ sections

    private static function header(string $title, array $brand): string
    {
        $logo = self::str($brand['logo'] ?? '');
        $name = self::str($brand['name'] ?? '');

        $html = "<header class=\"top\">\n<div class=\"wrap top-in\">\n";

        if ($logo !== '') {
            $alt = $name !== '' ? $name : $title;
            $html .= '<img class="logo" src="' . self::e($logo) . '" alt="' . self::e($alt) . "\">\n";
        } elseif ($name !== '') {
            $html .= '<span class="wordmark">' . self::e($name) . "</span>\n";
        }

        $html .= '<h1>' . self::e($title) . "</h1>\n";
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

        $html  = '<section class="banner banner-' . $tone . "\">\n<div class=\"wrap\">\n";
        $html .= '<p class="banner-line">' . self::icon($tone) . self::e($line) . "</p>\n";
        if ($sub !== '') {
            $html .= '<p class="banner-sub">' . self::e($sub) . "</p>\n";
        }
        $html .= "</div>\n</section>\n";

        return $html;
    }

    private static function openSection(array $open, \DateTimeZone $tz): string
    {
        if ($open === []) {
            return '';
        }

        $html = "<h2>Current issues</h2>\n";
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

        $html = "<h2>Planned maintenance</h2>\n";

        foreach ($shown as $window) {
            $state   = (string) ($window['state'] ?? Maintenance::SCHEDULED);
            $badge   = $state === Maintenance::IN_PROGRESS ? 'In progress' : 'Scheduled';
            $tone    = $state === Maintenance::IN_PROGRESS ? 'warn' : 'plan';
            $start   = (int) ($window['start'] ?? 0);
            $end     = (int) ($window['end'] ?? 0);
            $content = self::str($window['content'] ?? '');

            $html .= "<article class=\"card\">\n";
            $html .= "<div class=\"card-head\">\n";
            $html .= '<h3>' . self::e(self::str($window['title'] ?? 'Planned maintenance')) . "</h3>\n";
            $html .= '<span class="pill pill-' . $tone . '">' . self::e($badge) . "</span>\n";
            $html .= "</div>\n";

            if ($start > 0) {
                $html .= '<p class="window">' . self::when($start, $tz);
                if ($end > $start) {
                    $html .= ' &ndash; ' . self::when($end, $tz);
                }
                $html .= "</p>\n";
            }

            if ($content !== '') {
                $html .= '<div class="body">' . self::prose($content) . "</div>\n";
            }

            $html .= "</article>\n";
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

        $html  = '<h2>Recently resolved</h2>' . "\n";
        $html .= '<p class="note">Issues resolved in the last '
               . self::e(self::spell($days)) . ' days. Select one for the full record.</p>' . "\n";

        foreach ($shown as $incident) {
            $html .= self::pastCard($incident, $tz);
        }

        if ($total > count($shown)) {
            $html .= '<p class="note more">Showing the ' . self::e(self::spell(count($shown)))
                   . ' most recent of ' . self::e(self::spell($total))
                   . ' resolved issues from this period.</p>' . "\n";
        }

        return $html;
    }

    /**
     * One open incident, fully expanded.
     *
     * Somebody arriving during an outage must not have to click to find out
     * what is happening: the whole customer-visible record — the post-mortem
     * if one has been published, then the update timeline — is on the page
     * before any interaction.
     */
    private static function card(array $incident, \DateTimeZone $tz): string
    {
        $html  = "<article class=\"card card-open\">\n";
        $html .= self::head($incident);
        $html .= self::record($incident, $tz);
        $html .= "</article>\n";

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
        $html .= "<summary class=\"past-sum\">\n";
        $html .= '<h3>' . self::e(self::str($incident['title'] ?? 'Service issue')) . "</h3>\n";
        $html .= '<span class="pill pill-' . self::tone($state) . '">'
               . self::e(self::stateLabel($state)) . "</span>\n";
        if ($stamp > 0) {
            $html .= '<span class="past-at">' . self::when($stamp, $tz) . "</span>\n";
        }
        $html .= "</summary>\n";
        $html .= "<div class=\"past-body\">\n";
        $html .= self::record($incident, $tz);
        $html .= "</div>\n</details>\n";

        return $html;
    }

    private static function head(array $incident): string
    {
        $state = (string) ($incident['state'] ?? Incident::INVESTIGATING);

        $html  = "<div class=\"card-head\">\n";
        $html .= '<h3>' . self::e(self::str($incident['title'] ?? 'Service issue')) . "</h3>\n";
        $html .= '<span class="pill pill-' . self::tone($state) . '">'
               . self::e(self::stateLabel($state)) . "</span>\n";
        $html .= "</div>\n";

        return $html;
    }

    /**
     * The body of an incident's entry: the dates, the post-mortem when one
     * has been published, then the whole update history newest-first.
     *
     * The history is the point of the page. A customer who checks back after
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
            // on the one page in this plugin a customer reads.
            $html .= '<p class="meta">' . implode(' &middot; ', $meta) . "</p>\n";
        }

        // The finished account of an incident is worth more to a reader than
        // the play-by-play that led to it, so it comes first.
        $html .= self::postmortem($incident, $tz);

        if ($updates === []) {
            // Degrade honestly. An incident with no customer-facing update yet
            // says so, rather than rendering an empty box that reads as a page
            // that has stopped working.
            $html .= '<p class="body empty">We are working on this and will post an update shortly.</p>' . "\n";

            return $html;
        }

        $html .= "<ol class=\"log\">\n";
        foreach ($updates as $update) {
            $at   = (int) ($update['at'] ?? 0);
            $ustate = (string) ($update['state'] ?? $state);
            $html .= "<li class=\"entry\">\n";
            $html .= '<div class="entry-head">'
                   . '<span class="dot dot-' . self::tone($ustate) . '"></span>'
                   . '<span class="entry-state">' . self::e(self::stateLabel($ustate)) . '</span>'
                   . '<span class="entry-at">' . self::when($at, $tz) . '</span>'
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

        $html  = "<section class=\"pm\">\n";
        $html .= "<h4>Post-mortem</h4>\n";
        if ($at > 0) {
            $html .= '<p class="pm-at">Published ' . self::when($at, $tz) . "</p>\n";
        }
        $html .= '<div class="body">' . self::prose($content) . "</div>\n";
        $html .= "</section>\n";

        return $html;
    }

    private static function footer(array $brand, int $now, \DateTimeZone $tz): string
    {
        $email = self::str($brand['support_email'] ?? '');
        $phone = self::str($brand['support_phone'] ?? '');
        $note  = self::str($brand['note'] ?? '');
        $name  = self::str($brand['name'] ?? '');

        $html = "<footer class=\"foot\">\n<div class=\"wrap\">\n";

        if ($note !== '') {
            $html .= '<p class="foot-note">' . self::prose($note) . "</p>\n";
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

        $html .= '<p class="foot-stamp">Last updated ' . self::when($now, $tz, true) . "</p>\n";

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
     * The offset is spelled out because a customer in a different timezone
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

    /** Small numbers read better as words in a sentence a customer is scanning. */
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
    private static function css(): string
    {
        return <<<'CSS'
:root{
  --bg:#f6f7f9; --card:#fff; --ink:#1b2430; --muted:#5b6675; --line:#e3e7ec;
  --ok:#1f7a4d; --ok-bg:#e8f5ee; --warn:#8a5a00; --warn-bg:#fdf2dc;
  --down:#a32b2b; --down-bg:#fbeaea; --plan:#37507a; --plan-bg:#eaeff8;
}
@media (prefers-color-scheme:dark){
  :root{
    --bg:#11151b; --card:#171d25; --ink:#e6eaf0; --muted:#9aa6b6; --line:#252d38;
    --ok:#5ec98d; --ok-bg:#132a1f; --warn:#e3b160; --warn-bg:#2c2213;
    --down:#f08b8b; --down-bg:#2e1717; --plan:#9db6e6; --plan-bg:#161f2f;
  }
}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{
  margin:0; background:var(--bg); color:var(--ink);
  font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
}
.wrap{max-width:760px;margin:0 auto;padding:0 20px}
.top{border-bottom:1px solid var(--line);background:var(--card)}
.top-in{display:flex;align-items:center;gap:14px;padding:20px}
.logo{max-height:40px;max-width:190px;width:auto;height:auto;display:block}
.wordmark{font-weight:650;letter-spacing:.01em}
.top h1{font-size:1rem;font-weight:550;color:var(--muted);margin:0 0 0 auto}
.banner{padding:26px 0;border-bottom:1px solid var(--line)}
.banner-ok{background:var(--ok-bg);color:var(--ok)}
.banner-warn{background:var(--warn-bg);color:var(--warn)}
.banner-down{background:var(--down-bg);color:var(--down)}
.banner-line{display:flex;align-items:center;gap:10px;margin:0;font-size:1.3rem;font-weight:600}
.banner-sub{margin:6px 0 0 34px;color:inherit;opacity:.85;font-size:.95rem}
.ico{width:24px;height:24px;flex:none}
main{padding:8px 0 40px}
h2{font-size:.82rem;text-transform:uppercase;letter-spacing:.09em;color:var(--muted);
   font-weight:650;margin:32px 0 12px}
.note{margin:-6px 0 12px;color:var(--muted);font-size:.88rem}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;
      padding:18px 20px;margin:0 0 14px}
.card-open{border-left:3px solid var(--down)}
details.card{padding:0}
.past-sum{display:flex;align-items:center;gap:12px;flex-wrap:wrap;
          padding:13px 20px;cursor:pointer;list-style:none;border-radius:10px}
.past-sum::-webkit-details-marker{display:none}
.past-sum::marker{content:""}
.past-sum::before{content:"";width:8px;height:8px;flex:none;margin-top:-3px;
                  border-right:2px solid var(--muted);border-bottom:2px solid var(--muted);
                  transform:rotate(-45deg)}
details[open]>.past-sum::before{transform:rotate(45deg);margin-top:-6px}
.past-sum h3{margin:0;font-size:1rem;font-weight:600;flex:1 1 220px;overflow-wrap:anywhere}
.past-at{color:var(--muted);font-size:.84rem;margin-left:auto}
.past-sum:hover h3{text-decoration:underline}
.past-sum:focus-visible{outline:2px solid var(--plan);outline-offset:-2px}
.past-body{padding:2px 20px 16px}
.pm{margin:14px 0 2px;padding:12px 14px;border:1px solid var(--line);
    border-left:3px solid var(--plan);border-radius:8px;background:var(--plan-bg)}
.pm h4{margin:0;font-size:.78rem;font-weight:650;text-transform:uppercase;
       letter-spacing:.08em;color:var(--plan)}
.pm-at{margin:2px 0 0;color:var(--muted);font-size:.84rem}
.pm .body{margin-top:8px}
.note.more{margin:2px 0 0}
.card-head{display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap}
.card-head h3{margin:0;font-size:1.08rem;font-weight:620;flex:1 1 260px;overflow-wrap:anywhere}
.pill{flex:none;font-size:.75rem;font-weight:650;letter-spacing:.04em;text-transform:uppercase;
      padding:4px 10px;border-radius:999px}
.pill-ok{background:var(--ok-bg);color:var(--ok)}
.pill-warn{background:var(--warn-bg);color:var(--warn)}
.pill-down{background:var(--down-bg);color:var(--down)}
.pill-plan{background:var(--plan-bg);color:var(--plan)}
.meta,.window{margin:6px 0 0;color:var(--muted);font-size:.88rem}
.log{list-style:none;margin:16px 0 0;padding:0;border-top:1px solid var(--line)}
.entry{padding:14px 0 2px;border-bottom:1px solid var(--line)}
.entry:last-child{border-bottom:0;padding-bottom:0}
.entry-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px}
.dot{width:9px;height:9px;border-radius:50%;flex:none}
.dot-ok{background:var(--ok)} .dot-warn{background:var(--warn)} .dot-down{background:var(--down)}
.entry-state{font-weight:620;font-size:.88rem}
.entry-at{color:var(--muted);font-size:.84rem;margin-left:auto}
.body p{margin:.5em 0}
.body p:first-child{margin-top:0}
.body p:last-child{margin-bottom:0}
.body{overflow-wrap:anywhere}
.empty{color:var(--muted);font-style:italic}
.unknown{color:var(--muted)}
.foot{border-top:1px solid var(--line);background:var(--card);padding:24px 0 34px;
      color:var(--muted);font-size:.88rem}
.foot p{margin:.35em 0}
.foot a{color:inherit}
.foot-stamp{opacity:.85}
.foot-name{margin-top:12px;font-weight:600;color:var(--ink);opacity:.7}
CSS;
    }
}
