<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The published page, shown to an administrator.
 *
 * Serves the *file*, not a fresh render. A preview that re-rendered would be a
 * different document from the one a customer is reading — subtly, whenever
 * something changed between the last publish and now — and the whole question
 * a preview answers is "what does the customer see".
 *
 * Authenticated, unlike front/status.php: this is reached from the settings
 * page, by somebody who is already logged in, and it exists so an administrator
 * does not have to open the public address in a private window to check.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpimajor\Page;

Session::checkRight('plugin_glpimajor_config', READ);

$entities_id = (int) ($_GET['entities_id'] ?? -1);

$row = $entities_id >= 0 ? Page::forEntity($entities_id) : null;

if ($row === null || (string) $row['token'] === '' || !Page::isTokenShaped((string) $row['token'])) {
    Html::header(__('Status page', 'glpimajor'), $_SERVER['PHP_SELF'], 'config', 'plugins');
    echo "<div class='alert alert-info'>"
       . __s('That entity has no published status page.', 'glpimajor')
       . '</div>';
    Html::footer();
    exit;
}

// Same resolver the public endpoint uses — shape check plus realpath
// containment — so both readers of these files agree on what a token may name.
$file = Page::resolveFile((string) $row['token']);

if ($file === null) {
    Html::header(__('Status page', 'glpimajor'), $_SERVER['PHP_SELF'], 'config', 'plugins');
    echo "<div class='alert alert-warning'>"
       . __s('The address exists but nothing has been published to it yet. Press Republish on the '
           . 'settings page.', 'glpimajor')
       . '</div>';
    Html::footer();
    exit;
}

// Sent as the artefact it is, with no GLPI chrome around it. Framing it inside
// the admin layout would restyle it, which would defeat the point.
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

readfile($file);
exit;
