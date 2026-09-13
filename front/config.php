<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Major-incident settings.
 *
 * Several forms rather than one, because the page manages objects as well as
 * settings — snippets and status-page addresses are rows, and a single Save
 * that rewrote everything from one card's POST would be a trap. Each form posts
 * a hidden `section` and the handler writes only that section's keys: a
 * checkbox absent from a POST is indistinguishable from an unticked one, so
 * rebuilding all settings from a card that never contained them would silently
 * reset the rest of the page.
 *
 * A POST naming no recognised section is refused rather than guessed at.
 */

require_once(__DIR__ . '/../../../front/_check_webserver_config.php');

use GlpiPlugin\Glpimajor\Brand;
use GlpiPlugin\Glpimajor\Improve;
use GlpiPlugin\Glpimajor\Incident;
use GlpiPlugin\Glpimajor\Maintenance;
use GlpiPlugin\Glpimajor\Nag;
use GlpiPlugin\Glpimajor\Page;
use GlpiPlugin\Glpimajor\Publisher;
use GlpiPlugin\Glpimajor\Review;
use GlpiPlugin\Glpimajor\Settings;
use GlpiPlugin\Glpimajor\Snippet;
use GlpiPlugin\Glpimajor\Sop;
use GlpiPlugin\Glpimajor\Update;
use GlpiPlugin\Glpimajor\Url;

Session::checkRight('plugin_glpimajor_config', READ);

/**
 * Values this page corrected, reported after saving.
 *
 * Held in $GLOBALS explicitly, and read back the same way. GLPI 11 loads a
 * legacy front script with `require` from *inside*
 * Glpi\Controller\LegacyFileLoadController::__invoke(), so this file's "top
 * level" is that method's local scope — not global scope. A plain `$clamped`
 * here and a `global $clamped` in the function below would therefore be two
 * different variables, and every clamp would be recorded into one and reported
 * from the other: the correction would happen silently, which is the exact
 * failure the reporting exists to prevent. This is also why every file in this
 * suite writes `global $DB;` even at what looks like file scope.
 */
$GLOBALS['glpimajor_clamped'] = [];

/**
 * A posted number, clamped into range, with the clamp reported.
 *
 * Not `min`/`max` attributes on the input. Those look like validation and
 * behave like a trap: the browser refuses to submit, scrolls to the field and
 * shows a tooltip for a couple of seconds — on a page this long the field is
 * frequently off-screen, so the whole event reads as "I pressed Save and
 * nothing happened". A silent refusal is worse than a wrong value, because a
 * wrong value can at least be seen.
 */
function boundedInt(string $key, int $min, int $max, int $default, string $label): int
{
    $raw = $_POST[$key] ?? null;
    if ($raw === null || $raw === '') {
        return $default;
    }

    $value   = (int) $raw;
    $bounded = max($min, min($max, $value));

    if ($bounded !== $value) {
        $GLOBALS['glpimajor_clamped'][] = sprintf(
            __('%1$s was set to %2$d, which is outside the permitted range; %3$d was used.', 'glpimajor'),
            $label,
            $value,
            $bounded
        );
    }

    return $bounded;
}

