<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

/**
 * Gathers what a customer may see, renders it, and writes the file.
 *
 * The only thing in this plugin that writes to disk, and the only bridge
 * between the database and the Renderer — which is pure, and stays that way
 * because everything it would otherwise have had to query happens here.
 *
 * Publication is synchronous, on the request that made the change. A queue
 * would be tidier and would mean a technician pressing "publish" and having no
 * idea whether it worked; during an outage, "did the customer see that" is not
 * a question to answer with "probably, within fifteen minutes".
 *
 * Nothing here can throw into its caller. A status page that fails to write must
 * not also fail the update that was being published — the update is the record,
 * the page is a copy of it, and the failure is recorded where an administrator
 * will see it.
 */
final class Publisher
{
    /**
     * Republish an entity's page because something customer-visible changed.
     *
     * Cheap and safe to call from anywhere, including from paths where nothing
     * relevant changed: working out which fields matter in every code path is
     * how a page ends up stale in exactly one of them.
     */
    public static function onChange(int $entities_id, bool $recursive = false): void
    {
        if (!Settings::flag('status_enabled')) {
            return;
        }

        if ($recursive) {
            self::onChangeSubtree($entities_id);

            return;
        }

        $page = Page::forEntity($entities_id);
        if ($page === null || (int) $page['is_enabled'] !== 1 || (string) $page['token'] === '') {
            return;
        }

        self::publish($entities_id);
    }

