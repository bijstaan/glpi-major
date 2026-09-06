<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The list of major incidents.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpimajor\Incident;
use GlpiPlugin\Glpinav\Nav;

Session::checkRight(Incident::$rightname, READ);

Html::header(
    Incident::getTypeName(2),
    $_SERVER['PHP_SELF'],
    class_exists(Nav::class) ? Nav::sector(Incident::class, 'config') : 'config',
    // The itemtype, not 'plugins'. This is what makes GLPI render its own
    // breadcrumb and action links for the list — passing a menu key instead
    // gives a list with no way to act on it, because the buttons come from
    // whatever this argument names.
    Incident::class
);

Search::show(Incident::class);

Html::footer();
