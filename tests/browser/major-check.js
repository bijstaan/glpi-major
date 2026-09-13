// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// glpi-major in a browser: one outage, start to finish.
//
// This drives the whole thing the way a service desk would, in order, and
// asserts at each step the claim the README makes about it:
//
//   - declaring is a small control on the ticket, not a page of its own, and
//     what it publishes is a title of its own — not the ticket's
//   - the banner is in the record, above the form, with both roles on it
//   - the comms log keeps its audiences visibly apart
//   - a second ticket that looks the same is *offered* an attach, and attaches
//     on one click
//   - the page a reader reads contains no GLPI vocabulary and no internal
//     sentence, and a regenerated address 404s the old one immediately
//   - resolution proposes a solution on the attached ticket and closes nothing
//   - a completed review locks, and the lock says so
//   - an incident marked as covering sub-entities reaches the offices below it:
//     their status pages carry it, their tickets are offered the attach, and a
//     requester in one of them sees it on the self-service portal
//   - and when it is over the portal falls back to a quiet link
//
// It also takes the documentation screenshots.
//
//   cd glpi-major/tests/browser && bash major-setup.sh          # the world
//   SHOT_DIR=../../docs/screenshots node major-check.js
//
// The setup script prints the ids this one needs; run it first, or let this
// script run it (it does, unless TICKET_MI is already in the environment).
'use strict';

const { chromium } = require('playwright');
const { execSync } = require('child_process');
const { fullPage } = require('./shot');

const BASE = process.env.BASE || 'http://localhost:8081';
const SHOTS = process.env.SHOT_DIR || '.';

// The same one-shot-PHP helper the other checks use, for the handful of facts
// that are only visible in the database — a solution's status, a candidate row
// in a neighbouring plugin's inbox.
const php = (code) =>
  execSync('docker exec -i glpi-glpi-1 php', {
    encoding: 'utf8',
    input:
      '<?php require "/var/www/glpi/vendor/autoload.php";'
      + '(new Glpi\\Kernel\\Kernel(Glpi\\Application\\Environment::PRODUCTION->value))->boot();'
      + '(new Auth())->login("glpi","glpi",true);(new Plugin())->init(true);'
      + 'global $DB;'
      + code,
  }).trim();

