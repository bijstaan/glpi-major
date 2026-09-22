<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * GLPI Major — major-incident mode, and a status page anyone can read.
 *
 * A major incident is a *mode*, not a priority level. When one entity's file
 * server dies, twelve people file twelve tickets, three technicians answer
 * separately, and nobody owns telling the outside world what is happening.
 * Priority fields do not fix that; a mode does — one record, one commander,
 * one comms owner, one public title, and one published page everybody can be
 * pointed at.
 *
 * Nothing here publishes by itself. Duplicates are *offered* for
 * attachment and attached by a click; resolution *proposes* a solution on every
 * attached ticket and closes none of them; the optional AI review reads a draft
 * update and never writes one.
 */

use Glpi\Http\Firewall;
use Glpi\Http\SessionManager;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Glpimajor\Banner;
use GlpiPlugin\Glpimajor\Incident;
use GlpiPlugin\Glpimajor\Maintenance;
use GlpiPlugin\Glpimajor\MobileController;

define('PLUGIN_GLPIMAJOR_VERSION', '0.1.3');
define('PLUGIN_GLPIMAJOR_MIN_GLPI', '11.0');

// Settings live under this config context.
define('PLUGIN_GLPIMAJOR_CONFIG_CONTEXT', 'plugin:glpimajor');

/**
 * The one path a stranger may reach, anchored and naming the one script.
 *
 * A constant because it is needed twice, in two different phases of the boot —
 * plugin_glpimajor_boot() for the session decision and plugin_init_glpimajor()
 * for the firewall — and two copies of a security pattern is one copy too many.
 * A file added to this plugin later cannot inherit the exemption.
 */
define('PLUGIN_GLPIMAJOR_PUBLIC_PATH', '#^/front/status\.php(/|$)#');

