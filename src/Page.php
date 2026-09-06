<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

use CommonDBTM;
use CronTask;

/**
 * One status page per entity: its address, its state, and the file behind it.
 *
 * The generated document is named `<token>.html`, and that is the whole design.
 * The public endpoint validates the token's *shape* and opens that filename —
 * it asks the database nothing, so there is no query path from the internet
 * into GLPI's data layer at all. Not a restricted one, not a read-only one,
 * none. Multi-tenant safety here is structural rather than a filter somebody
 * has to remember to write.
 *
 * Extends CommonDBTM for the cron registry and GLPI's history, not because
 * anybody manages these from a form; they are created and revoked from a card
 * on the settings page.
 */
class Page extends CommonDBTM
{
    public static $rightname = 'plugin_glpimajor_config';

    /** 24 random bytes as lowercase hex. 192 bits is past argument. */
    public const TOKEN_BYTES = 24;

    /**
     * Anchored with \A and \z, not ^ and $.
     *
     * PHP's `$` matches before a trailing newline, so `/^[0-9a-f]{48}$/` accepts
     * "<48 hex>\n" — which would then be concatenated into a filename. \z is the
     * end of the subject and nothing else. The test suite asserts this
     * specifically, because it is the kind of thing that looks right forever.
     */
    public const TOKEN_RE = '/\A[0-9a-f]{48}\z/';

    public static function getTypeName($nb = 0)
    {
        return _n('Status page', 'Status pages', $nb, 'glpimajor');
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_glpimajor_pages';
    }

    // ------------------------------------------------------------- storage

    /**
     * Where the generated documents live.
     *
     * GLPI_PLUGIN_DOC_DIR, one subdirectory per plugin, which is the convention
     * glpi-whitelabel established for the same class of thing: a file GLPI
     * generates, serves itself, and must survive an upgrade that replaces the
     * plugin directory.
     */
    public static function dir(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/glpimajor/status';
    }

    public static function fileFor(string $token): string
    {
        return self::dir() . '/' . $token . '.html';
    }

    public static function isTokenShaped(string $token): bool
    {
        return preg_match(self::TOKEN_RE, $token) === 1;
    }

    /**
     * The readable file a token names, or null — the only way to turn a token
     * from a request into a path to open.
     *
     * The shape check alone already settles traversal: TOKEN_RE admits 48
     * lowercase hex characters and nothing else, so the token cannot carry a
     * dot, a slash, a null byte or a trailing newline into the concatenation.
     * The realpath containment underneath it is deliberate belt-and-braces on
     * a path that the *unauthenticated* endpoint walks: it costs one syscall
     * per request, and it means the safety of front/status.php stops depending
     * on every future reader noticing that the regular expression above is
     * load-bearing. If the resolved file is not inside dir(), nothing is
     * served — no fallback, no second attempt.
     */
    public static function resolveFile(string $token): ?string
    {
        if (!self::isTokenShaped($token)) {
            return null;
        }

        $base = realpath(self::dir());
        if ($base === false) {
            return null;
        }

        $file = realpath(self::fileFor($token));
        if ($file === false || !is_file($file) || !is_readable($file)) {
            return null;
        }

        return str_starts_with($file, rtrim($base, '/') . '/') ? $file : null;
    }

    // -------------------------------------------------------------- records

