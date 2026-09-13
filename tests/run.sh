#!/bin/sh
# The pure suites. None of them needs a database, a session or a network.
#
# Run inside the GLPI container, from the plugin directory:
#   docker compose -p glpi exec glpi sh -c 'cd /var/www/glpi/plugins/glpimajor && tests/run.sh'
#
# Or one at a time:
#   docker exec glpi-glpi-1 php /var/www/glpi/plugins/glpimajor/tests/nag.php
#
# tests/stubs.php supplies the handful of GLPI symbols the code under test needs
# in order to *load* — the four state constants, __(), and two empty base
# classes. Anything else a suite turns out to need is a sign the code has grown
# a dependency it was not supposed to have, and the file failing to load is the
# intended alarm.
#
# WHAT THIS SCRIPT DOES NOT RUN
#
# tests/db-live.php is the other half, and it is not started from here on
# purpose. It writes: an entity tree of its own — two unrelated organisations, and
# a third with two offices under it — seven tickets inside them, two incidents,
# four status pages, maintenance windows and a review, and it removes all of it
# on the way out, including on a fatal. It also flips `status_enabled` for the
# length of the run, snapshotting and restoring it as a raw `glpi_configs` row
# rather than through Config::setConfigurationValues(), which re-encrypts every
# secure field in a context on the way back in.
#
# It covers everything the three pure suites cannot reach, which is most of what
# would actually hurt someone: that declaring is gated on a right, that the
# matcher and the attach both refuse to cross a tenant boundary, that resolution
# *proposes* a solution on attached tickets and changes no ticket's status and
# assigns nobody, that the update log keeps its audiences apart and the
# published file has never held an internal sentence, that regenerating an
# address retires the old file at once, and that a completed review locks and
# the lock has a recorded key.
#
# Since 2026-08-22 it also covers the property the recursion feature turns on:
# that an incident marked as covering sub-entities reaches every page, ticket
# and portal underneath the entity it was declared in, and that a sibling
# entity's page, tickets and portal see none of it — asserted from both
# directions rather than only from the covered side.
#
# It needs the plugin installed and active, and an instance you are willing to
# have written to. Run it deliberately:
#
#     docker exec glpi-glpi-1 php \
#       /var/www/glpi/plugins/glpimajor/tests/db-live.php --yes-i-mean-it
#
# Without the flag it refuses to start, so a runner that does not know what it
# does cannot begin it by accident.
#
# The browser check is a third thing again, and lives with the other plugins':
#
#     cd glpi-major/tests/browser && bash major-setup.sh
#     SHOT_DIR=../../docs/screenshots node major-check.js
set -e

cd "$(dirname "$0")/.."

status=0
php tests/nag.php        || status=1
php tests/matching.php   || status=1
php tests/statuspage.php || status=1
php tests/feed.php       || status=1

if [ "$status" -eq 0 ]; then
    echo
    echo "The pure suites passed. tests/db-live.php is not run from here — see the note above."
fi

exit $status
