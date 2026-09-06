<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The public status page.
 *
 * Reached as `/plugins/glpimajor/front/status.php/<token>`, which is the
 * address an administrator hands a customer. No session, no cookie, no login —
 * see the firewall strategy and the stateless-path registration in setup.php.
 *
 * This script does not query the database. Not "queries it carefully", not
 * "queries it with an entity restriction" — it does not query it. The published
 * page is a file whose *name* is the token, so serving it is: check the token's
 * shape, open that filename, send the bytes. Tenant isolation is therefore
 * structural rather than a WHERE clause somebody has to remember, and no
 * malformed request can reach anything that knows what a ticket is.
 *
 * A wrong or retired token is a 404 with nothing in it. Anything else — "no
 * such page for this customer", a redirect, a differently-worded error — tells
 * somebody guessing addresses whether they are getting warmer.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpimajor\Page;
use GlpiPlugin\Glpimajor\Url;

/** Send a bare 404 and stop. */
$missing = static function (): never {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo "Not found\n";
    exit;
};

// GLPI 11 routes everything through one front controller and does not populate
// PATH_INFO by the time a legacy plugin script runs, so the token is dug out of
// REQUEST_URI. `?t=` is accepted too, for a web server configuration that
// mangles the path — the address is the same secret either way.
$token = trim(Url::pathAfter('status.php'), '/');
if ($token === '') {
    $token = (string) ($_GET['t'] ?? '');
}

if (!Page::isTokenShaped($token)) {
    $missing();
}

// Shape-checked above and containment-checked inside resolveFile(): the token
// is 48 hex characters or the request is a 404, and the resolved path has to
// sit inside the status directory or nothing is served. Two independent guards
// on the one filename this endpoint ever opens.
$file = Page::resolveFile($token);
if ($file === null) {
    $missing();
}

$bytes    = (int) filesize($file);
$modified = (int) filemtime($file);
// A cache validator, not a secret: sha1 here is a cheap fingerprint of
// (token, mtime, size) so a refresh during an outage costs a 304 instead of a
// read. Nothing is authenticated by it and nothing is derived from it.
$etag     = '"' . substr(sha1($token . '|' . $modified . '|' . $bytes), 0, 32) . '"';

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
// A status page must not turn up in search results next to the customer's name.
header('X-Robots-Tag: noindex, nofollow');
// The address is the secret, so nothing about this page should be framed or
// embedded into somebody else's.
header('Content-Security-Policy: default-src \'none\'; img-src data: https: http:; '
     . 'style-src \'unsafe-inline\'; frame-ancestors \'none\'; form-action \'none\'');
header('Referrer-Policy: no-referrer');

// A minute. Long enough that a room full of people refreshing during an outage
// costs one read rather than four hundred; short enough that the next update
// reaches them before they give up and telephone.
header('Cache-Control: public, max-age=60');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
header('ETag: ' . $etag);

$if_none_match     = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
$if_modified_since = trim((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));

$unchanged = ($if_none_match !== '' && $if_none_match === $etag)
    || ($if_none_match === '' && $if_modified_since !== '' && strtotime($if_modified_since) >= $modified);

if ($unchanged) {
    http_response_code(304);
    exit;
}

header('Content-Length: ' . $bytes);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

readfile($file);
exit;
