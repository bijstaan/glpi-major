<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Scheduled maintenance windows.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpimajor\Maintenance;
use GlpiPlugin\Glpinav\Nav;

Session::checkRight(Maintenance::$rightname, READ);

Html::header(
    Maintenance::getTypeName(2),
    $_SERVER['PHP_SELF'],
    class_exists(Nav::class) ? Nav::sector(Maintenance::class, 'config') : 'config',
    Maintenance::class
);

Search::show(Maintenance::class);

Html::footer();
