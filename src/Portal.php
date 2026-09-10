<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

/**
 * What a requester sees, in the self-service interface.
 *
 * Everything else in this plugin faces a technician or a stranger holding an
 * address. This is the third audience and the one that was missing: the person
 * who raised the ticket, logged into the helpdesk, wondering whether it is just
 * them. They had no path to the status page at all — it exists, it is written,
 * and the only way to it was somebody pasting the URL into an email.
 *
 * Two surfaces, deliberately different promises:
 *
 *   - **The banner**, when something is actually happening. Named in the
 *     customer's own vocabulary, linking to their entity's page.
 *   - **The quiet link**, when nothing is. A nav entry that says a page exists,
 *     shown only when there is one to read.
 *
 * Nothing internal reaches either. The banner carries a customer-visible title
 * and a state label and nothing else — no commander, no comms owner, no ticket,
 * no update text of any audience. The link is the entity's own public address,
 * which is theirs to have; it is what an administrator would email them.
 *
 * **Cost.** Both run on pages people load all day, so neither may query much
 * and neither may ever regenerate a page. The whole state is one small array
 * per entity, built from this plugin's own tables and kept in GLPI's cache for
 * a minute — and dropped the moment a publish rewrites that entity's page, so
 * the delay only ever applies to something nobody changed.
 */
final class Portal
{
    /**
     * How long a portal state survives untouched.
     *
     * Short, because the thing it is caching is "is my office's outage still
     * on". Any customer-visible change republishes the page and drops this key
     * on the way past, so the minute is the ceiling on staleness for a change
     * that somehow bypassed the publisher — not the normal latency.
     */
    public const CACHE_SECONDS = 60;

    /**
     * How far ahead a maintenance window counts as worth a banner.
     *
     * Not a setting. The status page already lists everything scheduled; this
     * is the narrower question of what deserves interrupting somebody with, and
     * "tonight" is the honest answer to that in every service desk.
     */
    public const IMMINENT_HOURS = 24;

    /** @var array<int,array<string,mixed>> per-request memo on top of the cache */
    private static array $memo = [];

    // ------------------------------------------------------------------ data

    /**
     * The portal's whole state for one entity.
     *
     * Pure of the session: it takes an entity id and answers about that entity,
     * so it can be asserted directly in a test rather than inferred from what a
     * browser drew. The caller decides whose entity to ask about.
     *
     * @return array{
     *     url:string,
     *     href:string,
     *     path:string,
     *     title:string,
     *     incidents:array<int,array{title:string,state:string,label:string}>,
     *     maintenance:array<int,array{title:string,state:string,start:string}>
     * }
     */
    public static function state(int $entities_id): array
    {
        if (isset(self::$memo[$entities_id])) {
            return self::$memo[$entities_id];
        }

        $cache  = self::cache();
        $key    = self::cacheKey($entities_id);
        $cached = $cache !== null ? $cache->get($key) : null;

        if (is_array($cached) && isset($cached['url'], $cached['href'], $cached['path'], $cached['incidents'])) {
            return self::$memo[$entities_id] = $cached;
        }

        $state = self::build($entities_id);

        if ($cache !== null) {
            $cache->set($key, $state, self::CACHE_SECONDS);
        }

        return self::$memo[$entities_id] = $state;
    }

    /** Is there anything to interrupt somebody about? */
    public static function isActive(array $state): bool
    {
        return $state['url'] !== ''
            && ($state['incidents'] !== [] || $state['maintenance'] !== []);
    }