if (!empty($_POST['_glpimajor_section'])) {
    // No explicit Session::checkCSRF: GLPI 11's CheckCsrfListener validated and
    // consumed the token before this page ran, so a second check always fails.
    // The hidden field in the form is what matters.
    Session::checkRight('plugin_glpimajor_config', UPDATE);

    $section = (string) $_POST['_glpimajor_section'];

    switch ($section) {
        case 'settings':
            Settings::save([
                'match_enabled'       => !empty($_POST['match_enabled']) ? '1' : '0',
                'match_same_category' => !empty($_POST['match_same_category']) ? '1' : '0',
                'match_same_location' => !empty($_POST['match_same_location']) ? '1' : '0',
                'match_keywords'      => !empty($_POST['match_keywords']) ? '1' : '0',
                'match_window_hours'  => boundedInt('match_window_hours', 1, 8760, 72,
                    __('Offer incidents declared within', 'glpimajor')),
                'match_min_word'      => boundedInt('match_min_word', 3, 20, 5,
                    __('Shortest word that counts as a match', 'glpimajor')),

                'nag_enabled'        => !empty($_POST['nag_enabled']) ? '1' : '0',
                'nag_grace_minutes'  => boundedInt('nag_grace_minutes', 0, 1440, 5,
                    __('Grace period', 'glpimajor')),
                'nag_repeat_minutes' => boundedInt('nag_repeat_minutes', 5, 1440, 30,
                    __('Remind again after', 'glpimajor')),
                'update_interval'    => boundedInt('update_interval', 5, 1440, 60,
                    __('Default next-update interval', 'glpimajor')),

                'ai_review_enabled' => !empty($_POST['ai_review_enabled']) ? '1' : '0',

                'status_enabled'       => !empty($_POST['status_enabled']) ? '1' : '0',
                'status_timezone'      => trim((string) ($_POST['status_timezone'] ?? '')),
                'status_page_title'    => trim((string) ($_POST['status_page_title'] ?? '')),
                'status_support_email' => trim((string) ($_POST['status_support_email'] ?? '')),
                'status_support_phone' => trim((string) ($_POST['status_support_phone'] ?? '')),
                'status_history_days'  => boundedInt('status_history_days', 1, 365, 30,
                    __('Keep resolved incidents on the page for', 'glpimajor')),
                'status_embed_logo'    => !empty($_POST['status_embed_logo']) ? '1' : '0',
                'status_logo_max_kb'   => boundedInt('status_logo_max_kb', 8, 2048, 256,
                    __('Embed the logo when it is under', 'glpimajor')),
                'status_max_age_min'   => boundedInt('status_max_age_min', 5, 1440, 60,
                    __('Republish anything older than', 'glpimajor')),

                'recursive_default' => !empty($_POST['recursive_default']) ? '1' : '0',
                'portal_banner'     => !empty($_POST['portal_banner']) ? '1' : '0',
                'portal_link'       => !empty($_POST['portal_link']) ? '1' : '0',

                'pir_required'         => !empty($_POST['pir_required']) ? '1' : '0',
                'pir_lock_on_complete' => !empty($_POST['pir_lock_on_complete']) ? '1' : '0',
                'pir_sops_id'          => (int) ($_POST['pir_sops_id'] ?? 0),
                'improve_push'         => !empty($_POST['improve_push']) ? '1' : '0',

                'events_retention_days' => boundedInt('events_retention_days', 30, 3650, 730,
                    __('Keep the audit trail for', 'glpimajor')),
            ]);

            Session::addMessageAfterRedirect(__s('Settings saved.', 'glpimajor'));
            break;

        case 'snippet':
            $id        = (int) ($_POST['snippet_id'] ?? 0);
            $recursive = !empty($_POST['snippet_recursive']);

            if (!empty($_POST['snippet_delete']) && $id > 0) {
                Snippet::delete($id);
                Session::addMessageAfterRedirect(__s('Template removed.', 'glpimajor'));
            } elseif ($id > 0) {
                Snippet::update(
                    $id,
                    (string) ($_POST['snippet_name'] ?? ''),
                    (string) ($_POST['snippet_audience'] ?? Update::EXTERNAL),
                    (string) ($_POST['snippet_content'] ?? ''),
                    $recursive
                );
                Session::addMessageAfterRedirect(__s('Template saved.', 'glpimajor'));
            } else {
                $created = Snippet::add(
                    (int) ($_POST['snippet_entity'] ?? 0),
                    (string) ($_POST['snippet_name'] ?? ''),
                    (string) ($_POST['snippet_audience'] ?? Update::EXTERNAL),
                    (string) ($_POST['snippet_content'] ?? ''),
                    $recursive
                );

                Session::addMessageAfterRedirect(
                    $created === false
                        ? __s('A template needs a name.', 'glpimajor')
                        : __s('Template added.', 'glpimajor'),
                    false,
                    $created === false ? ERROR : INFO
                );
            }
            break;

        case 'page':
            $entities_id = (int) ($_POST['page_entity'] ?? -1);
            if ($entities_id < 0) {
                Session::addMessageAfterRedirect(
                    __s('Choose an entity first.', 'glpimajor'),
                    false,
                    ERROR
                );
                break;
            }

            if (!empty($_POST['page_revoke'])) {
                Page::revoke($entities_id);
                Session::addMessageAfterRedirect(
                    __s('The address was revoked. Anyone holding the old link now gets nothing.', 'glpimajor')
                );
                break;
            }

            if (!empty($_POST['page_details'])) {
                /** @var DBmysql $DB */
                global $DB;

                $existing = Page::forEntity($entities_id);
                if ($existing !== null) {
                    $DB->update(Page::getTable(), [
                        'page_title'   => trim((string) ($_POST['page_title'] ?? '')),
                        'support_note' => trim((string) ($_POST['page_note'] ?? '')),
                        'date_mod'     => date('Y-m-d H:i:s'),
                    ], ['id' => (int) $existing['id']]);

                    Publisher::publish($entities_id);
                    Session::addMessageAfterRedirect(__s('Page details saved and republished.', 'glpimajor'));
                }
                break;
            }

            if (!empty($_POST['page_publish'])) {
                Publisher::publish($entities_id);
                Session::addMessageAfterRedirect(__s('Republished.', 'glpimajor'));
                break;
            }

            Page::mint($entities_id);
            Session::addMessageAfterRedirect(
                Settings::flag('status_enabled')
                    ? __s('A new address was generated and the page published.', 'glpimajor')
                    : __s('A new address was generated. Nothing is published until you switch '
                        . 'publishing on above.', 'glpimajor')
            );
            break;

        default:
            // Refused rather than guessed at. A POST from a form this page does
            // not recognise is either a bug or somebody else's request, and
            // writing settings on the strength of either is how a page silently
            // resets what it did not contain.
            Session::addMessageAfterRedirect(
                __s('That form was not recognised, so nothing was changed.', 'glpimajor'),
                false,
                ERROR
            );
    }

    // Said out loud. A value quietly corrected is a value the administrator
    // still believes they set.
    foreach ($GLOBALS['glpimajor_clamped'] as $note) {
        Session::addMessageAfterRedirect(htmlspecialchars($note), false, WARNING);
    }

    Html::back();
}

Html::header(__('Major incidents', 'glpimajor'), $_SERVER['PHP_SELF'], 'config', 'plugins');

$cfg      = Settings::all();
$e        = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$can_edit = Session::haveRight('plugin_glpimajor_config', UPDATE);

// Helper text has to stay readable on the dark palette. This used to be an
// inline <style> here with a `color:` override — a silent no-op, because core
// declares `.text-muted { color: var(--tblr-muted) !important; }` and no
// non-!important override beats it (found 2026-08-22 by the glpi-kedb dark
// pass). The real fix redefines the --tblr-muted *variable*, scoped to
// `.glpimajor-config` alongside every other surface this plugin ships — see
// public/css/major.css's dark-theme section, one rule for the whole plugin.

