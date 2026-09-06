<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Just enough GLPI for the pure code to load.
 *
 * The three suites in this directory test code that was written to have no
 * database, no session and no clock of its own — the matcher, the reminder
 * arithmetic and the status-page renderer. They still have to *load*, and PHP
 * resolves `extends` and class constants at load time, so a handful of
 * stand-ins are needed and nothing more.
 *
 * The stubs are deliberately anaemic. Anything a test needs that is not here is
 * a sign the code under test has grown a dependency it was not supposed to
 * have, which is worth finding out by the file failing to load rather than by
 * a subtle disagreement in production.
 */

namespace {
    if (!defined('GLPI_PLUGIN_DOC_DIR')) {
        define('GLPI_PLUGIN_DOC_DIR', '/tmp/glpimajor-test-doc');
    }

    if (!function_exists('__')) {
        function __(string $text, string $domain = 'glpi'): string
        {
            return $text;
        }
    }

    if (!function_exists('_n')) {
        function _n(string $one, string $many, int $n, string $domain = 'glpi'): string
        {
            return $n === 1 ? $one : $many;
        }
    }

    if (!function_exists('__s')) {
        function __s(string $text, string $domain = 'glpi'): string
        {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }

    if (!class_exists('CommonDBTM')) {
        class CommonDBTM
        {
        }
    }

    if (!class_exists('CronTask')) {
        class CronTask
        {
            public function setVolume(int $n): void
            {
            }
        }
    }
}

namespace GlpiPlugin\Glpimajor {

    /**
     * The real Incident extends CommonDBTM and reaches the database in almost
     * every method; the pure code only ever names its four state constants.
     */
    class Incident
    {
        public const INVESTIGATING = 'investigating';
        public const IDENTIFIED    = 'identified';
        public const MONITORING    = 'monitoring';
        public const RESOLVED      = 'resolved';
    }

    /** Same, for the two window states the renderer distinguishes. */
    class Maintenance
    {
        public const SCHEDULED   = 'scheduled';
        public const IN_PROGRESS = 'in_progress';
        public const COMPLETED   = 'completed';
        public const CANCELLED   = 'cancelled';
    }
}

namespace {

    /** @var string[] */
    $GLOBALS['glpimajor_failures'] = [];

    function check(string $name, bool $ok, string $detail = ''): void
    {
        echo ($ok ? "  \033[32mPASS\033[0m  " : "  \033[31mFAIL\033[0m  ") . $name
           . ($detail !== '' ? " :: $detail" : '') . "\n";

        if (!$ok) {
            $GLOBALS['glpimajor_failures'][] = $name;
        }
    }

    function section(string $title): void
    {
        echo "\n\033[1m$title\033[0m\n";
    }

    function finish(): never
    {
        $failures = $GLOBALS['glpimajor_failures'];

        echo "\n" . ($failures === []
            ? "\033[32mall checks passed\033[0m\n"
            : "\033[31mFAILED\033[0m: " . implode('; ', $failures) . "\n");

        exit($failures === [] ? 0 : 1);
    }
}