const fail = [];
function check(name, cond, detail) {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${detail ? ' :: ' + String(detail).slice(0, 200) : ''}`);
  if (!cond) fail.push(name);
}

// --- Dark-theme audit helpers ---------------------------------------------
//
// Two measured house traps:
// (1) a near-#e6e6e6 surface carrying the dark body's own (light) text —
//     core's dark palette redefines --tblr-light without redefining
//     --tblr-bg-surface-secondary, so anything still keyed to that token
//     paints a near-white panel with unreadable-by-similarity text; (2)
//     .text-muted/.form-text (and this plugin's own muted-looking classes)
//     sitting under the 4.5:1 contrast floor. Injected once per page load
//     (a fresh document each navigation) via addScriptTag, then queried per
//     surface.
const AUDIT_JS = `
window.__majorAudit = (function () {
  function relLum(rgb) {
    const f = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]);
  }
  function parseColor(str) {
    str = str || '';
    // Chromium serialises a color-mix() result (which --tblr-bg-surface-tertiary
    // and this plugin's own dark-mode variable overrides both are) as
    // "color(srgb r g b [/ a])" with space-separated 0-1 floats, not as
    // rgb()/rgba() — a background in that form fell straight through this
    // function's original rgba?()-only regex, so effectiveBg() silently
    // skipped every element painted by one and kept walking to whatever
    // ancestor happened to still be in plain rgb(), misreporting contrast
    // against the wrong surface. Found 2026-08-22 auditing the portal banner.
    const cm = /^color\\(srgb\\s+([\\d.]+)\\s+([\\d.]+)\\s+([\\d.]+)(?:\\s*\\/\\s*([\\d.]+))?\\)$/.exec(str.trim());
    if (cm) {
      return {
        r: parseFloat(cm[1]) * 255, g: parseFloat(cm[2]) * 255, b: parseFloat(cm[3]) * 255,
        a: cm[4] !== undefined ? parseFloat(cm[4]) : 1,
      };
    }
    const m = /rgba?\\(([^)]+)\\)/.exec(str);
    if (!m) return null;
    const p = m[1].split(',').map((s) => parseFloat(s));
    return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
  }
  function effectiveBg(el) {
    // Composite, don't shortcut. The first version returned the first
    // ancestor whose background had any alpha at all — which reported a
    // pill's 8%-alpha tint (color-mix(tone, transparent 92%)) as an *opaque*
    // swatch of the full-strength tone, and measured the lifted text against
    // a red nobody ever sees (1.99:1 against a surface that really reads
    // ~5:1). Found 2026-08-22 auditing the war-room cockpit's state pills.
    // Every translucent layer down to the first opaque one is blended in
    // paint order instead.
    let node = el;
    const layers = [];
    while (node) {
      const c = parseColor(getComputedStyle(node).backgroundColor);
      if (c && c.a > 0.02) {
        layers.push(c);
        if (c.a >= 0.98) break;
      }
      node = node.parentElement;
    }
    let bg = { r: 0, g: 0, b: 0, a: 1 };
    for (let i = layers.length - 1; i >= 0; i--) {
      const c = layers[i];
      bg = {
        r: c.r * c.a + bg.r * (1 - c.a),
        g: c.g * c.a + bg.g * (1 - c.a),
        b: c.b * c.a + bg.b * (1 - c.a),
        a: 1,
      };
    }
    return bg;
  }
  function contrast(c1, c2) {
    const L1 = relLum([c1.r, c1.g, c1.b]) + 0.05;
    const L2 = relLum([c2.r, c2.g, c2.b]) + 0.05;
    return L1 > L2 ? L1 / L2 : L2 / L1;
  }
  return function (rootSelector, mutedSelectors) {
    const darkActive = document.documentElement.getAttribute('data-glpi-theme-dark') === '1';
    const root = document.querySelector(rootSelector);
    if (!root) return { darkActive, rootFound: false, nearWhiteText: [], mutedFails: [] };

    // Trap 1: a background within ~10% of #e6e6e6 carrying light (dark-body) text.
    const nearWhiteText = [];
    root.querySelectorAll('*').forEach((el) => {
      const cs = getComputedStyle(el);
      const bg = parseColor(cs.backgroundColor);
      if (!bg || bg.a < 0.5) return;
      if (Math.abs(bg.r - 230) + Math.abs(bg.g - 230) + Math.abs(bg.b - 230) > 75) return;
      const fg = parseColor(cs.color);
      if (!fg || !el.textContent.trim()) return;
      if (relLum([fg.r, fg.g, fg.b]) > 0.4) {
        nearWhiteText.push({ tag: el.tagName, cls: String(el.className).slice(0, 60), bg: cs.backgroundColor, fg: cs.color });
      }
    });

    // Trap 2: muted/secondary text under the 4.5:1 floor against its real background.
    //
    // This plugin dims some of its own text with CSS opacity rather than
    // .text-muted/.form-text, which getComputedStyle().color does not reflect
    // at all — opacity is compositing, not a colour, so reading .color alone
    // measures the pre-dim text and overstates the contrast a reader actually
    // sees. Blending the parsed foreground toward the real background by the
    // element's own computed opacity first is what makes the number honest.
    const mutedFails = [];
    (mutedSelectors || []).forEach((sel) => {
      root.querySelectorAll(sel).forEach((el) => {
        if (!el.textContent.trim()) return;
        const fg = parseColor(getComputedStyle(el).color);
        if (!fg) return;
        const bg = effectiveBg(el);
        const op = parseFloat(getComputedStyle(el).opacity);
        const alpha = fg.a * (isNaN(op) ? 1 : op);
        const blended = {
          r: fg.r * alpha + bg.r * (1 - alpha),
          g: fg.g * alpha + bg.g * (1 - alpha),
          b: fg.b * alpha + bg.b * (1 - alpha),
        };
        const ratio = contrast(blended, bg);
        if (ratio < 4.5) {
          mutedFails.push({ sel, text: el.textContent.trim().slice(0, 50), ratio: Number(ratio.toFixed(2)) });
        }
      });
    });

    return { darkActive, rootFound: true, nearWhiteText, mutedFails };
  };
})();
`;

async function auditSurface(pg, rootSelector, mutedSelectors) {
  await pg.addScriptTag({ content: AUDIT_JS });
  return pg.evaluate(
    ({ rootSelector, mutedSelectors }) => window.__majorAudit(rootSelector, mutedSelectors),
    { rootSelector, mutedSelectors }
  );
}

function checkAudit(surface, result, mutedSelectors) {
  check(`[dark] ${surface}: dark theme active`, result.darkActive === true, JSON.stringify(result));
  check(`[dark] ${surface}: surface found`, result.rootFound === true);
  check(`[dark] ${surface}: no near-white surface carrying dark-body text`,
    result.nearWhiteText.length === 0, JSON.stringify(result.nearWhiteText));
  check(`[dark] ${surface}: muted/secondary text >= 4.5:1 (${mutedSelectors.join(', ') || 'none'})`,
    result.mutedFails.length === 0, JSON.stringify(result.mutedFails));
}

/** Provision (or reuse) a plugin-owned dark-palette test user, cloning glpi's own profile. */
function provisionDarkUser(login, password) {
  return JSON.parse(php(`
    global $DB;
    $login = "${login}";
    $existing = $DB->request(["FROM" => "glpi_users", "WHERE" => ["name" => $login]])->current();
    $u = new User();
    if ($existing) {
        $uid = (int) $existing["id"];
        $u->getFromDB($uid);
    } else {
        $glpi_row = $DB->request(["FROM" => "glpi_users", "WHERE" => ["name" => "glpi"]])->current();
        $sa_row = $DB->request(["FROM" => "glpi_profiles_users", "WHERE" => ["users_id" => $glpi_row["id"]], "ORDER" => "id ASC", "LIMIT" => 1])->current();
        $uid = (int) $u->add([
            "name"          => $login,
            "realname"      => "Dark theme",
            "firstname"     => "glpi-major test",
            "password"      => "${password}",
            "password2"     => "${password}",
            "is_active"     => 1,
            "entities_id"   => 0,
            "_entities_id"  => 0,
            "_is_recursive" => 1,
            "_profiles_id"  => (int) $sa_row["profiles_id"],
            "comment"       => "Plugin-owned fixture for glpi-major's dark-theme browser check "
                              . "(major-check.js). Never the real glpi user.",
        ]);
    }
    // Only ever this user's own palette — never glpi_users id 2 (glpi).
    $u->update(["id" => $uid, "palette" => "auror_dark"]);
    echo json_encode(["id" => $uid]);
  `));
}

/**
 * Poll until it is true, tolerating the page navigating underneath.
 *
 * Every button in this plugin posts and then reloads, so any wait that runs
 * inside the page can have its execution context pulled out from under it.
 * Asking Playwright from the outside, repeatedly, is the one thing that
 * survives that.
 */
async function until(probe, ms = 25000) {
  const started = Date.now();
  while (Date.now() - started < ms) {
    try {
      if (await probe()) return true;
    } catch (e) { /* navigating; ask again in a moment */ }
    await new Promise((r) => setTimeout(r, 250));
  }
  return false;
}

function section(title) {
  console.log(`\n=== ${title} ===`);
}

/**
 * Choose a value in a select GLPI has dressed with select2.
 *
 * The option is often not in the DOM until the widget has been opened and its
 * ajax has answered, and driving select2 itself is slow and brittle. The form
 * posts the underlying <select>, so putting the value there and firing a change
 * is the same thing to the server and nothing at all to the test's reliability.
 */
async function pick(page, name, value, label) {
  await page.evaluate(({ name, value, label }) => {
    const select = document.querySelector(`select[name="${name}"]`);
    if (!select) throw new Error(`no select named ${name}`);
    if (!select.querySelector(`option[value="${value}"]`)) {
      const option = document.createElement('option');
      option.value = String(value);
      option.textContent = label || String(value);
      select.appendChild(option);
    }
    select.value = String(value);
    select.dispatchEvent(new Event('change', { bubbles: true }));
  }, { name, value: String(value), label });
}

/**
 * Text of the page as a person sees it, whitespace-collapsed.
 *
 * innerText, not textContent: it is what is *rendered*, which is the thing
 * being asserted. It also means CSS `text-transform` reaches the assertion —
 * the banner's flag is uppercased in the stylesheet — so comparisons here are
 * made in lower case rather than against the wording in the PHP.
 */
const text = (page, selector = 'body') =>
  page.evaluate((s) => (document.querySelector(s)?.innerText || '').replace(/\s+/g, ' ').trim(), selector);

/** The same, lowercased, for wording assertions. */
const said = async (page, selector = 'body') => (await text(page, selector)).toLowerCase();

/** Everything inside an element, open or collapsed — a <details> hides its own innerText. */
const contents = (page, selector) =>
  page.evaluate((s) => (document.querySelector(s)?.textContent || '').replace(/\s+/g, ' ').trim(), selector);

/**
 * Open the "Edit details" summary the stock incident form lives behind
 * (0.1.2): the cockpit answers the war room's questions, so the form is one
 * click away rather than first. Clicks on its fields need it open.
 */
const openEditDetails = (pg) =>
  pg.evaluate(() => {
    const d = document.querySelector('details.glpimajor-editdetails');
    if (d) d.open = true;
  });

/** The feed's entries by kind, newest first — the order is half the feature. */
const feedKinds = (pg) =>
  pg.evaluate(() => Array.from(document.querySelectorAll('.glpimajor-log-entry')).map((li) =>
    li.classList.contains('glpimajor-feed-state') ? 'state'
      : li.classList.contains('glpimajor-feed-declared') ? 'declared' : 'update'));

(async () => {
  // --- the world ------------------------------------------------------

  let ids = {};
  if (!process.env.TICKET_MI) {
    const out = execSync('bash ' + __dirname + '/major-setup.sh', { encoding: 'utf8' });
    out.split('\n').forEach((line) => {
      const [k, v] = line.split('=');
      if (k && v) ids[k.trim()] = v.trim();
    });
  } else {
    ids = { ...process.env };
  }

  const ENTITY = Number(ids.ENTITY_ID);
  const MANCHESTER = Number(ids.MANCHESTER_ID);
  const LONDON = Number(ids.LONDON_ID);
  const T_MI = Number(ids.TICKET_MI);
  const T_DUP = Number(ids.TICKET_DUP);
  const T_BRANCH = Number(ids.TICKET_BRANCH);
  const COMMANDER = Number(ids.COMMANDER);
  const COMMS = Number(ids.COMMS);
  const PORTAL_USER = ids.PORTAL_USER;
  const PORTAL_PASSWORD = ids.PORTAL_PASSWORD;

  console.log(`entity ${ENTITY} (offices ${MANCHESTER}, ${LONDON}), tickets ${T_MI}, ${T_DUP} and ${T_BRANCH}`);

  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1500, height: 1000 } });
  const errs = [];
  page.on('pageerror', (e) => errs.push(e.message));

  // Publishing asks first, and there is no unsend — so the
  // confirmation is part of the feature, not an obstacle to the test. Accepted
  // rather than auto-dismissed (Playwright's default), and remembered, because
  // "it asked" is itself worth asserting.
  const asked = [];
  page.on('dialog', async (dialog) => {
    asked.push(dialog.message());
    await dialog.accept();
  });

  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.fill('#login_name', 'glpi');
  await page.fill('input[type=password]', 'glpi');
  await page.click('button[type=submit]');
  await page.waitForLoadState('networkidle');

  // ================================================== 1. declaring
  section('Declaring, from the ticket');

  const TITLE = 'Access to shared files is unavailable';

  await page.goto(`${BASE}/front/ticket.form.php?id=${T_MI}`, { waitUntil: 'networkidle' });

  check('the declare control is on the ticket', await page.locator('.glpimajor-declare').count() === 1);
  // A disclosure inside the fields panel, not a page of its own. It was a bare
  // <details> and is now core's accordion furniture — Banner::declareControl()
  // explains why — so this asserts the accordion header and opens that.
  check('and it is a disclosure in the panel, not a page of its own',
    await page.locator('.glpimajor-declare .accordion-header .accordion-button').count() === 1);

  await page.click('.glpimajor-declare .accordion-button');
  // Bootstrap animates the collapse; wait for it to actually be open rather
  // than for a fixed interval, or the first field read races the transition.
  await page.waitForSelector('.glpimajor-declare .collapse.show', { timeout: 5000 });
  await page.waitForTimeout(200);

  const prefilled = await page.inputValue('.glpimajor-declare input[name=name]');
  check('the public title is pre-filled from the ticket', prefilled.length > 0, prefilled);
  check('and the form says that is usually the wrong thing to publish',
    (await text(page, '.glpimajor-declare')).includes('the wrong thing to publish'));

  await fullPage(page, `${SHOTS}/major-01-declare.png`, { highlight: '.glpimajor-declare' });

  await page.fill('.glpimajor-declare input[name=name]', TITLE);
  await pick(page, 'users_id_commander', COMMANDER, 'Michael Okafor');
  await pick(page, 'users_id_comms', COMMS, 'Priya Raman');
  await page.click('.glpimajor-declare button[name=declare]');
  await page.waitForLoadState('networkidle');

  const url = page.url();
  check('declaring lands on the incident', /incident\.form\.php\?id=\d+/.test(url), url);
  const INCIDENT = Number((url.match(/id=(\d+)/) || [])[1]);

  // ================================================== 1b. the cockpit
  section('The cockpit: what the war room glances at, before any form field');

  check('the cockpit is the first thing on the incident',
    await page.locator('.glpimajor-cockpit').count() === 1);
  check('with the public title as the biggest thing on it',
    (await text(page, '.glpimajor-cockpit-title')) === TITLE,
    await text(page, '.glpimajor-cockpit-title'));
  check('a state pill', (await text(page, '.glpimajor-cockpit .glpimajor-pill')) === 'Investigating');
  check('and a live duration', /^Ongoing for /.test(await text(page, '.glpimajor-cockpit-since')),
    await text(page, '.glpimajor-cockpit-since'));

  const strip = await page.evaluate(() =>
    Array.from(document.querySelectorAll('.glpimajor-strip li')).map((li) =>
      `${li.classList.contains('is-current') ? '*' : li.classList.contains('is-done') ? '+' : '-'}${li.textContent.trim()}`));
  check('the progression strip highlights where we are',
    strip.join(',') === '*Investigating,-Identified,-Monitoring,-Resolved', strip.join(','));

  const chips = await text(page, '.glpimajor-cockpit-chips');
  check('the chips say when and by whom it was declared', /Declared\s/.test(chips), chips);
  check('and that the public has heard nothing yet — the number this plugin exists for',
    chips.includes('First public update') && chips.includes('none yet'), chips);
  check('said with the overdue emphasis',
    await page.evaluate(() => Array.from(document.querySelectorAll('.glpimajor-chip.is-late'))
      .some((c) => c.textContent.includes('First public update'))));

  check('the primary action is the natural next transition',
    (await text(page, '.glpimajor-cockpit [data-glpimajor-goto]')) === 'Mark identified');
  check('with the skips and regressions in a small menu beside it',
    await page.evaluate(() => Array.from(document.querySelectorAll('.glpimajor-cockpit-menu-item'))
      .map((b) => b.dataset.glpimajorGoto).join(',')) === 'monitoring,resolved');

  // The button drives the composer — one mechanism, not a modal of its own.
  await page.click('.glpimajor-cockpit [data-glpimajor-goto="identified"]');
  await page.waitForTimeout(300);
  check('pressing it pre-selects that state in the composer',
    (await page.inputValue('input[data-glpimajor-state]')) === 'identified'
    && await page.evaluate(() =>
      document.querySelector('[data-glpimajor-state-pill="identified"]').classList.contains('is-selected')));
  check('and puts the cursor in the note field',
    await page.evaluate(() => document.activeElement === document.querySelector('[data-glpimajor-update]')));

  // Put it back to "keep" — the walkthrough moves the state later, on purpose.
  await page.click('[data-glpimajor-current="1"]');
  check('re-choosing the current state is "keep"',
    (await page.inputValue('input[data-glpimajor-state]')) === '');

  check('and the stock form is one click away, not gone',
    await page.locator('details.glpimajor-editdetails > summary').count() === 1
    && !(await page.evaluate(() => document.querySelector('details.glpimajor-editdetails').open)));

  // The public post-mortem panel exists from the start — half the story is
  // best written while it is fresh — but publishing is gated on resolution,
  // and the gate is a sentence, not a missing button nobody can explain.
  check('the post-mortem panel is offered from the very start',
    await page.locator('[data-glpimajor-postmortem]').count() === 1);
  check('with no publish button while the incident is open',
    await page.locator('[data-glpimajor-action=pm-publish]').count() === 0);
  check('and the gate written down instead',
    (await text(page, '[data-glpimajor-postmortem]')).includes('Publishing opens when the incident is resolved'),
    (await text(page, '[data-glpimajor-postmortem]')).slice(0, 300));

  await fullPage(page, `${SHOTS}/major-11-cockpit.png`, { highlight: '.glpimajor-cockpit' });

  // ================================================== 2. the banner
  section('The banner, in the record');

  await page.goto(`${BASE}/front/ticket.form.php?id=${T_MI}`, { waitUntil: 'networkidle' });

  const banner = await page.locator('.glpimajor-banner').first();
  check('the ticket now carries the incident banner', await banner.count() === 1);

  const bannerText = await said(page, '.glpimajor-banner');
  check('it flags the mode', bannerText.includes('major incident'), bannerText);
  check('it shows the state', bannerText.includes('investigating'), bannerText);
  check('it names the commander', bannerText.includes('okafor'), bannerText);
  check('and separately the comms owner', bannerText.includes('raman'), bannerText);
  check('it publishes the public title, not the ticket\'s',
    bannerText.includes(TITLE.toLowerCase()), bannerText);
  check('and says so in as many words', bannerText.includes('is what the reader reads'), bannerText);
  check('it says nothing has been promised yet', bannerText.includes('no next update promised'), bannerText);
  // 0.1.1: the declaring ticket is the outage's first affected ticket, so a
  // fresh declaration reads 1, never 0.
  check('and it counts the declaring ticket as the first affected',
    bannerText.includes('1 affected ticket'), bannerText);

  // Above the ticket's own fields, not behind a tab: the whole placement
  // argument. The banner is rendered *inside* GLPI's form element, so the
  // question is whether it comes before the fields, not before the form.
  const placement = await page.evaluate(() => {
    const banner = document.querySelector('.glpimajor-banner');
    if (!banner) return null;
    const form = banner.closest('form');
    const box = banner.getBoundingClientRect();
    return {
      inTheTicketForm: !!form && /ticket\.form\.php/.test(form.getAttribute('action') || ''),
      visible: box.height > 0 && box.width > 0,
      tabsOpened: 0,
    };
  });
  check('and it is in the record itself, with no tab opened to find it',
    !!placement && placement.inTheTicketForm && placement.visible, JSON.stringify(placement));

  await fullPage(page, `${SHOTS}/major-02-banner.png`, { highlight: '.glpimajor-banner' });

  // ================================================== 3. the comms log
  section('The comms log, and its audiences');

  const INTERNAL = 'RAID controller on FS01 has been degraded since 06:40 and the second disk '
    + 'failed at 09:12. Dell dispatch raised under the 4h contract. Do not power-cycle it — the '
    + 'rebuild would start again from nothing.';
  const CUSTOMER_1 = 'We are aware that documents on the shared drive cannot be opened from any '
    + 'machine in the office, and we are working on it now. Email, telephones and the case '
    + 'management system are not affected. We will post the next update by 10:15.';
  const CUSTOMER_2 = 'The cause is a hardware fault in the file server at your office. A '
    + 'replacement part is on its way and is expected on site within four hours. We will update '
    + 'again at 12:00, or sooner if the engineer arrives before then.';

  // Publishing posts, then reloads the page from JavaScript. Waiting on
  // "networkidle" races that reload — the next fill lands in a DOM that is
  // about to be thrown away, and the update is silently never written. Waiting
  // for the log to actually grow is the only honest signal.
  async function post(content, audience) {
    const before = await page.locator('.glpimajor-feed-update').count();
    await page.fill('[data-glpimajor-update]', content);
    // The audience radios sit invisibly under the segmented control (0.1.2),
    // so the click goes to the segment a person would press.
    await page.click(`.glpimajor-audience-opt:has(input[value=${audience}])`);
    // The Publish button became Post (0.1.1): one submit that can also carry a
    // state change and a promise. Left at "keep"/"keep" here, it is exactly
    // the old publish.
    await page.click('[data-glpimajor-action=post]');
    const grew = await until(async () => (await page.locator('.glpimajor-feed-update').count()) > before);
    check(`the ${audience} update was published`, grew);
    await page.waitForLoadState('networkidle');
  }

  await page.goto(`${BASE}/plugins/glpimajor/front/incident.form.php?id=${INCIDENT}`, { waitUntil: 'networkidle' });

  check('the composer defaults to internal',
    await page.isChecked('input[name=glpimajor_audience][value=internal]'));

  await post(INTERNAL, 'internal');
  check('an internal note goes out without a confirmation', asked.length === 0, asked.join(' | '));

  await post(CUSTOMER_1, 'customer');
  check('but publishing asks first',
    asked.length === 1 && /status page/i.test(asked[0]), asked.join(' | '));

  // The promise, one click.
  await page.click('[data-glpimajor-action=promise][data-glpimajor-minutes="60"]');
  await until(async () => (await text(page, '.glpimajor-promise')).includes('Next update due'));
  await page.waitForLoadState('networkidle');

  check('a promise can be made in one click',
    (await text(page, '.glpimajor-promise')).includes('Next update due'),
    await text(page, '.glpimajor-promise'));

  // Move the state on, then say the next thing — so the log carries two
  // different states at two different times, which is what state_at_time is
  // for. Through the stock form deliberately: it is the second path to
  // setState(), and the feed has to record it whichever door was used.
  await openEditDetails(page);
  await pick(page, 'state', 'identified', 'Identified');
  await page.click('button[name=update]');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(400);

  await post(CUSTOMER_2, 'customer');

  const entries = await page.evaluate(() =>
    Array.from(document.querySelectorAll('.glpimajor-feed-update')).map((li) => ({
      audience: li.querySelector('.glpimajor-log-audience')?.textContent.trim(),
      state: li.querySelector('.glpimajor-log-state')?.textContent.trim(),
      who: li.querySelector('.glpimajor-log-who')?.textContent.trim(),
      body: li.querySelector('.glpimajor-log-body')?.textContent.trim().slice(0, 60),
      isPublic: li.classList.contains('is-public'),
    })));

  check('the log has all three entries', entries.length === 3, JSON.stringify(entries.map((e) => e.audience)));
  check('every entry says who it was for',
    entries.every((e) => e.audience === 'Public' || e.audience === 'Internal only'),
    JSON.stringify(entries.map((e) => e.audience)));
  check('two of them are public', entries.filter((e) => e.isPublic).length === 2);
  check('one of them is internal', entries.filter((e) => !e.isPublic).length === 1);
  check('and every entry carries the state we believed at the time',
    entries.every((e) => !!e.state) && entries.some((e) => e.state === 'Investigating')
      && entries.some((e) => e.state === 'Identified'),
    JSON.stringify(entries.map((e) => e.state)));

  // The feed (0.1.2): one chronological story. The state change made through
  // the *main form* above is a recorded event between the updates it
  // separates, the declaration is the oldest entry, and the promise is
  // deliberately not an entry at all.
  const kinds = await feedKinds(page);
  check('the feed interleaves the state change where it happened',
    kinds.join(',') === 'update,state,update,update,declared', kinds.join(','));

  const stateEntry = await text(page, '.glpimajor-feed-state');
  check('saying what moved, where, and who moved it',
    stateEntry.includes('Moved to') && stateEntry.includes('Identified')
      && stateEntry.includes('from Investigating'), stateEntry);

  const declaredEntry = await text(page, '.glpimajor-feed-declared');
  check('and the feed begins where the incident did — the declaration, from its ticket',
    declaredEntry.includes('Declared a major incident') && declaredEntry.includes('Promoted from'),
    declaredEntry);

  await fullPage(page, `${SHOTS}/major-03-updates.png`, { highlight: '.glpimajor-log' });

  // ================================================== 4. the duplicate
  section('The second ticket, and the offer');

  await page.goto(`${BASE}/front/ticket.form.php?id=${T_DUP}`, { waitUntil: 'networkidle' });

  check('the second ticket is offered the incident',
    await page.locator('.glpimajor-banner--offer').count() === 1);

  const offer = await text(page, '.glpimajor-banner--offer');
  check('the offer names the public title', offer.includes(TITLE), offer);
  check('it says why it matched', /Matched on/.test(offer), offer);
  check('it says what attaching does and does not do',
    offer.includes('nothing else changes'), offer);
  check('and it offers exactly one attach button',
    await page.locator('[data-glpimajor-action=attach]').count() === 1);
  check('with a way to look first',
    offer.includes('Look at the incident first'), offer);

  await fullPage(page, `${SHOTS}/major-04-duplicate.png`, { highlight: '.glpimajor-banner--offer' });

  await page.click('[data-glpimajor-action=attach]');
  await until(async () => (await page.locator('.glpimajor-banner--info').count()) === 1);
  await page.waitForLoadState('networkidle');

  const attached = await said(page, '.glpimajor-banner');
  check('one click attaches it', (await page.locator('.glpimajor-banner--info').count()) === 1, attached);
  check('and the ticket now says it is part of the outage',
    attached.includes('part of a major incident'), attached);
  check('and that it will not be closed for anybody',
    attached.includes('never closed for you'), attached);

  const link = php(
    `foreach ($DB->request(['FROM'=>'glpi_tickets_tickets','WHERE'=>['tickets_id_1'=>${T_DUP},'tickets_id_2'=>${T_MI}]]) as $r) { echo $r['link']; }`
  );
  check('the core link is SON_OF (3), never DUPLICATE_WITH (1)', link === '3', `link=${link}`);

  // The Affected panel, now that one ticket is attached: the declaring ticket
  // is the first row (0.1.1), and the header counts it.
  await page.goto(`${BASE}/plugins/glpimajor/front/incident.form.php?id=${INCIDENT}`, { waitUntil: 'networkidle' });

  const affectedHead = await text(page, '.glpimajor-affected');
  check('the affected header counts the declaring ticket',
    (await text(page)).includes('Affected tickets (2)'), affectedHead.slice(0, 120));

  const affectedRows = await page.evaluate(() =>
    Array.from(document.querySelectorAll('.glpimajor-affected tbody tr')).map((tr) => ({
      declaring: tr.classList.contains('glpimajor-declaring-row'),
      text: tr.textContent.trim().replace(/\s+/g, ' ').slice(0, 80),
      detach: !!tr.querySelector('[data-glpimajor-action=detach]'),
    })));
  check('the declaring ticket is the first row', affectedRows.length === 2 && affectedRows[0].declaring,
    JSON.stringify(affectedRows));
  check('it says it was declared, not attached', affectedRows[0].text.includes('declared by'),
    JSON.stringify(affectedRows[0]));
  check('and it has no detach button — detaching it would be un-declaring',
    !affectedRows[0].detach && affectedRows[1].detach, JSON.stringify(affectedRows));

  // ================================================== 5. the address
  section('The settings page, and an address to hand out');

  await page.goto(`${BASE}/plugins/glpimajor/front/config.php`, { waitUntil: 'networkidle' });

  check('the settings page renders', (await text(page)).includes('Status page addresses'));
  check('the dashboard counts the declaring ticket too',
    (await text(page)).includes('2 affected'), (await text(page)).match(/\d+ affected/g));

  await pick(page, 'page_entity', ENTITY, 'Ravensworth Legal');
  await page.click('button[name=page_create]');
  await page.waitForLoadState('networkidle');

  // Scoped to the target entity's own row: the pages table can hold other
  // entities' rows (other fixtures mint their own), and "the first <code> on
  // the card" silently becomes somebody else's address the day one exists.
  const addressOf = async () => {
    const codes = await page.evaluate((ent) =>
      Array.from(document.querySelectorAll('#glpimajor-pages tr'))
        .filter((tr) => tr.querySelector('input[name="page_entity"][value="' + ent + '"]'))
        .flatMap((tr) => Array.from(tr.querySelectorAll('code')).map((c) => c.textContent.trim())),
      String(ENTITY));
    const hit = codes.find((c) => /status\.php\/[0-9a-f]{48}$/.test(c));
    return hit ? hit.match(/([0-9a-f]{48})$/)[1] : null;
  };

  let token = await addressOf();
  check('an address was generated', !!token, token || (await text(page, '#glpimajor-pages')));

  // The per-entity wording. Its handler existed before its form did. Scoped
  // to this entity's own details row for the same reason as addressOf().
  const detailsRow = `#glpimajor-pages tr.glpimajor-page-details:has(input[name=page_entity][value="${ENTITY}"])`;
  await page.click(`${detailsRow} details summary`);
  await page.waitForTimeout(200);
  await page.fill(`${detailsRow} input[name=page_title]`, 'Ravensworth Legal — service status');
  await page.fill(`${detailsRow} input[name=page_note]`,
    'Our service desk is open 08:00–18:00, Monday to Friday. Outside those hours, call the number below.');
  await page.click(`${detailsRow} button[name=page_details]`);
  await page.waitForLoadState('networkidle');

  const details = php(
    `foreach ($DB->request(['FROM'=>'glpi_plugin_glpimajor_pages','WHERE'=>['entities_id'=>${ENTITY}]]) as $r)`
    + `{ echo $r['page_title'] . '|' . $r['support_note']; }`
  );
  check('the page title and support note are saved against the entity',
    details.startsWith('Ravensworth Legal — service status|Our service desk'), details);

  await fullPage(page, `${SHOTS}/major-07-settings.png`, { highlight: '#glpimajor-pages' });

  // ================================================== 5b. maintenance
  section('A window readers are warned about');

  await page.goto(`${BASE}/plugins/glpimajor/front/maintenance.form.php`, { waitUntil: 'networkidle' });

  // Blank here once meant the itemtype's right had no CREATE bit: GLPI's tab
  // endpoint returns *nothing* rather than an error when `can(-1, CREATE)` is
  // false, so the Add button in the menu led to an empty page.
  check('the new-window form renders at all',
    await page.locator('#main-form input[name=name]').count() === 1);

  const WINDOW = 'Overnight replacement of the office file server';

  await page.fill('#main-form input[name=name]', WINDOW);
  await page.fill('#main-form textarea[name=content]',
    'The shared drive will be unavailable while the server is replaced. Nothing else is affected, '
    + 'and everything will be back before the office opens.');

  // The dates are flatpickr's hidden partners; the visible field is a decorated
  // copy. The form posts these.
  await page.evaluate(({ entity, start, end }) => {
    const form = document.querySelector('#main-form');
    form.querySelector('[name=entities_id]').value = String(entity);
    form.querySelector('[name=date_start]').value = start;
    form.querySelector('[name=date_end]').value = end;
  }, { entity: ENTITY, start: '2026-08-29 22:00:00', end: '2026-08-30 02:00:00' });

  await page.click('#main-form button[name=add]');
  await page.waitForLoadState('networkidle');

  const win = php(
    `foreach ($DB->request(['FROM'=>'glpi_plugin_glpimajor_maintenances','WHERE'=>['entities_id'=>${ENTITY}],'ORDER'=>['id DESC'],'LIMIT'=>1]) as $r)`
    + `{ echo json_encode(['name'=>$r['name'],'state'=>$r['state'],'start'=>(string)$r['date_start'],'source'=>$r['source']]); }`
  );
  check('the window is saved against the entity', win.length > 0, win);
  if (win.length > 0) {
    const w = JSON.parse(win);
    check('with the title readers will see', w.name === WINDOW, w.name);
    check('scheduled, and dated', w.state === 'scheduled' && w.start.startsWith('2026-08-29'), win);
    // NULL, not '': the unique key on (source, external_key) is what makes a
    // pushed announcement idempotent, and two empty strings are equal — so
    // stored as '' the instance could hold exactly one hand-made window.
    check('and entered here rather than pushed by another plugin', !w.source, `source=${w.source}`);
  }

  // ================================================== 6. the public page
  section('The page a reader reads');

  // A different browser context: no cookie, no session, nothing this instance
  // has ever seen. That is the only honest way to check a page that claims to
  // need none of them.
  const stranger = await browser.newContext({ viewport: { width: 1200, height: 1000 } });
  const outside = await stranger.newPage();

  const fetchPage = async (t) => {
    const response = await outside.goto(`${BASE}/plugins/glpimajor/front/status.php/${t}`,
      { waitUntil: 'networkidle' });
    return { status: response.status(), body: await outside.content() };
  };

  /**
   * Ask the server, not the browser's cache.
   *
   * The page is served `Cache-Control: public, max-age=60` on purpose — a room
   * full of people refreshing during an outage should cost one read — and now
   * that no session is started, nothing defeats it any more. So a navigation to
   * a URL this context has already seen answers from the cache, which is right
   * for a reader and useless for asserting that an address was retired.
   */
  const statusOf = async (t) => {
    const response = await stranger.request.get(`${BASE}/plugins/glpimajor/front/status.php/${t}`,
      { headers: { 'Cache-Control': 'no-cache' } });
    return response.status();
  };

  let got = await fetchPage(token);
  check('a stranger gets the page', got.status === 200, `HTTP ${got.status}`);
  check('and is not asked to log in', !got.body.includes('login_name'));

  // No session, in the only way that can be checked: the browser that just read
  // it is holding nothing. GLPI decides whether to start a session *before* it
  // initialises plugins, so a stateless path registered at init time is
  // registered too late and every anonymous refresh writes a session file.
  const jar = await stranger.cookies();
  check('and no cookie for having read it', jar.length === 0, JSON.stringify(jar.map((c) => c.name)));

  const visible = (await text(outside)).toLowerCase();
  const vocabulary = ['ticket', 'entity', 'requester', 'itil', 'glpi', 'assignee', 'technician'];
  const leaked = vocabulary.filter((w) => new RegExp(`\\b${w}\\b`).test(visible));
  check('it uses none of GLPI\'s vocabulary', leaked.length === 0, leaked.join(', '));

  check('the public updates are on it',
    visible.includes('replacement part is on its way'), visible.slice(0, 200));
  check('the internal note is not', !visible.includes('fs01') && !visible.includes('dell dispatch'));
  check('nor is the internal ticket title', !visible.includes('s: drive'));
  check('the public title is', visible.includes(TITLE.toLowerCase()));
  check('the address is not printed on the page it addresses', !got.body.includes(token));
  check('the support contact is there', visible.includes('servicedesk@bijstaan.example'));
  check('and the per-entity note', visible.includes('08:00'));
  check('the planned maintenance is announced on it',
    visible.includes('overnight replacement of the office file server'), visible.slice(0, 300));
  check('with what readers should expect',
    visible.includes('back before the office opens'));

  // A nonsense address is a bare 404 — not a redirect, not a differently
  // worded error that tells somebody guessing whether they are getting warmer.
  const nonsense = await statusOf('0'.repeat(48));
  check('an address that was never minted is a bare 404', nonsense === 404, `HTTP ${nonsense}`);

  // Rotation. The old address has to stop working at the moment the button is
  // pressed, not at the next publish.
  const old = token;
  await page.goto(`${BASE}/plugins/glpimajor/front/config.php`, { waitUntil: 'networkidle' });
  await page.click(`#glpimajor-pages tr:has(input[name=page_entity][value="${ENTITY}"]) button[name=page_regenerate]`);
  await page.waitForLoadState('networkidle');
  token = await addressOf();

  check('regenerating gives a different address', !!token && token !== old, `${old} -> ${token}`);

  const retired = await statusOf(old);
  check('and the old address 404s immediately', retired === 404, `HTTP ${retired}`);
  check('while the new one answers', await statusOf(token) === 200);

  got = await fetchPage(token);
  check('while the new one serves the same page', got.status === 200
    && (await text(outside)).includes('Access to shared files'), `HTTP ${got.status}`);

  await fullPage(outside, `${SHOTS}/major-05-status-page.png`);

  // ================================================== 6b. down the tree
  section('An outage that covers the organisation\'s other offices');

  // Before anything is ticked, the Manchester office's ticket is in a different
  // entity from the incident and must be offered nothing at all. This is the
  // "off" half of the feature, and it is the half that would silently stop
  // being true if the scoping were ever loosened.
  await page.goto(`${BASE}/front/ticket.form.php?id=${T_BRANCH}`, { waitUntil: 'networkidle' });
  check('a ticket in a sub-entity is offered nothing while the incident covers only its own',
    await page.locator('.glpimajor-banner--offer').count() === 0);

  await page.goto(`${BASE}/plugins/glpimajor/front/incident.form.php?id=${INCIDENT}`, { waitUntil: 'networkidle' });
  await openEditDetails(page);

  check('the incident offers a coverage control, because this entity has offices',
    await page.locator('#main-form input[name=is_recursive][type=checkbox]').count() === 1);
  check('and it says which way coverage travels',
    (await text(page)).includes('never travels sideways or upwards'),
    (await text(page)).slice(0, 200));
  check('it is off until somebody says otherwise',
    !(await page.isChecked('#main-form input[name=is_recursive][type=checkbox]')));

  await page.check('#main-form input[name=is_recursive][type=checkbox]');
  await page.click('#main-form button[name=update]');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(400);

  const covers = php(
    `foreach ($DB->request(['FROM'=>'glpi_plugin_glpimajor_incidents','WHERE'=>['id'=>${INCIDENT}]]) as $r) { echo (int)$r['is_recursive']; }`
  );
  check('ticking it is recorded on the incident', covers === '1', `is_recursive=${covers}`);

  // The offer, now that the incident reaches down.
  await page.goto(`${BASE}/front/ticket.form.php?id=${T_BRANCH}`, { waitUntil: 'networkidle' });
  check('the sub-entity\'s ticket is now offered the incident declared above it',
    await page.locator('.glpimajor-banner--offer').count() === 1);
  const branchOffer = await text(page, '.glpimajor-banner--offer');
  check('naming the public title', branchOffer.includes(TITLE), branchOffer);

  await page.click('[data-glpimajor-action=attach]');
  await until(async () => (await page.locator('.glpimajor-banner--info').count()) === 1);
  await page.waitForLoadState('networkidle');

  const branchBanner = await said(page, '.glpimajor-banner');
  check('one click attaches it across the entity boundary, downwards',
    (await page.locator('.glpimajor-banner--info').count()) === 1, branchBanner);
  check('and the major-incident banner renders on a ticket in the sub-entity',
    branchBanner.includes('part of a major incident'), branchBanner);

  const branchRow = php(
    `foreach ($DB->request(['FROM'=>'glpi_plugin_glpimajor_affected','WHERE'=>['tickets_id'=>${T_BRANCH}]]) as $r)`
    + `{ echo json_encode(['incident'=>(int)$r['plugin_glpimajor_incidents_id'],'entity'=>(int)$r['entities_id']]); }`
  );
  check('recorded against the office it was raised in, not the firm',
    branchRow.length > 0 && JSON.parse(branchRow).entity === MANCHESTER
      && JSON.parse(branchRow).incident === INCIDENT, branchRow);

  // An address for the office, minted the way an administrator would.
  await page.goto(`${BASE}/plugins/glpimajor/front/config.php`, { waitUntil: 'networkidle' });
  await pick(page, 'page_entity', MANCHESTER, 'Ravensworth Legal — Manchester');
  await page.click('button[name=page_create]');
  await page.waitForLoadState('networkidle');

  const tokenFor = (entity) => php(
    `foreach ($DB->request(['FROM'=>'glpi_plugin_glpimajor_pages','WHERE'=>['entities_id'=>${entity}]]) as $r) { echo (string)$r['token']; }`
  );

  await pick(page, 'page_entity', LONDON, 'Ravensworth Legal — London');
  await page.click('button[name=page_create]');
  await page.waitForLoadState('networkidle');

  const manchesterToken = tokenFor(MANCHESTER);
  const londonToken = tokenFor(LONDON);
  check('the office gets an address of its own', /^[0-9a-f]{48}$/.test(manchesterToken), manchesterToken);
  check('and it is not the firm\'s', manchesterToken !== token);
  check('the other office gets its own too', /^[0-9a-f]{48}$/.test(londonToken), londonToken);
  check('and the two offices do not share one', londonToken !== manchesterToken);

  const branchPage = await fetchPage(manchesterToken);
  const branchText = (await text(outside)).toLowerCase();

  check('the office\'s page answers', branchPage.status === 200, `HTTP ${branchPage.status}`);
  check('and carries the incident declared at the firm above it',
    branchText.includes(TITLE.toLowerCase()), branchText.slice(0, 240));
  check('with the updates the public was given',
    branchText.includes('replacement part is on its way'), branchText.slice(0, 240));
  check('and still none of the internal note',
    !branchText.includes('fs01') && !branchText.includes('dell dispatch'));
  check('nor any of GLPI\'s vocabulary',
    vocabulary.filter((w) => new RegExp(`\\b${w}\\b`).test(branchText)).length === 0);

  await fullPage(outside, `${SHOTS}/major-09-child-status.png`);

  const londonPage = await stranger.request.get(
    `${BASE}/plugins/glpimajor/front/status.php/${londonToken}`,
    { headers: { 'Cache-Control': 'no-cache' } }
  );
  const londonText = (await londonPage.text()).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').toLowerCase();
  check('the second office\'s page carries it as well', londonText.includes(TITLE.toLowerCase()),
    londonText.slice(0, 200));

  // ================================================== 6c. the portal
  section('What the requester in that office sees when they log in');

  // A context of its own with a real self-service login: the portal is only
  // true if somebody who is not an administrator, in a sub-entity, sees it.
  const office = await browser.newContext({ viewport: { width: 1400, height: 1100 } });
  const solicitor = await office.newPage();
  const officeErrs = [];
  solicitor.on('pageerror', (e) => officeErrs.push(e.message));

  const signInAsRequester = async () => {
    await solicitor.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    if (await solicitor.locator('#login_name').count()) {
      await solicitor.fill('#login_name', PORTAL_USER);
      await solicitor.fill('input[type=password]', PORTAL_PASSWORD);
      await solicitor.click('button[type=submit]');
      await solicitor.waitForLoadState('networkidle');
    }
  };

  await signInAsRequester();

  check('the requester lands on the self-service portal', /\/Helpdesk/.test(solicitor.url()), solicitor.url());
  check('and the portal carries a status banner',
    await solicitor.locator('.glpimajor-portal').count() === 1);

  const portalText = await text(solicitor, '.glpimajor-portal');
  const portalSaid = portalText.toLowerCase();

  check('it names the public title', portalText.includes(TITLE), portalText);
  check('and the state, in the status page\'s words rather than GLPI\'s',
    portalSaid.includes('identified'), portalText);
  check('it does not name the internal ticket', !portalSaid.includes('s: drive'), portalText);
  check('nor the roles, which are nobody\'s business out here',
    !portalSaid.includes('okafor') && !portalSaid.includes('raman'), portalText);
  check('nor anything from the internal note',
    !portalSaid.includes('fs01') && !portalSaid.includes('dell'), portalText);
  // The status page's word list, minus "ticket".
  //
  // The page is a standalone artefact a stranger reads, so GLPI's vocabulary
  // has no business on it at all. The portal is *inside* GLPI's own helpdesk,
  // whose navigation says "Tickets" two inches above this banner — telling a
  // requester "your ticket is still with us" there is their own word for it,
  // not a leak. Everything that would be a leak is still forbidden.
  const portalVocabulary = vocabulary.filter((w) => w !== 'ticket');
  check('it uses none of GLPI\'s internal vocabulary either',
    portalVocabulary.filter((w) => new RegExp(`\\b${w}\\b`).test(portalSaid)).length === 0,
    portalText);
  check('and it tells them not to raise a second one',
    portalSaid.includes('no need to raise another'), portalText);

  // Above the tiles, inside the page's own container — the hook GLPI 11 calls
  // on the helpdesk home fires from inside a <table>, so a banner that is not a
  // table row gets foster-parented out of the layout entirely.
  const portalPlacement = await solicitor.evaluate(() => {
    const el = document.querySelector('.glpimajor-portal');
    if (!el) return null;
    const box = el.getBoundingClientRect();
    return {
      inACell: el.parentElement.tagName === 'TD',
      inTheCentralTable: !!el.closest('table.central'),
      visible: box.height > 0 && box.width > 0,
    };
  });
  check('and it is placed inside the page rather than foster-parented out of it',
    !!portalPlacement && portalPlacement.inACell && portalPlacement.inTheCentralTable
      && portalPlacement.visible, JSON.stringify(portalPlacement));

  const portalHref = await solicitor.getAttribute('.glpimajor-portal-link', 'href');
  check('the banner links to their own office\'s address',
    !!portalHref && portalHref.endsWith(manchesterToken), portalHref);

  await fullPage(solicitor, `${SHOTS}/major-08-portal-banner.png`, { highlight: '.glpimajor-portal' });

  const quietLink = async () => solicitor.evaluate(() =>
    Array.from(document.querySelectorAll('.navbar-nav a.nav-link'))
      .filter((a) => /service status/i.test(a.textContent))
      .map((a) => a.getAttribute('href'))[0] || null);

  check('and there is a standing "Service status" entry in the portal menu',
    (await quietLink()) !== null);

  await solicitor.click('.glpimajor-portal-link');
  await solicitor.waitForLoadState('networkidle');

  const reached = (await text(solicitor)).toLowerCase();
  check('following it reaches the page itself', reached.includes(TITLE.toLowerCase()), reached.slice(0, 200));
  check('which is the office\'s page, not the firm\'s',
    solicitor.url().endsWith(manchesterToken), solicitor.url());

  // ============================== dark pass, part 1: the live incident
  section('Dark-theme conformance pass — the live incident');

  // Run here, between "the portal" and "resolution", on purpose: this is the
  // last point in the whole walkthrough where INCIDENT is still open, covers
  // Manchester, and has attached tickets — every surface below needs exactly
  // that state, and after section 7 it is gone for good.
  //
  // One more ticket, in the incident's own entity and category, left
  // undeclared: Banner::render() shows the offer and the declare control
  // together on a ticket with no incident of its own and nothing attached,
  // which by this point in the run is no longer true of T_MI, T_DUP or
  // T_BRANCH.
  const darkFixture = JSON.parse(php(`
    global $DB;
    $cat = (int) ($DB->request(['FROM' => 'glpi_itilcategories', 'WHERE' => ['completename' => 'Hardware > Servers'], 'LIMIT' => 1])->current()['id'] ?? 0);
    $id = (int) (new Ticket())->add([
        'name'                => 'Print queue on the third floor will not clear',
        'content'             => 'Dark-theme fixture ticket for major-check.js. Not a real report.',
        'entities_id'         => ${ENTITY},
        'itilcategories_id'   => $cat,
        'type'                => Ticket::INCIDENT_TYPE,
        'urgency'             => 3,
        'impact'              => 3,
        'status'              => Ticket::ASSIGNED,
        '_users_id_requester' => 2,
    ]);
    echo json_encode(['ticket' => $id]);
  `));
  check('dark-pass fixture ticket created (offer + declare control)', darkFixture.ticket > 0, JSON.stringify(darkFixture));

  const maintenanceId = php(
    `echo (int) ($DB->request(['FROM' => 'glpi_plugin_glpimajor_maintenances', 'WHERE' => ['entities_id' => ${ENTITY}], 'ORDER' => ['id DESC'], 'LIMIT' => 1])->current()['id'] ?? 0);`
  );

  const DARK_LOGIN = 'glpimajor-darktest';
  const DARK_PASSWORD = 'Glpimajor-Dark-2026!';
  const darkUser = provisionDarkUser(DARK_LOGIN, DARK_PASSWORD);
  check('dark test user provisioned (own palette, not glpi\'s)', darkUser.id > 0, JSON.stringify(darkUser));

  const darkCtx = await browser.newContext({ viewport: { width: 1500, height: 1100 } });
  const dp = await darkCtx.newPage();
  const darkErrs = [];
  dp.on('pageerror', (e) => darkErrs.push(e.message));

  await dp.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await dp.fill('#login_name', DARK_LOGIN);
  await dp.fill('input[type=password]', DARK_PASSWORD);
  await dp.click('button[type=submit]');
  await dp.waitForLoadState('networkidle');
  await dp.waitForTimeout(500);

  const themeOn = await dp.evaluate(() => document.documentElement.getAttribute('data-glpi-theme-dark'));
  check('dark test user session shows data-glpi-theme-dark=1', themeOn === '1', themeOn);

  // --- The MI banner, on the incident's own ticket -----------------------
  await dp.goto(`${BASE}/front/ticket.form.php?id=${T_MI}`, { waitUntil: 'networkidle' });
  await dp.waitForTimeout(600);
  // Beyond the two named traps: this plugin dims some of its own text with
  // opacity rather than .text-muted, and opacity is exactly the kind of thing
  // that can quietly fail a contrast floor without anyone noticing — so it
  // gets the same measurement, not just the classes DARKTHEME.md names.
  const BANNER_MUTED = ['.text-muted', '.form-text', '.glpimajor-sub', '.glpimajor-facts', '.glpimajor-role--empty'];
  let r = await auditSurface(dp, '.glpimajor-banner', BANNER_MUTED);
  checkAudit('ticket banner (declared, roles + state chip)', r, BANNER_MUTED);
  await fullPage(dp, `${SHOTS}/dark/major-dark-02-banner.png`, { highlight: '.glpimajor-banner' });

  // --- MI banner + declare control, and the duplicate-offer banner -------
  await dp.goto(`${BASE}/front/ticket.form.php?id=${darkFixture.ticket}`, { waitUntil: 'networkidle' });
  await dp.waitForTimeout(600);
  check('the fixture ticket carries the duplicate offer', await dp.locator('.glpimajor-banner--offer').count() === 1);
  await dp.click('.glpimajor-declare .accordion-button');
  await dp.waitForSelector('.glpimajor-declare .collapse.show', { timeout: 5000 });
  await dp.waitForTimeout(300);
  r = await auditSurface(dp, '.glpimajor-banner--offer', BANNER_MUTED);
  checkAudit('duplicate-offer banner', r, BANNER_MUTED);
  r = await auditSurface(dp, '.glpimajor-declare', ['.text-muted', '.form-text']);
  checkAudit('declare control', r, ['.text-muted', '.form-text']);
  await fullPage(dp, `${SHOTS}/dark/major-dark-03-declare-offer.png`, { highlight: '.glpimajor-banner--offer' });

  // --- The incident view: roles header, update log, state chips ----------
  await dp.goto(`${BASE}/plugins/glpimajor/front/incident.form.php?id=${INCIDENT}`, { waitUntil: 'networkidle' });
  await dp.waitForTimeout(600);

  // The cockpit (0.1.2): tone-mapped pills, the strip, the chips — every one
  // of them coloured text on a card surface, which is exactly the shape of
  // thing the tone tokens fail on in dark without the scoped lifts.
  const COCKPIT_MUTED = ['.glpimajor-pill', '.glpimajor-cockpit-since', '.glpimajor-chip-label',
    '.glpimajor-chip-value', '.glpimajor-strip-label', '.glpimajor-cockpit-menu-item'];
  r = await auditSurface(dp, '.glpimajor-cockpit', COCKPIT_MUTED);
  checkAudit('cockpit (pills, strip, chips)', r, COCKPIT_MUTED);
  await fullPage(dp, `${SHOTS}/dark/major-dark-10-cockpit.png`, { highlight: '.glpimajor-cockpit' });

  await openEditDetails(dp);
  r = await auditSurface(dp, '.glpimajor-form', ['.text-muted', '.form-text']);
  checkAudit('incident form (roles, coverage)', r, ['.text-muted', '.form-text']);
  const VIEW_MUTED = ['.text-muted', '.form-text', '.glpimajor-log-head', '.glpimajor-log-review',
    '.glpimajor-tl-at', '.glpimajor-tl-detail', '.glpimajor-composer-caption',
    '.glpimajor-pill', '.glpimajor-statepill', '.glpimajor-audience-opt span',
    '.glpimajor-feed-verb', '.glpimajor-feed-from', '.glpimajor-feed-fromticket'];
  r = await auditSurface(dp, '.glpimajor-view', VIEW_MUTED);
  checkAudit('incident view (feed, composer pills, audience control)', r, VIEW_MUTED);
  await fullPage(dp, `${SHOTS}/dark/major-dark-04-incident-view.png`, { highlight: '.glpimajor-log' });

  // --- The incident list ---------------------------------------------------
  await dp.goto(`${BASE}/plugins/glpimajor/front/incident.php`, { waitUntil: 'networkidle' });
  await dp.waitForTimeout(500);
  await fullPage(dp, `${SHOTS}/dark/major-dark-05-incident-list.png`);

  // --- Maintenance windows: list and form ---------------------------------
  await dp.goto(`${BASE}/plugins/glpimajor/front/maintenance.php`, { waitUntil: 'networkidle' });
  await dp.waitForTimeout(500);
  await fullPage(dp, `${SHOTS}/dark/major-dark-06-maintenance-list.png`);

  await dp.goto(`${BASE}/plugins/glpimajor/front/maintenance.form.php?id=${maintenanceId}`, { waitUntil: 'networkidle' });
  await dp.waitForTimeout(500);
  r = await auditSurface(dp, '.glpimajor-form', ['.text-muted', '.form-text']);
  checkAudit('maintenance window form', r, ['.text-muted', '.form-text']);
  await fullPage(dp, `${SHOTS}/dark/major-dark-07-maintenance-form.png`);

  // --- The settings page: every card, plus the address/preview rows ------
  await dp.goto(`${BASE}/plugins/glpimajor/front/config.php`, { waitUntil: 'networkidle' });
  await dp.waitForTimeout(600);
  r = await auditSurface(dp, '.glpimajor-config', ['.text-muted', '.form-text']);
  checkAudit('settings page', r, ['.text-muted', '.form-text']);
  await fullPage(dp, `${SHOTS}/dark/major-dark-08-settings.png`, { highlight: '#glpimajor-pages' });

  check('[dark] no uncaught JavaScript errors so far', darkErrs.length === 0, darkErrs.join(' | '));

  // --- The self-service portal banner: worst-risk, requester-facing ------
  //
  // nadia.whitfield is a plugin-owned demo fixture (major-setup.sh), not a
  // real person's account — this is the one surface the brief asks to be
  // checked as her, specifically, because it is a requester-facing surface
  // sitting inside core's own <table class="central">.
  php(`$DB->update('glpi_users', ['palette' => 'auror_dark'], ['name' => 'nadia.whitfield']);`);

  const portalCtx = await browser.newContext({ viewport: { width: 1400, height: 1100 } });
  const np = await portalCtx.newPage();
  const portalErrs = [];
  np.on('pageerror', (e) => portalErrs.push(e.message));

  await np.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await np.fill('#login_name', PORTAL_USER);
  await np.fill('input[type=password]', PORTAL_PASSWORD);
  await np.click('button[type=submit]');
  await np.waitForLoadState('networkidle');
  await np.waitForTimeout(600);

  const portalThemeOn = await np.evaluate(() => document.documentElement.getAttribute('data-glpi-theme-dark'));
  check('nadia\'s dark-palette session shows data-glpi-theme-dark=1', portalThemeOn === '1', portalThemeOn);
  check('the portal banner is present for the self-service requester in dark',
    await np.locator('.glpimajor-portal').count() === 1);

  r = await auditSurface(np, '.glpimajor-portal', ['.text-muted', '.form-text', '.glpimajor-portal-note', '.glpimajor-portal-state']);
  checkAudit('self-service portal banner', r, ['.glpimajor-portal-note', '.glpimajor-portal-state']);
  await fullPage(np, `${SHOTS}/dark/major-dark-01-portal-banner.png`, { highlight: '.glpimajor-portal' });

  check('[dark] no uncaught JavaScript errors on the portal', portalErrs.length === 0, portalErrs.join(' | '));

  await portalCtx.close();

  // ================================================== 6e. one post
  section('One post: the note, the state and the promise together');

  await page.goto(`${BASE}/plugins/glpimajor/front/incident.form.php?id=${INCIDENT}`, { waitUntil: 'networkidle' });

  // The state is a pill row (0.1.2): the current pill selected *is* "keep",
  // and the hidden input the server reads still says so as ''.
  check('the composer offers the state as a pill row, current selected = keep',
    await page.locator('[data-glpimajor-state-pill]').count() === 4
    && await page.evaluate(() =>
      document.querySelector('[data-glpimajor-current="1"]')?.classList.contains('is-selected'))
    && (await page.inputValue('input[data-glpimajor-state]')) === '');
  check('and the next update, defaulting to keep',
    await page.locator('[data-glpimajor-next]').count() === 1
    && (await page.inputValue('[data-glpimajor-next]')) === 'keep');

  const MITIGATED = 'The rebuild is running on the replacement disk and shares are answering '
    + 'again. Watching it until the array reports healthy.';

  // "We mitigated it, we are monitoring, next update in 30 minutes" — one
  // sentence, one Post, one reload. Counting load events is the proof.
  let warroomLoads = 0;
  const onWarroomLoad = () => { warroomLoads += 1; };
  page.on('load', onWarroomLoad);

  const logBefore = await page.locator('.glpimajor-feed-update').count();
  await page.fill('[data-glpimajor-update]', MITIGATED);
  await page.click('[data-glpimajor-state-pill="monitoring"]');
  await page.selectOption('[data-glpimajor-next]', '30');
  await page.click('[data-glpimajor-action=post]');
  await until(async () => (await page.locator('.glpimajor-feed-update').count()) > logBefore);
  await page.waitForLoadState('networkidle');
  page.off('load', onWarroomLoad);

  check('the note landed', (await page.locator('.glpimajor-feed-update').count()) === logBefore + 1);
  check('the note carries the state we believed when it was written',
    await page.evaluate(() => document.querySelector('.glpimajor-feed-update .glpimajor-log-state')?.textContent.trim()) === 'Identified');

  // BOTH halves of the one post land in the feed, in order: the state event
  // above the note that rode with it (newest first), because the note was
  // written under the state we were leaving.
  const combined = await feedKinds(page);
  check('the feed gained both the note and the state-change event, in order',
    combined[0] === 'state' && combined[1] === 'update', combined.join(','));
  const movedEntry = await text(page, '.glpimajor-feed-state');
  check('the event names the transition',
    movedEntry.includes('Moved to') && movedEntry.includes('Monitoring')
      && movedEntry.includes('from Identified'), movedEntry);
  check('and the cockpit moved with it',
    await page.evaluate(() => document.querySelector('.glpimajor-strip li.is-current')?.textContent.trim()) === 'Monitoring'
    && (await text(page, '.glpimajor-cockpit [data-glpimajor-goto]')) === 'Resolve');
  check('the state moved to monitoring',
    (await page.inputValue('select[name=state]')) === 'monitoring');
  check('and the promise was made',
    (await text(page, '.glpimajor-promise')).includes('Next update due'),
    await text(page, '.glpimajor-promise'));
  check('all three in ONE reload', warroomLoads === 1, `loads=${warroomLoads}`);

  // Resolving without an outcome is refused as a sentence in the composer —
  // before anything mutates, so nothing to undo and nothing reloads. Driven
  // from the cockpit's own primary button, which after monitoring is Resolve.
  await page.click('.glpimajor-cockpit [data-glpimajor-goto="resolved"]');
  await page.waitForTimeout(300);
  check('choosing Resolved reveals the outcome field',
    await page.locator('[data-glpimajor-outcome-block]:not([hidden])').count() === 1);
  await page.fill('[data-glpimajor-outcome]', '');
  await page.evaluate(() => { window.__warroomStillHere = true; });
  await page.click('[data-glpimajor-action=post]');
  await until(async () => (await page.locator('[data-glpimajor-post-error]:not([hidden])').count()) === 1);

  check('resolving without an outcome is refused inline',
    (await text(page, '[data-glpimajor-post-error]')).includes('Say what happened'),
    await text(page, '[data-glpimajor-post-error]'));
  check('with no reload', await page.evaluate(() => window.__warroomStillHere === true));
  check('and nothing was resolved',
    php(`foreach ($DB->request(['FROM'=>'glpi_plugin_glpimajor_incidents','WHERE'=>['id'=>${INCIDENT}]]) as $r) { echo $r['state']; }`) === 'monitoring');

  // A post that would do nothing at all is refused too. Re-choosing the
  // current pill is "keep".
  await page.click('[data-glpimajor-current="1"]');
  await page.fill('[data-glpimajor-update]', '');
  await page.click('[data-glpimajor-action=post]');
  await until(async () => (await text(page, '[data-glpimajor-post-error]')).includes('Nothing to post'));
  check('an empty post that changes nothing is refused inline',
    (await text(page, '[data-glpimajor-post-error]')).includes('Nothing to post'),
    await text(page, '[data-glpimajor-post-error]'));

  // Public with nothing to say is refused even when something else
  // would change: the audience is a publication, and there is nothing to
  // publish. The promise it rode in with is not made either — validation
  // comes before any mutation.
  const promiseBefore = php(`foreach ($DB->request(['FROM'=>'glpi_plugin_glpimajor_incidents','WHERE'=>['id'=>${INCIDENT}]]) as $r) { echo (string)$r['next_update_at']; }`);
  await page.selectOption('[data-glpimajor-next]', '15');
  await page.click('.glpimajor-audience-opt:has(input[value=customer])');
  await page.click('[data-glpimajor-action=post]');
  await until(async () => (await text(page, '[data-glpimajor-post-error]')).includes('something to say'));

  check('a public post with no content is refused inline',
    (await text(page, '[data-glpimajor-post-error]')).includes('something to say'),
    await text(page, '[data-glpimajor-post-error]'));
  check('still with no reload', await page.evaluate(() => window.__warroomStillHere === true));
  check('and the promise it carried was not made',
    php(`foreach ($DB->request(['FROM'=>'glpi_plugin_glpimajor_incidents','WHERE'=>['id'=>${INCIDENT}]]) as $r) { echo (string)$r['next_update_at']; }`) === promiseBefore);

  await page.click('.glpimajor-audience-opt:has(input[value=internal])');
  await page.selectOption('[data-glpimajor-next]', 'keep');

  await fullPage(page, `${SHOTS}/major-10-one-post.png`, { highlight: '.glpimajor-composer' });

  // ================================================== 7. resolution
  section('Resolution proposes, and closes nothing');

  const statusBefore = php(`foreach ($DB->request(['FROM'=>'glpi_tickets','WHERE'=>['id'=>${T_DUP}]]) as $r) { echo (int)$r['status']; }`);

  await page.goto(`${BASE}/plugins/glpimajor/front/incident.form.php?id=${INCIDENT}`, { waitUntil: 'networkidle' });
  await openEditDetails(page);

  await page.fill('textarea[name=outcome]',
    'A disk in the office file server failed and its array did not rebuild. The disk was replaced '
    + 'on site and access to the shared drive was restored at 12:40. No documents were lost.');
  await pick(page, 'state', 'resolved', 'Resolved');
  await page.click('button[name=update]');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(600);

  check('the incident is resolved',
    (await text(page, '.glpimajor-view')).length > 0
    && (await page.inputValue('select[name=state]')) === 'resolved');

  // The resolve event in the feed carries the outcome as written at that
  // moment, and the cockpit stops counting up and states the total.
  const resolveEntry = await page.evaluate(() =>
    document.querySelector('.glpimajor-feed-state')?.textContent.replace(/\s+/g, ' ').trim() || '');
  check('the feed\'s resolve event shows the outcome snapshot',
    resolveEntry.includes('Resolved') && resolveEntry.includes('No documents were lost'),
    resolveEntry.slice(0, 160));
  check('the cockpit says how long the whole thing took',
    /^Resolved after /.test(await text(page, '.glpimajor-cockpit-since')),
    await text(page, '.glpimajor-cockpit-since'));
  check('and offers no further transition button',
    await page.locator('.glpimajor-cockpit [data-glpimajor-goto]').count() === 0);

  const proposal = php(
    `$out=[];`
    + `foreach ($DB->request(['FROM'=>'glpi_plugin_glpimajor_affected','WHERE'=>['tickets_id'=>${T_DUP}]]) as $a) { $out['solution']=(int)$a['itilsolutions_id']; }`
    + `foreach ($DB->request(['FROM'=>'glpi_itilsolutions','WHERE'=>['itemtype'=>'Ticket','items_id'=>${T_DUP}]]) as $s) { $out['status']=(int)$s['status']; $out['n']=($out['n']??0)+1; }`
    + `foreach ($DB->request(['FROM'=>'glpi_tickets','WHERE'=>['id'=>${T_DUP}]]) as $t) { $out['ticket_status']=(int)$t['status']; }`
    + `foreach ($DB->request(['COUNT'=>'c','FROM'=>'glpi_tickets_users','WHERE'=>['tickets_id'=>${T_DUP},'type'=>2]]) as $c) { $out['assignees']=(int)$c['c']; }`
    + `echo json_encode($out);`
  );
  const p = JSON.parse(proposal);

  check('the attached ticket received a solution', p.solution > 0 && p.n === 1, proposal);
  check('waiting for approval, not approved', p.status === 2, `status=${p.status}`);
  check('the attached ticket was not solved or closed', String(p.ticket_status) === statusBefore,
    `${statusBefore} -> ${p.ticket_status}`);
  check('and nobody was assigned to it on the way past', p.assignees === 0, `assignees=${p.assignees}`);

  // What the requester of the attached ticket actually sees.
  await page.goto(`${BASE}/front/ticket.form.php?id=${T_DUP}`, { waitUntil: 'networkidle' });
  const dupText = await text(page);
  check('the attached ticket shows the proposed solution',
    dupText.includes('This was part of a wider issue'), dupText.slice(0, 300));
  check('and it speaks the public title', dupText.includes(TITLE));

  // ============================================ 7b. the portal, afterwards
  section('And when it is over, the portal goes quiet');

  // The banner has to go away on its own. A status banner that outlives the
  // outage teaches people to ignore status banners, which is the only way to
  // make this feature worse than not having it.
  await solicitor.goto(`${BASE}/Helpdesk`, { waitUntil: 'networkidle' });
  await solicitor.reload({ waitUntil: 'networkidle' });

  const stillBannered = await until(
    async () => (await solicitor.locator('.glpimajor-portal').count()) === 0,
    20000
  );
  check('the banner is gone once the incident is resolved', stillBannered,
    await text(solicitor, '.glpimajor-portal'));

  const quietAfter = await solicitor.evaluate(() =>
    Array.from(document.querySelectorAll('.navbar-nav a.nav-link'))
      .filter((a) => /service status/i.test(a.textContent))
      .map((a) => a.getAttribute('href'))[0] || null);

  check('but the quiet "Service status" link stays', quietAfter !== null);
  check('still pointing at their own office\'s page',
    !!quietAfter && quietAfter.endsWith(manchesterToken), quietAfter);

  // Asked of the server, not of the browser that read it four minutes ago.
  // The page is deliberately cacheable for a minute — that is the whole point
  // of serving it without a session — so a navigation to a URL this context
  // has already visited answers from its own cache and would assert nothing
  // about whether the republish across the subtree actually happened.
  const afterResponse = await office.request.get(`${BASE}${quietAfter}`,
    { headers: { 'Cache-Control': 'no-cache' } });
  const afterText = (await afterResponse.text()).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').toLowerCase();

  check('the office\'s page was rewritten too when the firm\'s incident resolved',
    afterText.includes(TITLE.toLowerCase()) && afterText.includes('resolved'),
    afterText.slice(0, 300));

  const londonAfter = await office.request.get(
    `${BASE}/plugins/glpimajor/front/status.php/${londonToken}`,
    { headers: { 'Cache-Control': 'no-cache' } }
  );
  const londonAfterText = (await londonAfter.text()).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').toLowerCase();
  check('and so was the other office\'s — the fan-out is the whole subtree, not the nearest child',
    londonAfterText.includes(TITLE.toLowerCase()) && londonAfterText.includes('resolved'),
    londonAfterText.slice(0, 300));

  check('no JavaScript errors on the portal either', officeErrs.length === 0, officeErrs.join(' | '));

  await office.close();

  // ================================================== 8. the review
  section('The review, and the lock');

  await page.goto(`${BASE}/plugins/glpimajor/front/incident.form.php?id=${INCIDENT}`, { waitUntil: 'networkidle' });

  await page.fill('[data-glpimajor-pir-field=what_happened]',
    'A disk in the office file server failed at 06:40 and nobody was told. The second disk of the '
    + 'pair failed at 09:12 and the array stopped serving. The first anybody knew was twelve people '
    + 'ringing at ten past nine.');
  await page.fill('[data-glpimajor-pir-field=impact]',
    'Twenty-four people could not open any document on the shared drive for three and a half hours. '
    + 'Two completions were posted late.');
  await page.fill('[data-glpimajor-pir-field=root_cause]',
    'The array had been degraded for five months. Its monitoring only alerts on a failed array, '
    + 'not on a degraded one.');
  await page.click('[data-glpimajor-action=pir-save]');
  await page.waitForTimeout(800);

  await page.fill('[data-glpimajor-action-text]', 'Alert on a degraded array, not only a failed one');
  await pick(page, 'glpimajor_action_owner', COMMANDER, 'Michael Okafor');
  await page.fill('[data-glpimajor-action-due]', '2026-09-19');
  await page.click('[data-glpimajor-action=action-add]');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(600);

  check('the agreed action is listed',
    (await text(page, '.glpimajor-actions-block')).includes('degraded array'),
    await text(page, '.glpimajor-actions-block'));

  const timeline = await contents(page, '.glpimajor-timeline');
  check('the timeline is assembled rather than typed',
    timeline.includes('Declared a major incident') && timeline.includes('Public update'),
    timeline.slice(0, 200));

  // The seam to the improvement register, if that plugin is here.
  const improveHere = php(`echo class_exists('GlpiPlugin\\\\Glpiimprove\\\\Candidate') && method_exists('GlpiPlugin\\\\Glpiimprove\\\\Candidate','fromPlugin') ? '1' : '0';`);
  if (improveHere === '1') {
    php(`$DB->update('glpi_configs',['value'=>'1'],['context'=>'plugin:glpimajor','name'=>'improve_push']);`);
    await page.reload({ waitUntil: 'networkidle' });

    check('with the register present, the action offers to go to it',
      await page.locator('[data-glpimajor-action=push]').count() === 1);

    await page.click('[data-glpimajor-action=push]');
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(800);

    // Read-only, in the neighbour's own table: the seam is only real if
    // something arrived on the other side of it.
    const candidate = php(
      `foreach ($DB->request(['FROM'=>'glpi_plugin_glpiimprove_candidates','WHERE'=>['source'=>'glpimajor'],'ORDER'=>['id DESC'],'LIMIT'=>1]) as $r)`
      + `{ echo json_encode(['id'=>(int)$r['id'],'title'=>$r['title'],'origin'=>$r['origin_itemtype'],'entity'=>(int)$r['entities_id'],'detail'=>mb_substr((string)$r['detail'],0,120)]); }`
    );
    check('the action reached the improvement register', candidate.length > 0, candidate);

    if (candidate.length > 0) {
      const c = JSON.parse(candidate);
      check('with the action as its title', c.title.includes('degraded array'), c.title);
      check('keyed on the action so a re-push is idempotent', /^piraction:\d+$/.test(c.origin), c.origin);
      check('in the entity', c.entity === ENTITY, String(c.entity));
      check('carrying the incident it came from', c.detail.includes(TITLE), c.detail);
    }

    check('and the button becomes a statement of fact',
      (await text(page, '.glpimajor-actions-block')).includes('in the improvement register'),
      await text(page, '.glpimajor-actions-block'));
  } else {
    console.log('SKIP  glpi-improve is not installed; the seam is not exercised');
  }

  await page.click('[data-glpimajor-action=pir-complete]');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(800);

  const pir = await text(page, '[data-glpimajor-pir]');
  check('the review is marked complete', pir.includes('Complete'), pir.slice(0, 160));
  check('and locked, in as many words', pir.includes('complete and locked'), pir.slice(0, 300));
  check('its fields are no longer editable',
    await page.locator('[data-glpimajor-pir-field=what_happened][disabled]').count() === 1);
  check('and the lock has a visible key', await page.locator('[data-glpimajor-action=pir-unlock]').count() === 1);

  // Open the assembled timeline for the picture: it is the part of a review
  // nobody has to write, and a collapsed <details> shows none of it.
  await page.click('.glpimajor-timeline summary');
  await page.waitForTimeout(300);

  await fullPage(page, `${SHOTS}/major-06-pir.png`, { highlight: '[data-glpimajor-pir]' });

  // ================================== 8b. the public post-mortem
  section('The public post-mortem: drafted with help, published by a human, first on the page');

  await page.goto(`${BASE}/plugins/glpimajor/front/incident.form.php?id=${INCIDENT}`, { waitUntil: 'networkidle' });

  check('with the incident resolved, Publish appears',
    await page.locator('[data-glpimajor-action=pm-publish]').count() === 1,
    await page.locator('[data-glpimajor-action=pm-publish]').textContent().catch(() => ''));

  // A blank publish is refused inline: the server validates before it
  // mutates, so the "no" is a sentence in the panel and the page never
  // reloads half-changed.
  await page.evaluate(() => { window.__pmMarker = 1; });
  const askedBefore = asked.length;
  await page.click('[data-glpimajor-action=pm-publish]');
  await page.waitForTimeout(1200);
  check('publishing asks first, even to refuse', asked.length > askedBefore,
    asked[asked.length - 1]);
  check('a blank publish is refused inline',
    (await text(page, '[data-glpimajor-pm-error]')).includes('nothing to publish'),
    await text(page, '[data-glpimajor-pm-error]'));
  check('with no reload', await page.evaluate(() => window.__pmMarker === 1));

  // The AI assist, behind the same switch as the composer's review. Flipped
  // on for this section through the plugin's own config row (snapshot and
  // restore — never glpi-ai's context), because the live default is off and
  // "off" is itself asserted first.
  check('while the switch is off, the assist degrades to a stated reason',
    (await text(page, '[data-glpimajor-postmortem]')).includes('AI drafting unavailable'));

  const aiWas = php(`foreach ($DB->request(['FROM'=>'glpi_configs','WHERE'=>['context'=>'plugin:glpimajor','name'=>'ai_review_enabled']]) as $r) { echo (string)$r['value']; }`);
  php(`$DB->update('glpi_configs',['value'=>'1'],['context'=>'plugin:glpimajor','name'=>'ai_review_enabled']);`);
  await page.reload({ waitUntil: 'networkidle' });

  let PM_TEXT = '';
  let aiDrafted = false;
  const draftButton = await page.locator('[data-glpimajor-action=pm-draft]').count();
  check('with the switch on and a provider ready, "Draft with AI" exists', draftButton === 1);

  if (draftButton === 1) {
    await page.click('[data-glpimajor-action=pm-draft]');
    // The quality model is allowed to be the slow one; the draft or an
    // honest inline refusal, whichever the provider gives.
    await until(async () =>
      (await page.inputValue('[data-glpimajor-pm-content]')).trim() !== ''
      || (await text(page, '[data-glpimajor-pm-error]')) !== '', 90000);

    const drafted = (await page.inputValue('[data-glpimajor-pm-content]')).trim();
    if (drafted !== '') {
      check('the draft landed in the textarea — never on the page', drafted.length > 50,
        drafted.slice(0, 200));
      check('and it is publishable prose: no hostname, no vendor, no markup',
        !/DC01|Veeam|^#|\n#|[*_]{2}/.test(drafted), drafted.slice(0, 200));
      PM_TEXT = drafted;
      aiDrafted = true;
    } else {
      // Degraded honestly: the provider said no, and the panel says why.
      check('the provider refused and the panel says why — never a dead button',
        (await text(page, '[data-glpimajor-pm-error]')).length > 5,
        await text(page, '[data-glpimajor-pm-error]'));
    }
  }

  // A human's pass over the text — the edit that makes ai_drafted an honest
  // "drafted and edited", not a model publishing. When no draft came back,
  // the human writes it all, which is exactly the degradation contract.
  PM_TEXT = (PM_TEXT !== '' ? PM_TEXT + '\n\n' : '')
    + 'We are sorry for the disruption this caused. If any document still fails to open, '
    + 'contact the service desk and it will be handled first.';
  await page.fill('[data-glpimajor-pm-content]', PM_TEXT);

  await page.click('[data-glpimajor-action=pm-save]');
  await page.waitForTimeout(800);
  check('Save draft answers in place',
    (await text(page, '[data-glpimajor-action=pm-save]')) === 'Saved'
    || (await text(page, '[data-glpimajor-postmortem]')).includes('Draft'));

  const askedBeforePublish = asked.length;
  await page.click('[data-glpimajor-action=pm-publish]');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(800);

  check('publishing asked first', asked.length > askedBeforePublish
    && /post-mortem/.test(asked[asked.length - 1]), asked[asked.length - 1]);

  const pmPanel = await text(page, '[data-glpimajor-postmortem]');
  check('the panel says Published, when and by whom',
    pmPanel.includes('Published') && pmPanel.includes('leads this incident'), pmPanel.slice(0, 300));
  check('and the origin is on the record when the AI drafted first',
    !aiDrafted || (await page.locator('.glpimajor-pm-origin').count()) === 1);
  check('the published state offers update and retract, not save',
    (await text(page, '[data-glpimajor-action=pm-publish]')) === 'Update the published text'
    && await page.locator('[data-glpimajor-action=pm-retract]').count() === 1
    && await page.locator('[data-glpimajor-action=pm-save]').count() === 0);

  await fullPage(page, `${SHOTS}/major-15-postmortem.png`, { highlight: '[data-glpimajor-postmortem]' });

  // The page the reader reads: the post-mortem leads the incident's entry
  // — after the title, before the update timeline — per the seam contract.
  const pmPageResponse = await page.request.get(
    `${BASE}/plugins/glpimajor/front/status.php/${token}`,
    { headers: { 'Cache-Control': 'no-cache' } }
  );
  const pmHtml = await pmPageResponse.text();
  const iTitle = pmHtml.indexOf(TITLE);
  const iPm = pmHtml.indexOf('<section class="pm">');
  const iLog = iTitle >= 0 ? pmHtml.indexOf('<ol class="log">', iTitle) : -1;
  check('the status page carries the post-mortem', iPm >= 0);
  check('it renders FIRST in the incident\'s entry: title, post-mortem, then the timeline',
    iTitle >= 0 && iPm > iTitle && iLog > iPm, `title@${iTitle} pm@${iPm} log@${iLog}`);
  check('carrying the published text with its stamp',
    pmHtml.includes('contact the service desk') && pmHtml.includes('Published'));

  // Retract: off the page at once, the text kept as a draft.
  await page.click('[data-glpimajor-action=pm-retract]');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(800);

  const retractedHtml = await (await page.request.get(
    `${BASE}/plugins/glpimajor/front/status.php/${token}`,
    { headers: { 'Cache-Control': 'no-cache' } }
  )).text();
  check('retracting removes it from the page immediately',
    !retractedHtml.includes('<section class="pm">'));
  check('while the text survives as a draft',
    (await page.inputValue('[data-glpimajor-pm-content]')).includes('contact the service desk'));

  // Put it back — the published state is the one the dark pass audits and
  // the one worth leaving on the demo world.
  await page.click('[data-glpimajor-action=pm-publish]');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(800);
  check('re-publishing after a retraction works',
    (await text(page, '[data-glpimajor-postmortem]')).includes('Published'));

  php(`$DB->update('glpi_configs',['value'=>'${aiWas === '' ? '0' : aiWas}'],['context'=>'plugin:glpimajor','name'=>'ai_review_enabled']);`);

  // ============================== dark pass, part 2: resolved and reviewed
  section('Dark-theme conformance pass — the locked PIR, and the quiet portal');

  // Reuses the dark technician context opened in part 1 — INCIDENT is now
  // resolved and its PIR complete, which is the state this half of the audit
  // needs and the part-1 pass ran before it existed.
  await dp.goto(`${BASE}/plugins/glpimajor/front/incident.form.php?id=${INCIDENT}`, { waitUntil: 'networkidle' });
  await dp.waitForTimeout(600);

  check('[dark] the PIR shows complete and locked',
    (await text(dp, '[data-glpimajor-pir]')).toLowerCase().includes('complete and locked'));

  await dp.click('.glpimajor-timeline summary');
  await dp.waitForTimeout(300);

  const PIR_MUTED = ['.text-muted', '.form-text', '.glpimajor-tl-at', '.glpimajor-tl-detail', '.glpimajor-proposed'];
  let r2 = await auditSurface(dp, '[data-glpimajor-pir]', PIR_MUTED);
  checkAudit('PIR (locked state + assembled timeline)', r2, PIR_MUTED);
  await fullPage(dp, `${SHOTS}/dark/major-dark-09-pir.png`, { highlight: '[data-glpimajor-pir]' });

  // The post-mortem panel, in its published state (section 8b left it so).
  const PM_MUTED = ['.text-muted', '.form-text', '.glpimajor-pm-meta', '.glpimajor-pm-origin'];
  let r3 = await auditSurface(dp, '[data-glpimajor-postmortem]', PM_MUTED);
  checkAudit('post-mortem panel (published state)', r3, PM_MUTED);
  await fullPage(dp, `${SHOTS}/dark/major-dark-11-postmortem.png`, { highlight: '[data-glpimajor-postmortem]' });

  check('[dark] no uncaught JavaScript errors across the whole dark pass', darkErrs.length === 0, darkErrs.join(' | '));
  await darkCtx.close();

  // The portal has to go quiet in dark exactly as it does in light: no
  // banner once the incident it was reporting on is resolved.
  php(`$DB->update('glpi_users', ['palette' => 'auror_dark'], ['name' => 'nadia.whitfield']);`);
  const quietCtx = await browser.newContext({ viewport: { width: 1400, height: 1100 } });
  const qp = await quietCtx.newPage();
  await qp.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await qp.fill('#login_name', PORTAL_USER);
  await qp.fill('input[type=password]', PORTAL_PASSWORD);
  await qp.click('button[type=submit]');
  await qp.waitForLoadState('networkidle');
  await qp.waitForTimeout(600);

  check('[dark] the portal banner is gone once the incident is resolved',
    await qp.locator('.glpimajor-portal').count() === 0);
  const quietDark = await qp.evaluate(() =>
    Array.from(document.querySelectorAll('.navbar-nav a.nav-link'))
      .filter((a) => /service status/i.test(a.textContent))
      .map((a) => a.getAttribute('href'))[0] || null);
  check('[dark] but the quiet "Service status" link stays', quietDark !== null);

  await quietCtx.close();

  // ================================================== the verdict
  section('Verdict');

  check('no JavaScript errors anywhere in that', errs.length === 0, errs.join(' | '));

  console.log(`\nincident ${INCIDENT}, address ${BASE}/plugins/glpimajor/front/status.php/${token}`);
  console.log(fail.length === 0 ? `\nall checks passed` : `\nFAILED: ${fail.join('; ')}`);

  await stranger.close();
  await browser.close();
  process.exit(fail.length === 0 ? 0 : 1);
})();
