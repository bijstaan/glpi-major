<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The status page, inside the self-service interface.
 *
 * The signed-in counterpart to `status.php`. That one serves a flat file to a
 * stranger holding an address and never touches the database; this one is an
 * ordinary session-backed page that answers about the organisation the reader
 * already belongs to.
 *
 * There is **no id in the URL**. The organisation comes from the session, so
 * there is no parameter to tamper with and nothing to enumerate.
 *
 * A reader with no page to see gets the interface's own not-found, not a
 * permission error. "You may not see this" would confirm that their
 * organisation has a status page and that somebody decided they should not read
 * it, which is a sentence a customer should never be shown by accident.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpimajor\PortalStatus;

Session::checkLoginUser();

$page = PortalStatus::pageForReader();

if ($page === null) {
    Html::helpHeader(__('Service status', 'glpimajor'));
    Html::displayNotFoundError();
}

Html::helpHeader(__('Service status', 'glpimajor'));

PortalStatus::show($page);

Html::helpFooter();
