<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * One maintenance window.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpimajor\Maintenance;
use GlpiPlugin\Glpinav\Nav;

Session::checkRight(Maintenance::$rightname, READ);

$window = new Maintenance();

if (!empty($_POST['add'])) {
    $window->check(-1, CREATE, $_POST);
    $newid = $window->add($_POST);

    // Back to the form on a rejection rather than on to `?id=0`. A refused add
    // has already queued its reason as a session message, and redirecting to an
    // id that does not exist renders an error page that swallows it.
    if ($newid === false) {
        Html::back();
    }

    Html::redirect($window->getFormURLWithID($newid));
} elseif (!empty($_POST['update'])) {
    $window->check($_POST['id'], UPDATE);
    $window->update($_POST);
    Html::back();
} elseif (!empty($_POST['purge'])) {
    $window->check($_POST['id'], PURGE);
    $window->delete($_POST, true);
    $window->redirectToList();
}

// GLPI derives the itemtype from the URL as `pluginglpimajormaintenance`, which
// does not resolve to a namespaced plugin class — so forcetab is dropped and
// both GLPI's own tab links and every post-edit redirect land on the wrong tab.
// Setting it explicitly is the same fix core applies in front/knowbaseitem.php.
if (isset($_GET['forcetab'])) {
    Session::setActiveTab(Maintenance::class, $_GET['forcetab']);
}

Html::header(
    Maintenance::getTypeName(1),
    $_SERVER['PHP_SELF'],
    class_exists(Nav::class) ? Nav::sector(Maintenance::class, 'config') : 'config',
    Maintenance::class
);

$window->display(['id' => (int) ($_GET['id'] ?? -1)]);

Html::footer();