    /**
     * A customer-visible change on a recursive incident, republished everywhere
     * it is now read.
     *
     * The declaring entity's page and every descendant's, because a recursive
     * incident appears on all of them and a stale child page is a customer
     * being told the outage is over when it is not.
     *
     * Batched at the query, not at the render. One request finds the pages that
     * actually exist inside the subtree — usually one or three, and never more
     * than the number of addresses somebody has deliberately minted — and only
     * those are rendered. A tree with forty branches and two published pages
     * costs two renders, not forty. Each page's failure is recorded on its own
     * row exactly as a single publish would, so one broken page does not hide
     * the others or stop them.
     *
     * @return int how many pages were rewritten
     */
    public static function onChangeSubtree(int $entities_id): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!Settings::flag('status_enabled')) {
            return 0;
        }

        $done = 0;
        foreach (
            $DB->request([
                'SELECT' => ['entities_id'],
                'FROM'   => Page::getTable(),
                'WHERE'  => [
                    'entities_id' => Tree::subtree($entities_id),
                    'is_enabled'  => 1,
                    ['NOT' => ['token' => '']],
                ],
                'ORDER'  => ['entities_id ASC'],
                'LIMIT'  => 500,
            ]) as $row
        ) {
            if (self::publish((int) $row['entities_id'])) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * Render and write, unconditionally.
     *
     * @return bool false when nothing was written; the reason is on the page row
     */
    public static function publish(int $entities_id): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        // The master switch is checked here as well as in onChange(), because
        // this is also what the Republish button and the cron call. A feature
        // that is switched off must not be writing customer-facing files
        // because somebody pressed a button that was still on the screen.
        if (!Settings::flag('status_enabled')) {
            return false;
        }

        $page = Page::forEntity($entities_id);
        if ($page === null) {
            return false;
        }

        $token = (string) $page['token'];
        if ($token === '' || !Page::isTokenShaped($token) || (int) $page['is_enabled'] !== 1) {
            return false;
        }

        try {
            $html = Renderer::document(self::gather($entities_id, $page));
            $file = Page::fileFor($token);

            $dir = dirname($file);
            if (!is_dir($dir) && !@mkdir($dir, 0o770, true) && !is_dir($dir)) {
                throw new \RuntimeException('could not create the storage directory');
            }

            // Written to a neighbour and renamed. rename() within a filesystem
            // is atomic, so a customer refreshing mid-write reads either the
            // old page or the new one, never half of either.
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $html) === false) {
                throw new \RuntimeException('could not write the page');
            }
            @chmod($tmp, 0o640);

            if (!@rename($tmp, $file)) {
                @unlink($tmp);
                throw new \RuntimeException('could not replace the published page');
            }

            $DB->update(Page::getTable(), [
                'date_generated' => date('Y-m-d H:i:s'),
                'bytes'          => strlen($html),
                'last_error'     => null,
                'date_mod'       => date('Y-m-d H:i:s'),
            ], ['id' => (int) $page['id']]);

            Events::record(0, Events::PUBLISHED, sprintf('%d bytes', strlen($html)), $entities_id, 0);

            // The portal reads a cached summary of the same facts. Dropping it
            // here rather than waiting for the TTL is what makes a customer
            // update posted at 09:14 visible on the portal at 09:14.
            Portal::forget($entities_id);

            return true;
        } catch (\Throwable $e) {
            // Recorded, not thrown. The settings page's status card is where an
            // administrator finds out, which is the difference between a broken
            // page and a broken page nobody knows about.
            $DB->update(Page::getTable(), [
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
                'date_mod'   => date('Y-m-d H:i:s'),
            ], ['id' => (int) $page['id']]);

            trigger_error('glpimajor: status page for entity ' . $entities_id
                . ' could not be published: ' . $e->getMessage(), E_USER_WARNING);

            return false;
        }
    }

    /**
     * Everything the Renderer needs, and nothing it does not.
     *
     * This is where the customer-visible projection happens: internal updates
     * are dropped here rather than filtered in the template, so there is no
     * template path that could accidentally reach one. The document that leaves
     * this process has never held an internal sentence.
     */
    public static function gather(int $entities_id, array $page): array
    {
        $cfg     = Settings::all();
        $days    = (int) $cfg['status_history_days'];
        $cutoff  = date('Y-m-d H:i:s', time() - ($days * DAY_TIMESTAMP));

        return [
            'title'        => Brand::title($page),
            'brand'        => Brand::forEntity($entities_id, $page),
            'generated_at' => time(),
            'timezone'     => self::timezone(),
            'history_days' => $days,
            'incidents'    => self::incidents($entities_id, $cutoff),
            'maintenance'  => self::maintenance($entities_id),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function incidents(int $entities_id, string $cutoff): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = [];
        foreach (
            $DB->request([
                'FROM'  => Incident::getTable(),
                'WHERE' => [
                    // The entity's own incidents, plus any declared at an
                    // ancestor and marked as covering its sub-entities.
                    //
                    // Written as an explicit OR of two fenced clauses rather
                    // than one `entities_id IN (self + ancestors)`, because the
                    // second clause must carry `is_recursive` with it: an
                    // ancestor's *non*-recursive incident is that entity's own
                    // business and must never reach a child's page. Reading up
                    // the tree is also the only direction that cannot produce a
                    // sibling — a sibling is in nobody's ancestor list.
                    'OR' => Tree::visibleFrom($entities_id),
                    'AND' => [
                        'OR' => [
                            ['state' => ['<>', Incident::RESOLVED]],
                            [
                                'state'         => Incident::RESOLVED,
                                'date_resolved' => ['>=', $cutoff],
                            ],
                        ],
                    ],
                ],
                'ORDER' => ['date_declared DESC'],
                'LIMIT' => 100,
            ]) as $row
        ) {
            $rows[] = $row;
        }

        $out = [];
        foreach ($rows as $row) {
            $updates = [];
            foreach (Update::forIncident((int) $row['id'], Update::CUSTOMER) as $update) {
                $updates[] = [
                    'at'      => self::stamp($update['date_creation'] ?? null),
                    'state'   => (string) ($update['state_at_time'] ?? $row['state']),
                    'content' => (string) ($update['content'] ?? ''),
                ];
            }

            // The published post-mortem, supplied only when one exists AND
            // the incident is currently resolved. The second condition is the
            // reopen case: a resolved incident with a published post-mortem
            // that regresses back to monitoring must not carry "here is what
            // happened" above a timeline that says it is still happening —
            // the row stays published, and the account returns to the page
            // when the incident is resolved again. Renderer::postmortem()
            // documents the same contract from the consuming side and renders
            // it first in the incident's entry; an absent key keeps meaning
            // "none has been published".
            $postmortem = null;
            if ((string) $row['state'] === Incident::RESOLVED) {
                $pm = Postmortem::publishedFor((int) $row['id']);
                if ($pm !== null) {
                    $postmortem = [
                        'content'      => (string) $pm['content'],
                        'published_at' => self::stamp($pm['published_at'] ?? null),
                    ];
                }
            }

            // A resolved incident nobody ever told the customer about was never
            // on the page, and putting it there when it ends would be the first
            // they heard of an outage that is already over. A published
            // post-mortem counts as telling them: publishing one is precisely
            // the deliberate act of putting the incident in front of the
            // customer, updates or no updates.
            if ($updates === [] && $postmortem === null && (string) $row['state'] === Incident::RESOLVED) {
                continue;
            }

            $entry = [
                'title'       => (string) $row['name'],
                'state'       => (string) $row['state'],
                'started_at'  => self::stamp($row['date_declared'] ?? null),
                'resolved_at' => self::stamp($row['date_resolved'] ?? null),
                'updates'     => $updates,
            ];

            if ($postmortem !== null) {
                $entry['postmortem'] = $postmortem;
            }

            $out[] = $entry;
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function maintenance(int $entities_id): array
    {
        $out = [];
        foreach (Maintenance::upcomingFor($entities_id) as $row) {
            $out[] = [
                'title'   => (string) $row['name'],
                'content' => (string) ($row['content'] ?? ''),
                'start'   => self::stamp($row['date_start'] ?? null),
                'end'     => self::stamp($row['date_end'] ?? null),
                'state'   => (string) $row['state'],
            ];
        }

        return $out;
    }

    /**
     * The timezone the page's clock is drawn in.
     *
     * Configured here rather than taken from PHP, because the customer reading
     * it is not necessarily in the same country as the server — and a page that
     * says "14:20" with no offset is a page nobody can act on. The Renderer
     * prints the offset next to the "last updated" stamp for that reason.
     */
    private static function timezone(): string
    {
        $configured = trim((string) Settings::get('status_timezone'));
        if ($configured !== '') {
            return $configured;
        }

        return date_default_timezone_get() ?: 'UTC';
    }

    private static function stamp(mixed $value): int
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000')) {
            return 0;
        }

        $at = strtotime($value);

        return $at === false ? 0 : $at;
    }
}
