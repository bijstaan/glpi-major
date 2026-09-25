<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * One major incident: its roles, what we have said, who else is waiting, and
 * the review.
 *
 * Declaring happens here too, reached from a ticket as
 * `incident.form.php?tickets_id=<id>&declare=1`. There is no blank-form path
 * that makes sense — an incident with no ticket has nothing to command — so the
 * declare button on a ticket posts here rather than opening an empty form.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpimajor\Incident;
use GlpiPlugin\Glpimajor\View;
use GlpiPlugin\Glpinav\Nav;

Session::checkRight(Incident::$rightname, READ);

$incident = new Incident();

if (!empty($_POST['declare'])) {
    // Declaring is its own right and its own action, not an ordinary add: it
    // reads the ticket for the entity and the default title rather than
    // trusting either from the form.
    Session::checkRight(Incident::$rightname, CREATE);

    $ticket = new Ticket();
    if (!$ticket->getFromDB((int) ($_POST['tickets_id'] ?? 0))) {
        Html::back();
    }

    if (!$ticket->can($ticket->getID(), UPDATE)) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }

    $id = Incident::declareFor($ticket, [
        'name'               => (string) ($_POST['name'] ?? ''),
        'users_id_commander' => (int) ($_POST['users_id_commander'] ?? 0),
        'users_id_comms'     => (int) ($_POST['users_id_comms'] ?? 0),
        // Only ever what the control actually offered. It is drawn only when
        // the entity has sub-entities, so a POST claiming coverage for an
        // entity with none is stored and covers nothing, which is the correct
        // amount of nothing.
        'is_recursive'       => !empty($_POST['is_recursive']) ? 1 : 0,
    ]);

    if ($id === false) {
        Html::back();
    }

    Html::redirect($incident->getFormURLWithID($id));
} elseif (!empty($_POST['add'])) {
    $incident->check(-1, CREATE, $_POST);
    $newid = $incident->add($_POST);

    if ($newid === false) {
        Html::back();
    }

    Html::redirect($incident->getFormURLWithID($newid));
} elseif (!empty($_POST['update'])) {
    $incident->check($_POST['id'], UPDATE);
    $incident->getFromDB((int) $_POST['id']);

    // State is moved through setState() rather than written straight to the
    // row: resolution has consequences — the outcome check, the proposed
    // solutions, the procedure, the notification — and a form that wrote the
    // column directly would skip every one of them.
    $wanted  = (string) ($_POST['state'] ?? $incident->fields['state']);
    $outcome = (string) ($_POST['outcome'] ?? '');
    unset($_POST['state']);

    $incident->update($_POST);
    $incident->getFromDB((int) $_POST['id']);

    if ($wanted !== (string) $incident->fields['state']) {
        $incident->setState($wanted, $outcome);
    }

    Html::back();
} elseif (!empty($_POST['purge'])) {
    $incident->check($_POST['id'], PURGE);
    $incident->delete($_POST, true);
    $incident->redirectToList();
}

// GLPI derives the itemtype from the URL as `pluginglpimajorincident`, which
// does not resolve to a namespaced plugin class — so forcetab is dropped and
// both GLPI's own tab links and every post-edit redirect land on the wrong tab.
// Setting it explicitly is the same fix core applies in front/knowbaseitem.php.
if (isset($_GET['forcetab'])) {
    Session::setActiveTab(Incident::class, $_GET['forcetab']);
}

Html::header(
    Incident::getTypeName(1),
    $_SERVER['PHP_SELF'],
    class_exists(Nav::class) ? Nav::sector(Incident::class, 'config') : 'config',
    Incident::class
);

$id = (int) ($_GET['id'] ?? -1);

if ($id > 0 && $incident->getFromDB($id)) {
    // The cockpit first: the war room's questions — what is it, what state,
    // how long, who knows — before any form field. The stock GLPI form
    // (title, roles, coverage, outcome, and its tabs) collapses behind an
    // "Edit details" summary rather than moving to a page of its own: its
    // fields are edited a handful of times per incident while the cockpit
    // and the feed are read continuously, and a collapsed <details> keeps
    // it one click away with zero navigation.
    View::cockpit($incident);

    echo "<details class='glpimajor-editdetails'><summary>"
       . __s('Edit details', 'glpimajor') . '</summary>';
    $incident->display(['id' => $id]);
    echo '</details>';

    View::panels($incident);
} else {
    $incident->display(['id' => $id]);
}

Html::footer();
