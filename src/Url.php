<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

/**
 * Where this plugin's pages live.
 *
 * Also the PATH_INFO shim. GLPI 11 routes every request through one front
 * controller, and PHP's PATH_INFO is not populated by the time a legacy plugin
 * script runs — so a script that wants `/front/status.php/<token>` has to read
 * the token out of REQUEST_URI itself. glpi-identity's SCIM endpoint does the
 * same thing for the same reason.
 */
final class Url
{
    public const KEY = 'glpimajor';

    /**
     * Root-relative path WITHOUT the root_doc prefix — what Html::css() and
     * Html::script() want, since they prepend root_doc themselves.
     */
    public static function path(string $path): string
    {
        return '/plugins/' . self::KEY . '/' . ltrim($path, '/');
    }

    /** Root-relative including root_doc, for anything fetched from JavaScript. */
    public static function to(string $path): string
    {
        global $CFG_GLPI;

        return rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/') . self::path($path);
    }

    /**
     * Absolute, for anything that leaves the instance — the status-page URL an
     * administrator copies to a customer, and the logo link inside a page that
     * is opened from a bookmark rather than from GLPI.
     */
    public static function absolute(string $path): string
    {
        global $CFG_GLPI;

        return rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . self::to($path);
    }

    /** Whatever followed `/front/<script>` in the URL, or ''. */
    public static function pathAfter(string $script): string
    {
        $explicit = (string) ($_SERVER['PATH_INFO'] ?? '');
        if ($explicit !== '') {
            return $explicit;
        }

        $uri    = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $marker = '/front/' . ltrim($script, '/');

        $at = strpos($uri, $marker);
        if ($at === false) {
            return '';
        }

        return substr($uri, $at + strlen($marker));
    }
}