    /**
     * Read the plugin's own three tables, and nothing else.
     *
     * No ticket, no follow-up, no entity join beyond the ancestor walk. The
     * heaviest thing here is one `IN` over a handful of ids.
     */
    private static function build(int $entities_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $empty = [
            'url' => '', 'href' => '', 'path' => '', 'title' => '',
            'incidents' => [], 'maintenance' => [],
        ];

        // Nothing at all until publishing is on and this entity has a live
        // address. A banner that links to a page nobody can open is worse than
        // no banner: it tells a customer there is somewhere to look and then
        // does not take them there.
        if (!Settings::flag('status_enabled')) {
            return $empty;
        }

        $page = Page::forEntity($entities_id);
        if ($page === null) {
            return $empty;
        }

        $url = Page::publicUrl($page);
        if ($url === '') {
            return $empty;
        }

        $state = [
            // Three shapes of one address, because three consumers need
            // different ones and computing the wrong one is a link that 404s in
            // a customer's face.
            //
            //   url  — absolute, from url_base. What an administrator emails.
            //   href — root-relative including root_doc. What the banner's
            //          anchor uses: same-origin, so it works even on an
            //          instance whose url_base was never corrected, which is
            //          most development instances and a few live ones.
            //   path — root-relative *without* root_doc, because the menu
            //          template runs its own path() over whatever it is given
            //          and would otherwise prefix root_doc twice.
            'url'         => $url,
            'href'        => Url::to('front/status.php/' . (string) $page['token']),
            'path'        => Url::path('front/status.php/' . (string) $page['token']),
            'title'       => Brand::title($page),
            'incidents'   => [],
            'maintenance' => [],
        ];

        // The entity's own open incidents, and any declared at an ancestor as
        // covering its sub-entities. Same clause as the status page itself, so
        // the banner and the page it links to cannot disagree about what is
        // happening — see Tree::visibleFrom() for why a sibling is unreachable.
        foreach (
            $DB->request([
                'SELECT' => ['name', 'state'],
                'FROM'   => Incident::getTable(),
                'WHERE'  => [
                    'OR'    => Tree::visibleFrom($entities_id),
                    'state' => ['<>', Incident::RESOLVED],
                ],
                'ORDER'  => ['date_declared DESC'],
                'LIMIT'  => 5,
            ]) as $row
        ) {
            $state['incidents'][] = [
                'title' => (string) $row['name'],
                'state' => (string) $row['state'],
                'label' => Incident::stateLabel((string) $row['state']),
            ];
        }

        $horizon = date('Y-m-d H:i:s', time() + (self::IMMINENT_HOURS * HOUR_TIMESTAMP));

        foreach (
            $DB->request([
                'SELECT' => ['name', 'state', 'date_start'],
                'FROM'   => Maintenance::getTable(),
                'WHERE'  => [
                    'OR' => Tree::visibleFrom($entities_id),
                    // In progress now, or starting inside the horizon. A window
                    // three weeks out is on the status page, which is where a
                    // customer goes to plan; it is not worth a banner on the
                    // page they open to raise a ticket about a printer.
                    'AND' => [
                        'OR' => [
                            ['state' => Maintenance::IN_PROGRESS],
                            [
                                'state'      => Maintenance::SCHEDULED,
                                'date_start' => ['<=', $horizon],
                            ],
                        ],
                    ],
                ],
                'ORDER'  => ['date_start ASC'],
                'LIMIT'  => 3,
            ]) as $row
        ) {
            $state['maintenance'][] = [
                'title' => (string) $row['name'],
                'state' => (string) $row['state'],
                'start' => (string) ($row['date_start'] ?? ''),
            ];
        }

        return $state;
    }

    // ----------------------------------------------------------------- cache

    /**
     * Drop one entity's cached state.
     *
     * Called from the publisher, which already knows every entity whose page it
     * has just rewritten — including the whole subtree of a recursive incident.
     * That is why the TTL can be a backstop rather than the mechanism: a
     * customer update posted at 09:14 is on the portal at 09:14.
     */
    public static function forget(int $entities_id): void
    {
        unset(self::$memo[$entities_id]);

        $cache = self::cache();
        if ($cache !== null) {
            $cache->delete(self::cacheKey($entities_id));
        }
    }

    private static function cacheKey(int $entities_id): string
    {
        return 'glpimajor_portal_' . $entities_id;
    }

    /**
     * GLPI's cache, or nothing at all.
     *
     * Guarded because this runs from a display hook, and a plugin that fatals
     * because a cache backend is unavailable would take down the page a
     * requester was trying to raise a ticket from. Without a cache the queries
     * above are still two small indexed reads.
     */
    private static function cache(): ?\Psr\SimpleCache\CacheInterface
    {
        $cache = $GLOBALS['GLPI_CACHE'] ?? null;

        return $cache instanceof \Psr\SimpleCache\CacheInterface ? $cache : null;
    }

    // --------------------------------------------------------------- surfaces