echo "<div class='container-fluid glpimajor-config' style='max-width:960px'>";

if (!$can_edit) {
    echo "<div class='alert alert-info py-2'>"
       . __s('Read only: you can see these settings but not change them.', 'glpimajor')
       . '</div>';
}

// ==================================================================== status
//
// Status first. This plugin can say whether it is actually working — whether
// anything is open, whether each entity's page is live and fresh, and whether
// the reminder cron has run — so that leads the page.

$open  = Incident::allOpen();
$pages = Page::all();

$live = 0;
$stale = 0;
$errored = 0;
$max_age = ((int) $cfg['status_max_age_min']) * 60;

foreach ($pages as $row) {
    if ((int) $row['is_enabled'] !== 1 || (string) $row['token'] === '') {
        continue;
    }
    $live++;
    if (trim((string) ($row['last_error'] ?? '')) !== '') {
        $errored++;
        continue;
    }
    $generated = (string) ($row['date_generated'] ?? '');
    $stamp     = $generated !== '' ? strtotime($generated) : false;
    if ($stamp === false || (time() - $stamp) > ($max_age * 2)) {
        $stale++;
    }
}

$publishing = ((int) $cfg['status_enabled']) === 1;
$tone = 'secondary';
if ($publishing && $errored === 0 && $stale === 0 && $live > 0) {
    $tone = 'success';
} elseif ($publishing && ($errored > 0 || $stale > 0)) {
    $tone = 'warning';
} elseif ($publishing) {
    $tone = 'warning';
}

echo "<div class='alert alert-$tone d-flex align-items-start'>";
echo "<i class='ti " . ($tone === 'success' ? 'ti-circle-check' : ($tone === 'warning' ? 'ti-alert-triangle' : 'ti-circle-dashed'))
   . " me-2 fs-3'></i><div>";

if (!$publishing) {
    echo '<strong>' . __s('Status pages are switched off.', 'glpimajor') . '</strong>';
    echo "<div class='small'>"
       . __s('Nothing is written to disk and nothing is served publicly. Incidents, comms and '
           . 'reviews all work; only publishing is off.', 'glpimajor')
       . '</div>';
} elseif ($live === 0) {
    echo '<strong>' . __s('Publishing is on, and no entity has an address yet.', 'glpimajor') . '</strong>';
    echo "<div class='small'>"
       . __s('Generate one below for each entity whose people should be able to read a status '
           . 'page. Until then nothing is published for anybody.', 'glpimajor')
       . '</div>';
} elseif ($errored > 0) {
    echo '<strong>' . sprintf(
        __s('%d status page(s) could not be written.', 'glpimajor'),
        $errored
    ) . '</strong>';
    echo "<div class='small'>"
       . __s('The reason is shown against each page below. A reader is reading whatever was '
           . 'published last.', 'glpimajor')
       . '</div>';
} elseif ($stale > 0) {
    echo '<strong>' . sprintf(__s('%d status page(s) look stale.', 'glpimajor'), $stale) . '</strong>';
    echo "<div class='small'>"
       . __s('Pages regenerate on every public change, with a cron as a backstop. If '
           . 'these stay stale, check that GLPI\'s automatic actions are running.', 'glpimajor')
       . '</div>';
} else {
    echo '<strong>' . sprintf(
        __s('%d status page(s) published and current.', 'glpimajor'),
        $live
    ) . '</strong>';
    echo "<div class='small'>"
       . __s('Pages are static files regenerated on change. The public endpoint never queries '
           . 'GLPI\'s data.', 'glpimajor')
       . '</div>';
}

echo '</div></div>';

// Open incidents, because an administrator arriving here during an outage
// should not have to go and find them.
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Open right now', 'glpimajor') . '</h3></div><div class="card-body">';