function plugin_init_glpimajor()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['glpimajor'] = true;

    // The plugin's rights, on Administration > Profiles.
    //
    // Core stores a plugin's rights and saves them back with its own, but
    // renders a form for its rights only — so without this tab the ones below
    // are enforced everywhere and grantable nowhere but SQL.
    Plugin::registerClass(\GlpiPlugin\Glpimajor\Profile::class, ['addtabon' => ['Profile']]);
    $PLUGIN_HOOKS['config_page']['glpimajor']    = 'front/config.php';

    // Registering the itemtypes is what puts them in the search engine — which
    // is where the MI list, its filters and its CSV export come from — and, for
    // the incident, in the notification itemtype dropdown so an administrator
    // can retarget our events without editing code.
    Plugin::registerClass(Incident::class, ['notificationtemplates_types' => true]);
    Plugin::registerClass(Maintenance::class);

    // GLPI reads a list page's Add button out of the menu registry, so an
    // itemtype absent from here gets a list it is impossible to add to —
    // however correct Html::header()'s arguments are.
    $PLUGIN_HOOKS['menu_toadd']['glpimajor'] = [
        'config' => [Incident::class, Maintenance::class],
    ];

    // The banner goes at the top of the ticket's own form, not behind a tab.
    // A tab is a thing people stop opening, and "nobody noticed this was part
    // of the outage" is the failure this plugin exists to prevent.
    //
    // PRE_ITIL_INFO_SECTION, not PRE_ITEM_FORM: the latter renders inside the
    // "Ticket" accordion body, and core's fields_panel script strips `show`
    // from every section of that panel below 768px — so the banner was in the
    // DOM and display:none on a phone, which is precisely the reader it was
    // written for. This hook renders as a sibling of the sections instead.
    // Both sit inside GLPI's own <form> for the ticket, so the declare
    // control's form= indirection (Banner::FORM_ID) is unaffected.
    $PLUGIN_HOOKS[Hooks::PRE_ITIL_INFO_SECTION]['glpimajor'] = 'plugin_glpimajor_pre_item_form';

    /**
     * The requester's side, in the self-service interface.
     *
     * Two hooks, chosen over the alternatives. In short:
     *
     * `DISPLAY_CENTRAL` is the only plugin hook GLPI 11 calls from the helpdesk
     * home page at all — `templates/pages/helpdesk/index.html.twig` calls it
     * from inside `<table class="central">`, which is why Portal::banner()
     * emits a table row and not a div. It fires on the central home page too,
     * so the callback checks which interface it is on.
     *
     * `REDEFINE_MENUS` runs inside `Html::helpHeader()`, immediately after the
     * helpdesk menu is generated and before it is rendered, and takes a flat
     * top-level entry. `helpdesk_menu_entry` — the hook whose name suggests it
     * is the answer — does still work, but it forces the entry into a shared
     * "Plugins" dropdown titled with the plugin's own name, which is not where
     * a reader looks for "is it just me".
     */
    $PLUGIN_HOOKS[Hooks::DISPLAY_CENTRAL]['glpimajor']  = 'plugin_glpimajor_display_central';
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['glpimajor']   = 'plugin_glpimajor_redefine_menus';

    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['glpimajor'] = [
        'Ticket' => 'plugin_glpimajor_item_update',
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['glpimajor'] = [
        'Ticket' => 'plugin_glpimajor_item_purge',
    ];

    // High-level API routes for the technician app (glpi-mobile). The HL
    // layer authenticates the bearer; every route re-checks this plugin's own
    // rights, mirroring front/incident.form.php and ajax/major.php.
    $PLUGIN_HOOKS['api_controllers']['glpimajor'] = [MobileController::class];

    /**
     * Feature discovery for glpi-mobile's /capabilities endpoint. Evaluated
     * per-session by that plugin, so these are the caller's rights — rights
     * checks only, nothing that touches the database.
     */
    $PLUGIN_HOOKS['glpimobile_capabilities']['glpimajor'] = 'plugin_glpimajor_mobile_capabilities';

    // Open major incidents, and what has been published about them, offered to glpi-ai's assistant as read-only tools.
    //
    // Registered unconditionally: only glpi-ai reads this hook, so an instance
    // without it pays one array assignment and never loads the class. Guarding
    // on Plugin::isPluginActive('glpiai') would run a database lookup on every
    // request to avoid that assignment.
    $PLUGIN_HOOKS['glpiai_tools']['glpimajor'] = [\GlpiPlugin\Glpimajor\AiTools::class, 'all'];

    $PLUGIN_HOOKS['add_javascript']['glpimajor'] = 'js/major.js';
    $PLUGIN_HOOKS['add_css']['glpimajor']        = 'css/major.css';

    /**
     * The status page is read by strangers, and needs both opt-outs.
     *
     * This is the first of the two: the firewall strategy stops GLPI demanding
     * a logged-in user, without which every reader gets the login page. It
     * belongs here because the firewall is consulted per controller, long after
     * plugins are initialised.
     *
     * The second — registering the same path as *stateless*, so GLPI opens no
     * session at all — has to happen earlier than this function runs, and lives
     * in plugin_glpimajor_boot(). See the note there.
     */
    Firewall::addPluginStrategyForLegacyScripts(
        'glpimajor',
        PLUGIN_GLPIMAJOR_PUBLIC_PATH,
        Firewall::STRATEGY_NO_CHECK
    );

    // Offered to glpi-pdf. See src/PdfDocument.php for what it contributes and
    // why. Registered unconditionally: only glpi-pdf reads this hook, and
    // PdfDocument::offers() returns nothing when that plugin is absent.
    $PLUGIN_HOOKS['glpipdf_documents']['glpimajor'] = [\GlpiPlugin\Glpimajor\PdfDocument::class, 'offers'];

    // How this plugin's notification looks when glpi-mail is installed. Without
    // it the body is still a real template and glpi-mail can wrap it; with it,
    // it is described and gets the house card. See MailLetters.
    $PLUGIN_HOOKS['glpimail_letters']['glpimajor'] = [\GlpiPlugin\Glpimajor\MailLetters::class, 'offers'];
}