    /**
     * The banner, on the helpdesk home page.
     *
     * Rendered from `Hooks::DISPLAY_CENTRAL`, which GLPI 11 calls from inside
     * `<table class="central">` on `pages/helpdesk/index.html.twig` — so this
     * emits a table row. Anything else is foster-parented by the HTML parser
     * out of the table and out of the page's container, which looks exactly
     * like a broken plugin.
     *
     * Calm on purpose. This is read by somebody who already knows something is
     * wrong; a red alarm adds nothing but the impression of panic at the
     * supplier. It states what is happening, in the words we chose to publish,
     * and offers the page.
     */
    public static function banner(): void
    {
        if (!Settings::flag('portal_banner')) {
            return;
        }

        $entities_id = self::currentEntity();
        if ($entities_id === null) {
            return;
        }

        $state = self::state($entities_id);
        if (!self::isActive($state)) {
            return;
        }

        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        echo "<tr><td colspan='2' class='p-0'>";
        echo "<div class='glpimajor-portal' role='status'>";

        echo "<div class='glpimajor-portal-head'>";
        echo "<span class='glpimajor-portal-mark' aria-hidden='true'></span>";
        echo "<span class='glpimajor-portal-lede'>"
           . __s('There is a service issue affecting your organisation.', 'glpimajor')
           . '</span>';
        echo '</div>';

        echo "<ul class='glpimajor-portal-list'>";

        foreach ($state['incidents'] as $one) {
            echo "<li class='glpimajor-portal-item'>";
            echo "<span class='glpimajor-portal-title'>" . $e($one['title']) . '</span>';
            echo "<span class='glpimajor-portal-state'>" . $e($one['label']) . '</span>';
            echo '</li>';
        }

        foreach ($state['maintenance'] as $one) {
            echo "<li class='glpimajor-portal-item'>";
            echo "<span class='glpimajor-portal-title'>" . $e($one['title']) . '</span>';
            echo "<span class='glpimajor-portal-state'>"
               . ($one['state'] === Maintenance::IN_PROGRESS
                   ? __s('Planned work in progress', 'glpimajor')
                   : __s('Planned work', 'glpimajor'))
               . '</span>';
            echo '</li>';
        }

        echo '</ul>';

        echo "<div class='glpimajor-portal-foot'>";
        // The in-portal page for somebody who can open it, the public address
        // otherwise. PortalStatus decides; this must not have its own opinion,
        // or the banner and the navigation entry send the same reader to two
        // different places.
        echo "<a class='glpimajor-portal-link' href='" . $e(PortalStatus::linkFor($state)) . "'>"
           . __s('Read the latest updates', 'glpimajor') . '</a>';
        echo "<span class='glpimajor-portal-note'>"
           . __s('Your ticket is still with us. There is no need to raise another one for this.',
               'glpimajor')
           . '</span>';
        echo '</div>';

        echo '</div></td></tr>';
    }

    /**
     * The quiet link, in the helpdesk navigation.
     *
     * Added through `Hooks::REDEFINE_MENUS` rather than `helpdesk_menu_entry`.
     * The older hook works — `Html::generateHelpMenu()` still reads it — but it
     * forces the entry into a shared "Plugins" dropdown, titled with the
     * plugin's own name, two clicks from anywhere. A customer looking for
     * "is it just me" does not go looking under Plugins. `REDEFINE_MENUS` runs
     * immediately afterwards in the same method and takes a flat top-level
     * entry with the title we choose.
     *
     * Shown only where there is something to open, which is the whole
     * difference between a link and a dead end.
     *
     * @param array<string,mixed> $menu
     *
     * @return array<string,mixed>
     */
    public static function menu(array $menu): array
    {
        if (!Settings::flag('portal_link')) {
            return $menu;
        }

        $entities_id = self::currentEntity();
        if ($entities_id === null) {
            return $menu;
        }

        $state = self::state($entities_id);
        if ($state['url'] === '') {
            return $menu;
        }

        $menu['glpimajor_status'] = [
            'title' => __('Service status', 'glpimajor'),
            'icon'  => 'ti ti-activity-heartbeat',
            // Root-relative without root_doc: the menu template runs it through
            // twig's path(), which prefixes root_doc itself.
            'default' => PortalStatus::linkFor($state, 'path'),
        ];

        return $menu;
    }

    /**
     * The entity to answer about, or null when the question does not apply.
     *
     * `null` for anybody in the central interface — a technician has the
     * incident itself, and the banner would be a worse copy of it — and for
     * anything with no session entity, which is every anonymous request.
     */
    public static function currentEntity(): ?int
    {
        if (\Session::getCurrentInterface() !== 'helpdesk') {
            return null;
        }

        if (!isset($_SESSION['glpiactive_entity'])) {
            return null;
        }

        return (int) $_SESSION['glpiactive_entity'];
    }
}
