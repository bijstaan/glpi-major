<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

use Html;
use Session;

/**
 * The status page, for somebody who is signed in.
 *
 * There are two audiences for the same information and they were being served
 * by one artefact. A stranger holding an address gets the published file: no
 * session, no cookie, a flat page whose filename is the token. That is right
 * for them and nothing here changes it.
 *
 * A requester who is **signed in** was getting sent to that same file. It works
 * — but it takes somebody out of the interface they are already in, into a page
 * that has none of its chrome, and hands them a secret address they never
 * needed, because their own session already says which organisation they belong
 * to. This is the surface that was missing.
 *
 * ## Same data, same allow-list, different chrome
 *
 * The content comes from {@see Publisher::gather()} and is rendered by
 * {@see Renderer::body()} — the identical call the published file makes. A
 * customer signing in does not become entitled to more than a customer holding
 * the address, so there is deliberately no "internal" variant and no flag that
 * would produce one. If this page shows something the public page does not,
 * that is a bug in one shared method rather than a divergence between two.
 *
 * What differs is only the frame: the interface's own header, navigation and
 * stylesheet instead of a hand-written document with its CSS inlined.
 *
 * ## Which organisation, and how that is decided
 *
 * The reader's **session entity**, never a parameter. There is no id in the
 * URL, so there is nothing to tamper with and nothing to enumerate: the page
 * answers about the organisation the session already belongs to or it answers
 * about nothing.
 *
 * ## When it exists at all
 *
 * The same condition the banner and the navigation entry use, so the three
 * cannot disagree: publishing switched on, and a page row for this entity that
 * an administrator has enabled. That row is the expressed intent "this
 * organisation's people get a status view".
 *
 * A **token is not required** to read this. The token is how the page reaches
 * somebody with no account; a signed-in reader is identified by their session.
 * In practice enabling a page mints one and revoking clears both, so the two
 * conditions travel together today — the independence is deliberate anyway, so
 * that a future way of enabling a page without publishing an address does not
 * silently take the in-portal view away with it. Where there is no address, the
 * "share this" block is simply absent.
 */
final class PortalStatus
{
    /** Where the in-portal page lives, root-relative and without root_doc. */
    public const PATH = '/plugins/glpimajor/front/portal.php';

    /**
     * Is this reader in the interface this page belongs to?
     *
     * The central interface is excluded on purpose. A technician has the
     * incident itself, with the commander, the comms owner and every update of
     * both audiences; a customer-facing copy would be a worse version of a
     * thing they already have open.
     */
    public static function applies(): bool
    {
        return Settings::flag('status_enabled')
            && Session::getCurrentInterface() === 'helpdesk'
            && Session::getLoginUserID() !== false;
    }

    /**
     * The page row this reader's session entitles them to, or null.
     *
     * Null covers every reason equally — publishing off, no session entity, no
     * page row, a row an administrator disabled — because the caller's response
     * to all of them is the same and a page that distinguished between them
     * would be describing an administrator's configuration to a customer.
     *
     * @return array<string,mixed>|null
     */
    public static function pageForReader(): ?array
    {
        if (!self::applies()) {
            return null;
        }

        $entities_id = Portal::currentEntity();
        if ($entities_id === null) {
            return null;
        }

        $page = Page::forEntity($entities_id);
        if ($page === null || (int) $page['is_enabled'] !== 1) {
            return null;
        }

        return $page;
    }

    /** Is there an in-portal page for this reader to open? */
    public static function available(): bool
    {
        return self::pageForReader() !== null;
    }

    /**
     * Render the page into the interface's chrome.
     *
     * @param array<string,mixed> $page the row from {@see pageForReader()}
     */
    public static function show(array $page): void
    {
        $entities_id = (int) $page['entities_id'];

        // The same gather the publisher runs. Called live rather than reading
        // the published file back off disk: the file can be up to
        // `status_max_age_min` stale, and this reader is signed in and asking
        // right now. It also means the in-portal view works before anybody has
        // ever minted an address.
        $data = Publisher::gather($entities_id, $page);

        echo '<div class="glpimajor-portal-status">';

        // Rendered by the same method the published file uses. Not escaped
        // again here: Renderer escapes every value it interpolates, and a
        // second pass would print the markup as text.
        echo Renderer::body($data);

        self::share($page);

        echo '</div>';
    }

    /**
     * The public address, offered as something to pass on.
     *
     * A requester is often the person who ends up telling the rest of their
     * office. The address is theirs to have — it is exactly what an
     * administrator would email them — and it is the only thing on this page
     * that the published file does not also carry.
     *
     * Absent when no address has been minted, rather than shown broken.
     *
     * @param array<string,mixed> $page
     */
    private static function share(array $page): void
    {
        $url = Page::publicUrl($page);
        if ($url === '') {
            return;
        }

        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        echo '<div class="container-narrow"><div class="card card-sm mt-3"><div class="card-body">';
        printf(
            '<p class="mb-2 text-secondary">%s</p>',
            __s('Anyone can read this page without signing in:', 'glpimajor')
        );
        printf(
            '<a href="%s" rel="noreferrer">%s</a>',
            $e($url),
            $e($url)
        );
        echo '</div></div></div>';
    }

    // ================================================================== menu

    /**
     * Where the navigation entry and the banner should point.
     *
     * The in-portal page for somebody who can open it; the public address for
     * anybody else. Written once, here, so the call sites cannot drift into
     * sending the same reader to two different places.
     *
     * Three forms, for the same reason {@see Portal::state()} keeps three — and
     * getting it wrong is a link that 404s in a customer's face:
     *
     *   - `href` — root-relative *including* root_doc. What an anchor needs.
     *   - `path` — root-relative *without* it, because the menu template runs
     *     its own `path()` over whatever it is given and would otherwise
     *     prefix root_doc twice.
     *   - `url`  — absolute. What somebody would paste into an email; only the
     *     public address is ever offered in this form, because an in-portal
     *     link is no use to a reader who cannot sign in.
     *
     * @param array<string,mixed> $state a {@see Portal::state()} array
     * @param 'href'|'path'|'url' $form
     */
    public static function linkFor(array $state, string $form = 'href'): string
    {
        if ($form !== 'url' && self::available()) {
            return $form === 'path'
                ? self::PATH
                : Html::getPrefixedUrl(self::PATH);
        }

        return (string) ($state[$form] ?? '');
    }
}