/**
 * Registered here rather than in plugin_init_glpimajor(), which is too late.
 *
 * GLPI 11 runs its post-boot listeners in priority order: BootPlugins (140),
 * then SessionStart (130), then InitializePlugins (110) — and it is
 * InitializePlugins that calls `plugin_init_<key>()`. A stateless path
 * registered there is registered *after* SessionStart has already asked whether
 * this request needs a session, so the answer was always "yes": every anonymous
 * refresh of an entity's status page set a cookie on their browser and wrote a
 * session file on the server, which is the exact opposite of what registering
 * the path was for. Measured, not guessed — the session directory grew by one
 * file per request.
 *
 * `plugin_<key>_boot()` is GLPI's own hook for work that has to happen before
 * the session decision, and this is what it is for.
 */
function plugin_glpimajor_boot()
{
    SessionManager::registerPluginStatelessPath('glpimajor', PLUGIN_GLPIMAJOR_PUBLIC_PATH);
}

function plugin_version_glpimajor()
{
    return [
        'name'         => 'GLPI Major',
        'version'      => PLUGIN_GLPIMAJOR_VERSION,
        'author'       => 'Bijstaan',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://github.com/bijstaan/glpi-major',
        'requirements' => ['glpi' => ['min' => PLUGIN_GLPIMAJOR_MIN_GLPI]],
    ];
}

function plugin_glpimajor_check_prerequisites()
{
    return true;
}

function plugin_glpimajor_check_config($verbose = false)
{
    return true;
}

/**
 * The banners, at the top of a ticket's form.
 *
 * Two mutually exclusive things: if this ticket *is* a major incident, the MI
 * banner with its roles and state; if it is an ordinary ticket that looks like
 * one already open, the offer to attach it.
 */
function plugin_glpimajor_pre_item_form($params)
{
    $item = $params['item'] ?? null;

    if (!($item instanceof Ticket) || $item->isNewItem()) {
        return;
    }

    Banner::render($item);
}

/**
 * The status banner on the self-service home page.
 *
 * GLPI calls this from both home pages — `Central` and the helpdesk index —
 * with one hook. A technician already has the incident itself, the banner on
 * its ticket, and the list under Setup; a second, softer copy of it on their
 * home page would be noise. Portal::currentEntity() answers null for anybody
 * not in the helpdesk interface, which is where that decision lives.
 */
function plugin_glpimajor_display_central()
{
    \GlpiPlugin\Glpimajor\Portal::banner();
}

/**
 * The quiet "Service status" entry in the helpdesk navigation.
 *
 * Returns the menu it was given, changed or not: this is a *function* hook, so
 * whatever comes back is what GLPI renders, and a callback that forgets to
 * return would empty the menu for every requester on the instance.
 */
function plugin_glpimajor_redefine_menus($menu)
{
    return \GlpiPlugin\Glpimajor\Portal::menu(is_array($menu) ? $menu : []);
}

/**
 * Keep the affected list honest when a ticket changes underneath it.
 *
 * An attached ticket solved by whoever picked it up is no longer waiting on the
 * incident, and the count on the MI should say so rather than quietly including
 * work that finished hours ago.
 */
function plugin_glpimajor_item_update($item)
{
    if (!($item instanceof Ticket)) {
        return;
    }

    \GlpiPlugin\Glpimajor\Affected::refreshFor((int) $item->getID());
}

function plugin_glpimajor_item_purge($item)
{
    if (!($item instanceof Ticket)) {
        return;
    }

    \GlpiPlugin\Glpimajor\Affected::forgetTicket((int) $item->getID());
    Incident::forgetTicket((int) $item->getID());
}

/**
 * What of this plugin the mobile app may show the calling user.
 *
 * @return array{version:string,features:array<string,bool>}
 */
function plugin_glpimajor_mobile_capabilities(): array
{
    return [
        'version'  => PLUGIN_GLPIMAJOR_VERSION,
        'features' => [
            'view'    => (bool) Session::haveRight('plugin_glpimajor_declare', READ),
            'declare' => (bool) Session::haveRight('plugin_glpimajor_declare', CREATE),
            'publish' => (bool) Session::haveRight('plugin_glpimajor_declare', UPDATE),
        ],
    ];
}