if ($open === []) {
    echo "<p class='text-muted mb-0'>" . __s('Nothing is open.', 'glpimajor') . '</p>';
} else {
    echo "<table class='table table-sm mb-0'><tbody>";
    foreach ($open as $row) {
        $late = Nag::overdueMinutes($row['next_update_at'] ?? null, time());
        echo '<tr>';
        echo "<td><a href='" . $e(Url::to('front/incident.form.php?id=' . (int) $row['id'])) . "'>"
           . $e($row['name']) . '</a></td>';
        echo '<td>' . $e(Incident::stateLabel((string) $row['state'])) . '</td>';
        echo '<td>' . sprintf(__s('%d affected', 'glpimajor'), (int) $row['affected_count']) . '</td>';
        echo '<td>';
        if (($row['next_update_at'] ?? '') === '' || $row['next_update_at'] === null) {
            echo "<span class='text-danger'>" . __s('no update promised', 'glpimajor') . '</span>';
        } elseif ($late > 0) {
            echo "<span class='text-danger'>"
               . sprintf(__s('%d min overdue', 'glpimajor'), $late) . '</span>';
        } else {
            echo $e(Html::convDateTime((string) $row['next_update_at']));
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
}
echo '</div></div>';

// ------------------------------------------------------------- section nav
$sections = [
    'pages'    => __s('Status pages', 'glpimajor'),
    'matching' => __s('Duplicate matching', 'glpimajor'),
    'comms'    => __s('Comms', 'glpimajor'),
    'snippets' => __s('Templates', 'glpimajor'),
    'review'   => __s('Post-incident review', 'glpimajor'),
];

echo "<div class='mb-3 d-flex gap-2 flex-wrap'>";
foreach ($sections as $anchor => $label) {
    echo "<a class='btn btn-sm btn-outline-secondary' href='#glpimajor-" . $e($anchor) . "'>"
       . $label . '</a>';
}
echo '</div>';

// ============================================================ status pages
//
// Its own form: minting and revoking an address are actions on a row, not
// settings, and they must not travel with the Save button on the settings card.

echo "<div id='glpimajor-pages' class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Status page addresses', 'glpimajor') . '</h3></div><div class="card-body">';

echo '<p class="text-muted">'
   . __s('Each entity gets one page at an unguessable address. The page is a static file '
       . 'regenerated whenever something public changes; the public endpoint serves the '
       . 'file and never queries GLPI. Regenerating an address retires the old one immediately.',
       'glpimajor')
   . '</p>';

if ($pages === []) {
    echo '<p class="text-muted">' . __s('No entity has a page yet.', 'glpimajor') . '</p>';
} else {
    echo "<table class='table table-sm'><thead><tr>"
       . '<th>' . __s('Entity') . '</th>'
       . '<th>' . __s('Address', 'glpimajor') . '</th>'
       . '<th>' . __s('Last published', 'glpimajor') . '</th>'
       . '<th></th></tr></thead><tbody>';

    foreach ($pages as $row) {
        $entity = new Entity();
        $name   = $entity->getFromDB((int) $row['entities_id'])
            ? (string) $entity->fields['completename']
            : (string) $row['entities_id'];

        $url = Page::publicUrl($row);

        echo '<tr>';
        echo '<td>' . $e($name) . '</td>';
        echo '<td>';
        if ($url === '') {
            echo "<span class='text-muted'>" . __s('revoked', 'glpimajor') . '</span>';
        } else {
            echo "<code class='small'>" . $e($url) . '</code>';
        }
        echo '</td>';
        echo '<td>';
        $err = trim((string) ($row['last_error'] ?? ''));
        if ($err !== '') {
            echo "<span class='text-danger'>" . $e($err) . '</span>';
        } elseif (($row['date_generated'] ?? null) === null) {
            echo "<span class='text-muted'>" . __s('never', 'glpimajor') . '</span>';
        } else {
            echo $e(Html::convDateTime((string) $row['date_generated']))
               . " <span class='text-muted small'>(" . (int) $row['bytes'] . ' B)</span>';
        }
        echo '</td>';
        echo '<td>';
        if ($url !== '') {
            echo "<a class='btn btn-sm btn-outline-secondary' target='_blank' rel='noopener' href='"
               . $e(Url::to('front/preview.php?entities_id=' . (int) $row['entities_id'])) . "'>"
               . __s('Preview', 'glpimajor') . '</a> ';
        }
        if ($can_edit) {
            echo "<form method='post' class='d-inline'>";
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo Html::hidden('_glpimajor_section', ['value' => 'page']);
            echo Html::hidden('page_entity', ['value' => (int) $row['entities_id']]);
            echo "<button class='btn btn-sm btn-outline-secondary' name='page_publish' value='1'>"
               . __s('Republish', 'glpimajor') . '</button> ';
            echo "<button class='btn btn-sm btn-outline-secondary' name='page_regenerate' value='1'>"
               . __s('New address', 'glpimajor') . '</button> ';
            if ($url !== '') {
                echo "<button class='btn btn-sm btn-outline-danger' name='page_revoke' value='1'>"
                   . __s('Revoke', 'glpimajor') . '</button>';
            }
            echo '</form>';
        }
        echo '</td></tr>';

        // The two things about a page that are per-entity rather than
        // per-instance. They have their own form because they are a property of
        // this row, and folding them into the settings card would mean one Save
        // button writing both instance settings and one entity's page.
        //
        // Behind a <details> because the address and when it last published are
        // what somebody opens this card to see; the wording is what they came
        // back for later.
        if ($can_edit) {
            echo "<tr class='glpimajor-page-details'><td colspan='4'>";
            echo "<details><summary class='text-muted small'>"
               . __s('Page title and support note for this entity', 'glpimajor')
               . '</summary>';
            echo "<form method='post' class='row g-2 align-items-end mt-1'>";
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo Html::hidden('_glpimajor_section', ['value' => 'page']);
            echo Html::hidden('page_entity', ['value' => (int) $row['entities_id']]);

            echo "<div class='col-md-5'><label class='form-label'>"
               . __s('Page title', 'glpimajor') . '</label>';
            echo "<input type='text' class='form-control form-control-sm' name='page_title' value='"
               . $e($row['page_title'] ?? '') . "' placeholder='" . $e(Brand::title()) . "'>";
            echo "<div class='form-text'>"
               . __s('What readers see at the top of this entity\'s page. Empty falls back to the '
                   . 'instance-wide title.', 'glpimajor')
               . '</div></div>';

            echo "<div class='col-md-5'><label class='form-label'>"
               . __s('Support note', 'glpimajor') . '</label>';
            echo "<input type='text' class='form-control form-control-sm' name='page_note' value='"
               . $e($row['support_note'] ?? '') . "' placeholder='"
               . __s('Our service desk is open 08:00–18:00, Monday to Friday.', 'glpimajor') . "'>";
            echo "<div class='form-text'>"
               . __s('One line in the footer, next to the support contact.', 'glpimajor')
               . '</div></div>';

            echo "<div class='col-md-2'><button class='btn btn-sm btn-outline-secondary w-100' "
               . "name='page_details' value='1'>" . __s('Save') . '</button></div>';
            echo '</form></details></td></tr>';
        }
    }

    echo '</tbody></table>';
}

if ($can_edit) {
    echo "<form method='post' class='row g-2 align-items-end border-top pt-3'>";
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo Html::hidden('_glpimajor_section', ['value' => 'page']);
    echo "<div class='col-md-6'><label class='form-label'>"
       . __s('Give an entity a status page', 'glpimajor') . '</label>';
    Entity::dropdown(['name' => 'page_entity', 'value' => 0, 'width' => '100%']);
    echo '</div>';
    echo "<div class='col-md-3'><button class='btn btn-primary w-100' name='page_create' value='1'>"
       . __s('Generate an address', 'glpimajor') . '</button></div>';
    echo '</form>';
}

echo '</div></div>';

// ==================================================================== settings
//
// One form, one Save, for everything that is genuinely a setting.

echo "<form method='post'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo Html::hidden('_glpimajor_section', ['value' => 'settings']);

// ----------------------------------------------------------- page content
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('What the status page says', 'glpimajor') . '</h3></div><div class="card-body">';

echo "<label class='form-check mb-2'>";
echo "<input type='checkbox' class='form-check-input' name='status_enabled' value='1' "
   . (((int) $cfg['status_enabled']) === 1 ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>" . __s('Publish status pages', 'glpimajor') . '</span></label>';
echo "<div class='form-text mb-3'>"
   . __s('The master switch. Off, no file is written and no address serves anything.', 'glpimajor')
   . '</div>';

echo "<div class='row'>";

echo "<div class='col-md-6 mb-3'><label class='form-label'>"
   . __s('Page title', 'glpimajor') . '</label>';
echo "<input type='text' class='form-control' name='status_page_title' value='"
   . $e($cfg['status_page_title']) . "' placeholder='" . $e(Brand::title()) . "'>";
echo "<div class='form-text'>"
   . __s('Left empty this follows the branding plugin\'s product name. Each entity can override '
       . 'it on its own page.', 'glpimajor')
   . '</div></div>';

echo "<div class='col-md-6 mb-3'><label class='form-label'>"
   . __s('Timezone', 'glpimajor') . '</label>';
echo "<input type='text' class='form-control' name='status_timezone' value='"
   . $e($cfg['status_timezone']) . "' placeholder='" . $e(date_default_timezone_get()) . "'>";
echo "<div class='form-text'>"
   . __s('Every time on the page is drawn in this zone, with the offset printed next to the '
       . '"last updated" stamp. A reader in another country cannot act on a bare "14:20".',
       'glpimajor')
   . '</div></div>';

echo "<div class='col-md-6 mb-3'><label class='form-label'>"
   . __s('Support email', 'glpimajor') . '</label>';
echo "<input type='text' class='form-control' name='status_support_email' value='"
   . $e($cfg['status_support_email']) . "'></div>";

echo "<div class='col-md-6 mb-3'><label class='form-label'>"
   . __s('Support telephone', 'glpimajor') . '</label>';
echo "<input type='text' class='form-control' name='status_support_phone' value='"
   . $e($cfg['status_support_phone']) . "'></div>";

echo "<div class='col-md-6 mb-3'><label class='form-label'>"
   . __s('Keep resolved incidents on the page for (days)', 'glpimajor') . '</label>';
echo "<input type='number' class='form-control' name='status_history_days' value='"
   . $e($cfg['status_history_days']) . "'></div>";

echo "<div class='col-md-6 mb-3'><label class='form-label'>"
   . __s('Republish anything older than (minutes)', 'glpimajor') . '</label>';
echo "<input type='number' class='form-control' name='status_max_age_min' value='"
   . $e($cfg['status_max_age_min']) . "'>";
echo "<div class='form-text'>" . __s('The cron backstop, not the normal path.', 'glpimajor')
   . '</div></div>';

echo '</div>';

echo "<label class='form-check mb-2'>";
echo "<input type='checkbox' class='form-check-input' name='status_embed_logo' value='1' "
   . (((int) $cfg['status_embed_logo']) === 1 ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>"
   . __s('Embed the logo in the page when it is small enough', 'glpimajor') . '</span></label>';
echo "<div class='mb-2' style='max-width:320px'>";
echo "<label class='form-label'>" . __s('Embed when under (KB)', 'glpimajor') . '</label>';
echo "<input type='number' class='form-control' name='status_logo_max_kb' value='"
   . $e($cfg['status_logo_max_kb']) . "'></div>";

$logo = Brand::logo();
echo "<div class='form-text mb-3'>";
if ($logo === '') {
    echo __s('No branding logo is available, so the page shows a plain wordmark or nothing. '
        . 'Install and configure the branding plugin to change that.', 'glpimajor');
} elseif (str_starts_with($logo, 'data:')) {
    echo __s('The logo is currently embedded, so the published page makes no external requests '
        . 'at all.', 'glpimajor');
} else {
    echo __s('The logo is currently linked rather than embedded — it is over the ceiling above. '
        . 'The page will make one request for it.', 'glpimajor');
}
echo '</div>';

// ------------------------------------------------- who else the page reaches
//
// Both of these decide *where a page is read*, which is why they live on the
// status-page card rather than getting a card of their own: an administrator
// asking "who can see this" should find every answer in one place.

echo "<hr class='my-3'>";
echo "<h4 class='mb-2'>" . __s('Sub-entities', 'glpimajor') . '</h4>';

echo "<label class='form-check mb-2'>";
echo "<input type='checkbox' class='form-check-input' name='recursive_default' value='1' "
   . (((int) $cfg['recursive_default']) === 1 ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>"
   . __s('Tick "Also covers sub-entities" by default when declaring', 'glpimajor')
   . '</span></label>';
echo "<div class='form-text mb-3'>"
   . __s('The checkbox is offered only when the entity being declared in has sub-entities. '
       . 'A covered incident appears on every sub-entity\'s status page, and their tickets are '
       . 'offered the attach; it never travels sideways to another entity or upwards to a '
       . 'parent. Left off by default: ticking a box costs a click, and taking an outage back '
       . 'off three entities\' pages costs an explanation.', 'glpimajor')
   . '</div>';

echo "<hr class='my-3'>";
echo "<h4 class='mb-2'>" . __s('The self-service portal', 'glpimajor') . '</h4>';

echo '<p class="text-muted">'
   . __s('What a requester sees when they log in to raise a ticket. Both are read from this '
       . 'plugin\'s own tables behind a short cache, and neither ever regenerates a page. '
       . 'Neither does anything until publishing is on and the entity has an address.',
       'glpimajor')
   . '</p>';

echo "<label class='form-check mb-2'>";
echo "<input type='checkbox' class='form-check-input' name='portal_banner' value='1' "
   . (((int) $cfg['portal_banner']) === 1 ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>"
   . __s('Show a banner on the portal home page during an incident', 'glpimajor')
   . '</span></label>';
echo "<div class='form-text mb-3'>"
   . __s('The public title and the state, and a link to their own status page. '
       . 'Nothing internal: no roles, no ticket, no internal notes. It also covers planned work '
       . 'that is under way or starting within the day.', 'glpimajor')
   . '</div>';

echo "<label class='form-check mb-2'>";
echo "<input type='checkbox' class='form-check-input' name='portal_link' value='1' "
   . (((int) $cfg['portal_link']) === 1 ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>"
   . __s('Keep a quiet "Service status" link in the portal menu', 'glpimajor')
   . '</span></label>';
echo "<div class='form-text'>"
   . __s('Shown whenever the requester\'s entity has a live address, whether anything is wrong '
       . 'or not — so a reader who wants to check before ringing has somewhere to go.',
       'glpimajor')
   . '</div>';

echo '</div></div>';

// ------------------------------------------------------------------ matching
echo "<div id='glpimajor-matching' class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Duplicate matching', 'glpimajor') . '</h3></div><div class="card-body">';

echo '<p class="text-muted">'
   . __s('When an incident is open, new tickets that look like it are offered an '
       . '"attach as affected" button. The offer is never taken automatically, and a ticket is '
       . 'never attached without somebody clicking. Tickets in the incident\'s own entity always '
       . 'qualify; tickets in a sub-entity do when the incident is marked as covering '
       . 'sub-entities. Never another entity\'s.', 'glpimajor')
   . '</p>';

echo "<label class='form-check mb-3'>";
echo "<input type='checkbox' class='form-check-input' name='match_enabled' value='1' "
   . (((int) $cfg['match_enabled']) === 1 ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>" . __s('Offer the banner', 'glpimajor') . '</span></label>';

foreach (
    [
        'match_same_category' => __('Same category', 'glpimajor'),
        'match_same_location' => __('Same location', 'glpimajor'),
        'match_keywords'      => __('Similar wording in the title', 'glpimajor'),
    ] as $key => $label
) {
    echo "<label class='form-check'>";
    echo "<input type='checkbox' class='form-check-input' name='" . $e($key) . "' value='1' "
       . (((int) $cfg[$key]) === 1 ? "checked='checked'" : '') . '>';
    echo "<span class='form-check-label'>" . $e($label) . '</span></label>';
}

echo "<div class='form-text mb-3'>"
   . __s('At least one signal must agree, on top of the entity and the time window. Switching all '
       . 'three off would offer every open incident on every new ticket, so category is switched '
       . 'back on if you do.', 'glpimajor')
   . '</div>';

echo "<div class='row'>";
echo "<div class='col-md-6 mb-3'><label class='form-label'>"
   . __s('Only offer incidents declared within (hours)', 'glpimajor') . '</label>';
echo "<input type='number' class='form-control' name='match_window_hours' value='"
   . $e($cfg['match_window_hours']) . "'></div>";

echo "<div class='col-md-6 mb-3'><label class='form-label'>"
   . __s('Shortest word that counts as similar wording', 'glpimajor') . '</label>';
echo "<input type='number' class='form-control' name='match_min_word' value='"
   . $e($cfg['match_min_word']) . "'>";
echo "<div class='form-text'>"
   . __s('Without a floor, "the" and "issue" match everything you have ever run.', 'glpimajor')
   . '</div></div>';
echo '</div>';

echo '</div></div>';

// --------------------------------------------------------------------- comms
echo "<div id='glpimajor-comms' class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Comms discipline', 'glpimajor') . '</h3></div><div class="card-body">';

echo "<label class='form-check mb-2'>";
echo "<input type='checkbox' class='form-check-input' name='nag_enabled' value='1' "
   . (((int) $cfg['nag_enabled']) === 1 ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>"
   . __s('Remind the comms owner when a promised update passes', 'glpimajor') . '</span></label>';
echo "<div class='form-text mb-3'>"
   . __s('Sent as a GLPI notification to the comms owner alone. Copying in the room turns a '
       . 'reminder into an alert, and an alert that fires during every outage is an alert people '
       . 'filter.', 'glpimajor')
   . '</div>';

echo "<div class='row'>";
foreach (
    [
        'nag_grace_minutes'  => [__('Grace period (minutes)', 'glpimajor'),
            __('Nothing is late the instant it is due; people are mid-sentence.', 'glpimajor')],
        'nag_repeat_minutes' => [__('Remind again after (minutes)', 'glpimajor'),
            __('A reminder that repeats every cron run is a reminder people mute.', 'glpimajor')],
        'update_interval'    => [__('Default next-update interval (minutes)', 'glpimajor'),
            __('What the one-click "promise an update" button offers.', 'glpimajor')],
    ] as $key => [$label, $help]
) {
    echo "<div class='col-md-4 mb-3'><label class='form-label'>" . $e($label) . '</label>';
    echo "<input type='number' class='form-control' name='" . $e($key) . "' value='"
       . $e($cfg[$key]) . "'>";
    echo "<div class='form-text'>" . $e($help) . '</div></div>';
}
echo '</div>';

$next = Nag::nextAt(
    date('Y-m-d H:i:s'),
    null,
    (int) $cfg['nag_grace_minutes'],
    (int) $cfg['nag_repeat_minutes']
);
if ($next !== null) {
    echo "<p class='text-muted small mb-3'>"
       . sprintf(
           __s('With these values, an update promised for right now would be chased at %s.', 'glpimajor'),
           $e(Html::convDateTime(date('Y-m-d H:i:s', $next)))
       )
       . '</p>';
}

echo "<hr><label class='form-check mb-2'>";
echo "<input type='checkbox' class='form-check-input' name='ai_review_enabled' value='1' "
   . (((int) $cfg['ai_review_enabled']) === 1 ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>"
   . __s('Offer an AI review of public updates before publishing', 'glpimajor') . '</span></label>';
echo "<div class='form-text'>"
   . __s('The model reads a draft and reports jargon, internal detail and a missing next step. It '
       . 'never writes or rewrites an update, and publishing works exactly the same without it. '
       . 'Requires the AI plugin, a configured provider, and the entity permitted in that '
       . 'plugin\'s own settings — text is sent to whichever provider is configured there.',
       'glpimajor')
   . '</div>';

$refusal = Review::refusal((int) Session::getActiveEntity());
if (((int) $cfg['ai_review_enabled']) === 1 && $refusal !== null) {
    echo "<div class='alert alert-warning py-2 mt-2 mb-0'>" . $e($refusal) . '</div>';
}

echo '</div></div>';

// ----------------------------------------------------------------- PIR
echo "<div id='glpimajor-review' class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Post-incident review', 'glpimajor') . '</h3></div><div class="card-body">';

echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='pir_required' value='1' "
   . (((int) $cfg['pir_required']) === 1 ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>"
   . __s('Require an outcome summary before an incident can be resolved', 'glpimajor')
   . '</span></label>';
echo "<div class='form-text mb-3'>"
   . __s('The outcome is also what every affected ticket receives as its proposed solution, so '
       . 'switching this off means those tickets get nothing.', 'glpimajor')
   . '</div>';

echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='pir_lock_on_complete' value='1' "
   . (((int) $cfg['pir_lock_on_complete']) === 1 ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>"
   . __s('Lock a review when it is marked complete', 'glpimajor') . '</span></label>';
echo "<div class='form-text mb-3'>"
   . __s('The lock has a key: anyone who could have written the review can unlock it, and every '
       . 'turn of it is recorded.', 'glpimajor')
   . '</div>';

$sops = Sop::choices();
if ($sops !== []) {
    echo "<div class='mb-3' style='max-width:480px'>";
    echo "<label class='form-label'>"
       . __s('Attach this procedure when an incident resolves', 'glpimajor') . '</label>';
    Dropdown::showFromArray('pir_sops_id', [0 => __('— none —', 'glpimajor')] + $sops, [
        'value' => (int) $cfg['pir_sops_id'],
    ]);
    echo "<div class='form-text'>"
       . __s('From the SOP plugin. It lands on the incident\'s own ticket.', 'glpimajor')
       . '</div></div>';
} else {
    echo Html::hidden('pir_sops_id', ['value' => (int) $cfg['pir_sops_id']]);
    echo "<p class='text-muted small'>"
       . __s('The SOP plugin is not installed, so no post-incident procedure can be attached.', 'glpimajor')
       . '</p>';
}

echo "<label class='form-check'>";
echo "<input type='checkbox' class='form-check-input' name='improve_push' value='1' "
   . (((int) $cfg['improve_push']) === 1 ? "checked='checked'" : '') . '>';
echo "<span class='form-check-label'>"
   . __s('Offer review actions to the improvement register', 'glpimajor') . '</span></label>';
echo "<div class='form-text mb-3'>";
echo Improve::available()
    ? __s('The improvement register is present. Sending an action is still a click per action.', 'glpimajor')
    : __s('No improvement register is installed. This does nothing until one is, and nothing '
        . 'breaks in the meantime.', 'glpimajor');
echo '</div>';

echo "<div class='mb-2' style='max-width:320px'>";
echo "<label class='form-label'>" . __s('Keep the audit trail for (days)', 'glpimajor') . '</label>';
echo "<input type='number' class='form-control' name='events_retention_days' value='"
   . $e($cfg['events_retention_days']) . "'></div>";

echo '</div></div>';

echo "<div class='text-end mb-4'>";
if ($can_edit) {
    echo "<button type='submit' class='btn btn-primary'>" . __s('Save', 'glpimajor') . '</button>';
}
echo '</div>';

echo '</form>';

// =================================================================== snippets
//
// Its own form again: these are rows.

echo "<div id='glpimajor-snippets' class='card mb-3'><div class='card-header'><h3 class='card-title'>"
   . __s('Update templates', 'glpimajor') . '</h3></div><div class="card-body">';

echo '<p class="text-muted">'
   . __s('Starting points for an update, offered on the incident page. The first update of an '
       . 'outage is written by whoever is nearest, under pressure — a template is structure they '
       . 'do not have to invent. Recursive templates are offered to child entities too.',
       'glpimajor')
   . '</p>';

$snippets = Snippet::all();
if ($snippets === []) {
    echo '<p class="text-muted">' . __s('None yet.', 'glpimajor') . '</p>';
} else {
    foreach ($snippets as $snippet) {
        $entity = new Entity();
        $name   = $entity->getFromDB((int) $snippet['entities_id'])
            ? (string) $entity->fields['completename']
            : (string) $snippet['entities_id'];

        echo "<form method='post' class='border rounded p-2 mb-2'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('_glpimajor_section', ['value' => 'snippet']);
        echo Html::hidden('snippet_id', ['value' => (int) $snippet['id']]);

        echo "<div class='row g-2 align-items-end'>";
        echo "<div class='col-md-4'><label class='form-label'>" . __s('Name') . '</label>';
        echo "<input type='text' class='form-control form-control-sm' name='snippet_name' value='"
           . $e($snippet['name']) . "'" . ($can_edit ? '' : ' disabled') . '></div>';

        echo "<div class='col-md-3'><label class='form-label'>" . __s('Audience', 'glpimajor') . '</label>';
        Dropdown::showFromArray('snippet_audience', Update::audiences(), [
            'value'   => (string) $snippet['audience'],
            'display' => true,
        ]);
        echo '</div>';

        echo "<div class='col-md-3'><span class='text-muted small'>" . $e($name) . '</span></div>';

        echo "<div class='col-md-2 text-end'>";
        if ($can_edit) {
            echo "<button class='btn btn-sm btn-outline-secondary' name='snippet_save' value='1'>"
               . __s('Save') . '</button> ';
            echo "<button class='btn btn-sm btn-ghost-danger' name='snippet_delete' value='1'>"
               . __s('Remove', 'glpimajor') . '</button>';
        }
        echo '</div></div>';

        echo "<textarea class='form-control form-control-sm mt-2' rows='3' name='snippet_content'"
           . ($can_edit ? '' : ' disabled') . '>' . $e($snippet['content']) . '</textarea>';

        echo "<label class='form-check mt-1'>";
        echo "<input type='checkbox' class='form-check-input' name='snippet_recursive' value='1' "
           . (((int) $snippet['is_recursive']) === 1 ? "checked='checked'" : '')
           . ($can_edit ? '' : ' disabled') . '>';
        echo "<span class='form-check-label small'>"
           . __s('Also offer this in child entities', 'glpimajor') . '</span></label>';

        echo '</form>';
    }
}

if ($can_edit) {
    echo "<form method='post' class='border-top pt-3'>";
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo Html::hidden('_glpimajor_section', ['value' => 'snippet']);

    echo "<div class='row g-2 align-items-end'>";
    echo "<div class='col-md-4'><label class='form-label'>" . __s('New template', 'glpimajor') . '</label>';
    echo "<input type='text' class='form-control form-control-sm' name='snippet_name' required>";
    echo '</div>';

    echo "<div class='col-md-3'><label class='form-label'>" . __s('Audience', 'glpimajor') . '</label>';
    Dropdown::showFromArray('snippet_audience', Update::audiences(), ['value' => Update::EXTERNAL]);
    echo '</div>';

    echo "<div class='col-md-3'><label class='form-label'>" . __s('Entity') . '</label>';
    Entity::dropdown(['name' => 'snippet_entity', 'value' => 0, 'width' => '100%']);
    echo '</div>';

    echo "<div class='col-md-2 text-end'><button class='btn btn-sm btn-primary w-100' "
       . "name='snippet_add' value='1'>" . __s('Add') . '</button></div>';
    echo '</div>';

    echo "<textarea class='form-control form-control-sm mt-2' rows='3' name='snippet_content' "
       . "placeholder='" . __s('What has changed, and what happens next.', 'glpimajor')
       . "'></textarea>";

    echo "<label class='form-check mt-1'>";
    echo "<input type='checkbox' class='form-check-input' name='snippet_recursive' value='1' checked>";
    echo "<span class='form-check-label small'>"
       . __s('Also offer this in child entities', 'glpimajor') . '</span></label>';

    echo '</form>';
}

echo '</div></div>';

echo "<p class='text-muted small mb-4'>"
   . sprintf(
       __s('Maintenance windows are managed separately: %s.', 'glpimajor'),
       "<a href='" . $e(Url::to('front/maintenance.php')) . "'>"
       . $e(Maintenance::getTypeName(2)) . '</a>'
   )
   . '</p>';

echo '</div>';

Html::footer();