    /** @return array<string,mixed>|null */
    public static function forEntity(int $entities_id): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['entities_id' => $entities_id],
                'LIMIT' => 1,
            ]) as $row
        ) {
            return $row;
        }

        return null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'ORDER' => ['entities_id ASC'],
                'LIMIT' => 500,
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Give an entity a page, or a new address for the one it has.
     *
     * Regenerating is how an address that leaked is retired: the old file is
     * removed before the new one is written, so the URL somebody forwarded
     * stops working the moment the button is pressed rather than whenever the
     * next publish happens.
     */
    public static function mint(int $entities_id): string
    {
        /** @var \DBmysql $DB */
        global $DB;

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $now   = date('Y-m-d H:i:s');
        $row   = self::forEntity($entities_id);

        if ($row === null) {
            $DB->insert(self::getTable(), [
                'entities_id'   => $entities_id,
                'token'         => $token,
                'is_enabled'    => 1,
                'date_creation' => $now,
                'date_mod'      => $now,
            ]);
        } else {
            self::removeFile((string) $row['token']);

            $DB->update(self::getTable(), [
                'token'          => $token,
                'is_enabled'     => 1,
                'date_generated' => null,
                'bytes'          => 0,
                'date_mod'       => $now,
            ], ['id' => (int) $row['id']]);
        }

        Events::record(0, Events::TOKEN, sprintf('entity %d: new address', $entities_id), $entities_id);

        Portal::forget($entities_id);
        Publisher::publish($entities_id);

        return $token;
    }

    /**
     * Take a page down.
     *
     * The row is kept with an empty token so the settings page can still say
     * "this entity had a page and it was revoked", which is a different fact
     * from "this entity never had one".
     */
    public static function revoke(int $entities_id): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = self::forEntity($entities_id);
        if ($row === null) {
            return false;
        }

        self::removeFile((string) $row['token']);

        $DB->update(self::getTable(), [
            'token'          => '',
            'is_enabled'     => 0,
            'date_generated' => null,
            'bytes'          => 0,
            'date_mod'       => date('Y-m-d H:i:s'),
        ], ['id' => (int) $row['id']]);

        Events::record(0, Events::TOKEN, sprintf('entity %d: revoked', $entities_id), $entities_id);

        // A revoked address must stop being offered on the portal at once, not
        // at the end of the cache window — the link would 404 in a customer's
        // face, which is the one thing worse than no link.
        Portal::forget($entities_id);

        return true;
    }

    /** The address to hand a customer. Empty when there is no live page. */
    public static function publicUrl(array $row): string
    {
        $token = (string) ($row['token'] ?? '');
        if ($token === '' || (int) ($row['is_enabled'] ?? 0) !== 1) {
            return '';
        }

        return Url::absolute('front/status.php/' . $token);
    }

    public static function removeFile(string $token): void
    {
        if ($token === '' || !self::isTokenShaped($token)) {
            return;
        }

        $file = self::fileFor($token);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** Uninstall: a revoked plugin must not leave a live customer-facing page. */
    public static function purgeAllFiles(): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return;
        }

        foreach ((array) @scandir($dir) as $entry) {
            if (!is_string($entry) || !str_ends_with($entry, '.html')) {
                continue;
            }
            @unlink($dir . '/' . $entry);
        }

        @rmdir($dir);
    }

    // ------------------------------------------------------------------ cron

    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'publish' => ['description' => __('Republish stale status pages', 'glpimajor')],
            default   => [],
        };
    }

    /**
     * The backstop.
     *
     * Pages are regenerated synchronously on every customer-visible change, so
     * in the normal case this finds nothing to do. It exists because the
     * failure mode of the synchronous path is invisible — a customer reading
     * yesterday's page has no way to tell it is yesterday's — and a page that
     * self-heals within the hour is worth a cheap query every quarter of one.
     *
     * It also rolls maintenance windows, which change what the page says
     * without anybody touching anything.
     */
    public static function cronPublish(CronTask $task): int
    {
        if (!Settings::flag('status_enabled')) {
            return 0;
        }

        $rolled = Maintenance::rollWindows();

        $max_age = ((int) Settings::get('status_max_age_min')) * 60;
        $now     = time();
        $done    = 0;

        foreach (self::all() as $row) {
            if ((int) $row['is_enabled'] !== 1 || (string) $row['token'] === '') {
                continue;
            }

            $generated = $row['date_generated'] ?? null;
            $stamp     = is_string($generated) && $generated !== '' ? strtotime($generated) : false;
            $missing   = !is_file(self::fileFor((string) $row['token']));

            if (!$missing && $stamp !== false && ($now - $stamp) < $max_age) {
                continue;
            }

            Publisher::publish((int) $row['entities_id']);
            $done++;
        }

        $task->setVolume($done + $rolled);

        return ($done + $rolled) > 0 ? 1 : 0;
    }
}
