<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

use CommonDBTM;
use CronTask;
use Dropdown;
use Html;
use Ticket;

/**
 * A major incident: one ticket promoted to a mode.
 *
 * 1:1 with a ticket, enforced by a unique key, because the alternative is a
 * parallel object that drifts from the thing everyone is actually working in.
 * Promotion never rewrites the ticket — not its status, not its priority, not
 * its category. GLPI's own machinery keeps owning all of that; this record owns
 * the questions GLPI has no field for: who is commanding, who is talking to the
 * the people affected, what we are prepared to say in public, and when we said we would
 * say the next thing.
 *
 * `name` is the *public* title, not the ticket's. That is the point
 * of the column: internal titles carry hostnames, vendor names and hypotheses
 * ("EXCH01 mailbox DB corrupt, restoring from Veeam"), and the status page is
 * read by outsiders. Defaulting it to the ticket title makes the safe path
 * the lazy path; having the column at all makes the safe path possible.
 */
class Incident extends CommonDBTM
{
    public static $rightname = 'plugin_glpimajor_declare';

    public $dohistory = true;

    public const INVESTIGATING = 'investigating';
    public const IDENTIFIED    = 'identified';
    public const MONITORING    = 'monitoring';
    public const RESOLVED      = 'resolved';

    public static function getTypeName($nb = 0)
    {
        return _n('Major incident', 'Major incidents', $nb, 'glpimajor');
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_glpimajor_incidents';
    }

    public static function getIcon()
    {
        return 'ti ti-urgent';
    }

    public function isEntityAssign()
    {
        return true;
    }

    /**
     * Recursive **downwards**, and only downwards.
     *
     * The first version of this plugin returned false here, on the argument
     * that a recursive incident would land on a sibling tenant's status page.
     * That argument was right about siblings and wrong about the shape of a
     * organisation: "Law Company" with offices in Manchester and London is one
     * organisation with three entities, and an outage that closes both offices is
     * one outage. Refusing to say so made the commander declare it three times
     * and keep three sets of updates in step by hand.
     *
     * What travels is the subtree of the declaring entity, computed by walking
     * *down* from it or *up* from a reader — see Tree. A sibling appears in
     * neither direction, so the leak the original decision feared is not
     * filtered out here, it is unreachable.
     *
     * Saying true also lets GLPI's own search engine do the same thing for the
     * incident list: `getEntitiesRestrictCriteria(..., true)` is only correct
     * for a table that has the column, and now this one does.
     */
    public function maybeRecursive()
    {
        return true;
    }

    /** Does this declaration cover the sub-entities of the entity it is in? */
    public function coversSubEntities(): bool
    {
        return ((int) ($this->fields['is_recursive'] ?? 0)) === 1;
    }

    /** @return array<string,string> state => label, in progression order */
    public static function states(): array
    {
        return [
            self::INVESTIGATING => __('Investigating', 'glpimajor'),
            self::IDENTIFIED    => __('Identified', 'glpimajor'),
            self::MONITORING    => __('Monitoring', 'glpimajor'),
            self::RESOLVED      => __('Resolved', 'glpimajor'),
        ];
    }

    public static function stateLabel(string $state): string
    {
        return self::states()[$state] ?? $state;
    }

    public static function isOpenState(string $state): bool
    {
        return $state !== self::RESOLVED;
    }

    /**
     * The Setup-menu entry, and the Add button that comes with it.
     *
     * GLPI reads a list page's action links from the menu registry, so an
     * itemtype with no entry here gets a list it is impossible to add to.
     * Naming the itemtype in Html::header() is necessary but not sufficient —
     * that argument only says which entry to look up.
     *
     * The paths are the ones GLPI derives from the namespace
     * (GlpiPlugin\Glpimajor\Incident -> /plugins/glpimajor/front/incident*.php).
     * Writing them by hand somewhere that disagrees with the derivation is how
     * a menu works while every row in the list 404s.
     */
    public static function getMenuContent()
    {
        if (!\Session::haveRight(self::$rightname, READ)) {
            return false;
        }

        $menu = [
            'title' => self::getTypeName(2),
            'page'  => '/plugins/glpimajor/front/incident.php',
            'icon'  => self::getIcon(),
            'links' => [
                'search' => '/plugins/glpimajor/front/incident.php',
            ],
        ];

        // Declaring happens from a ticket, not from a blank form — an incident
        // without a ticket has nothing to command. The Add button is offered
        // only to whoever could complete that form anyway.
        if (\Session::haveRight(self::$rightname, CREATE)) {
            $menu['links']['add'] = '/plugins/glpimajor/front/incident.form.php';
        }

        return $menu;
    }

    // ------------------------------------------------------------ lifecycle

    /**
     * Promote a ticket.
     *
     * Returns the new incident's id, or false with the reason already queued as
     * a session message.
     */
    public static function declareFor(Ticket $ticket, array $input = []): int|false
    {
        if ($ticket->isNewItem()) {
            return false;
        }

        $tickets_id = (int) $ticket->getID();

        if (self::forTicket($tickets_id) !== null) {
            \Session::addMessageAfterRedirect(
                __s('That ticket is already a major incident.', 'glpimajor'),
                false,
                ERROR
            );

            return false;
        }

        $now   = date('Y-m-d H:i:s');
        $me    = (int) \Session::getLoginUserID();
        $title = trim((string) ($input['name'] ?? ''));

        $incident = new self();
        $id       = $incident->add([
            'entities_id'        => (int) $ticket->fields['entities_id'],
            // The default is the ticket's title, so a declaration made in the
            // first thirty seconds of an outage still has something sayable on
            // it. The field is editable, and the form says why.
            'name'               => $title !== '' ? $title : (string) $ticket->fields['name'],
            'tickets_id'         => $tickets_id,
            // Only ever what the declaring form asked for. It is offered at all
            // only when the entity has sub-entities, and its default comes from
            // a setting rather than from this code, so an instance whose
            // entities are all single-site never sees the question.
            'is_recursive'       => !empty($input['is_recursive']) ? 1 : 0,
            'state'              => self::INVESTIGATING,
            'users_id_commander' => (int) ($input['users_id_commander'] ?? $me),
            'users_id_comms'     => (int) ($input['users_id_comms'] ?? 0),
            'users_id_declared'  => $me,
            'date_declared'      => $now,
            'next_update_at'     => $input['next_update_at'] ?? null,
            'date_creation'      => $now,
            'date_mod'           => $now,
        ]);

        if ($id === false) {
            return false;
        }

        $id = (int) $id;

        Events::record(
            $id,
            Events::DECLARED,
            sprintf('ticket #%d', $tickets_id),
            (int) $ticket->fields['entities_id']
        );

        Notifications::raise('declared', $id);
        Publisher::onChange(
            (int) $ticket->fields['entities_id'],
            !empty($input['is_recursive'])
        );

        return $id;
    }

    /** @return array<string,mixed>|null the MI row for a ticket, if there is one */
    public static function forTicket(int $tickets_id): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($tickets_id <= 0) {
            return null;
        }

        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['tickets_id' => $tickets_id],
                'LIMIT' => 1,
            ]) as $row
        ) {
            return $row;
        }

        return null;
    }

    /**
     * Open incidents declared in exactly this entity.
     *
     * The literal question, with no recursion in it. `openFor()` is what a
     * ticket asks; this is what an entity's own record is.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function openIn(int $entities_id): array
    {
        return self::openWhere(['mi.entities_id' => $entities_id]);
    }

    /**
     * Open incidents a ticket in this entity may belong to.
     *
     * Its entity's own, plus any declared at an ancestor as covering its
     * sub-entities. This is what the duplicate offer runs against, so a ticket
     * raised in the Manchester office is offered the outage declared at the
     * firm — and a ticket in a *sibling* firm is offered nothing, because a
     * sibling is in nobody's ancestor list.
     *
     * The join is to the ticket rather than a second query per incident: this
     * runs on every ticket view in the instance, and N+1 here is a latency cost
     * paid by people who are not having an outage.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function openFor(int $entities_id): array
    {
        return self::openWhere(['OR' => self::scoped($entities_id)]);
    }

    /**
     * Tree::visibleFrom(), with the table alias this query uses.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function scoped(int $entities_id): array
    {
        $out = [];
        foreach (Tree::visibleFrom($entities_id) as $clause) {
            $prefixed = [];
            foreach ($clause as $field => $value) {
                $prefixed['mi.' . $field] = $value;
            }
            $out[] = $prefixed;
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function openWhere(array $where): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'SELECT' => [
                    'mi.*',
                    't.itilcategories_id AS itilcategories_id',
                    't.locations_id AS locations_id',
                ],
                'FROM'      => self::getTable() . ' AS mi',
                'LEFT JOIN' => [
                    'glpi_tickets AS t' => [
                        'ON' => ['mi' => 'tickets_id', 't' => 'id'],
                    ],
                ],
                'WHERE' => $where + ['mi.state' => ['<>', self::RESOLVED]],
                'ORDER' => ['mi.date_declared DESC'],
                'LIMIT' => 50,
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
    }

    /** Every open incident, for the settings page's status card. */
    public static function allOpen(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['state' => ['<>', self::RESOLVED]],
                'ORDER' => ['date_declared DESC'],
                'LIMIT' => 200,
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Move an incident's state.
     *
     * Resolution is refused without an outcome summary when the setting says
     * so: "what actually happened" written six weeks later is fiction, and the
     * only moment anyone reliably knows is the moment they decide it is over.
     */
    public function setState(string $state, string $outcome = ''): bool
    {
        if (!array_key_exists($state, self::states())) {
            return false;
        }

        $was = (string) $this->fields['state'];
        if ($was === $state && $state !== self::RESOLVED) {
            return true;
        }

        $update = [
            'id'       => (int) $this->getID(),
            'state'    => $state,
            'date_mod' => date('Y-m-d H:i:s'),
        ];

        if ($state === self::RESOLVED) {
            $outcome = trim($outcome);
            if ($outcome === '' && Settings::flag('pir_required')) {
                \Session::addMessageAfterRedirect(
                    __s('Say what happened before resolving. One or two sentences is enough — '
                        . 'this is the only moment anyone reliably remembers.', 'glpimajor'),
                    false,
                    ERROR
                );

                return false;
            }

            $update['outcome']       = $outcome;
            $update['date_resolved'] = date('Y-m-d H:i:s');
            // The promise dies with the incident; a resolved incident whose
            // next-update time is in the past would nag forever.
            $update['next_update_at'] = null;
        }

        if (!$this->update($update)) {
            return false;
        }

        $this->getFromDB((int) $this->getID());

        Events::record(
            (int) $this->getID(),
            $state === self::RESOLVED ? Events::RESOLVED : Events::STATE,
            sprintf('%s -> %s', $was, $state),
            (int) $this->fields['entities_id']
        );

        // The feed's copy, typed and permanent — the audit row above is
        // pruned on retention and its detail is a sentence. The outcome rides
        // only on a resolve, as a snapshot of what was written at that
        // moment; see StateChange for both arguments.
        StateChange::record($this, $was, $state, $state === self::RESOLVED ? $outcome : '');

        if ($state === self::RESOLVED) {
            // Propose, never close. Every attached ticket gets the resolution
            // as a solution pending approval, and its status is left exactly
            // where the technician working it left it.
            Affected::proposeResolution($this);
            Sop::attachReview($this);
            Notifications::raise('resolved', (int) $this->getID());
        }

        Publisher::onChange((int) $this->fields['entities_id'], $this->coversSubEntities());

        return true;
    }

    public function setRoles(int $commander, int $comms): bool
    {
        $ok = $this->update([
            'id'                 => (int) $this->getID(),
            'users_id_commander' => $commander,
            'users_id_comms'     => $comms,
            'date_mod'           => date('Y-m-d H:i:s'),
        ]);

        if ($ok) {
            Events::record(
                (int) $this->getID(),
                Events::ROLE,
                sprintf('commander=%d comms=%d', $commander, $comms),
                (int) $this->fields['entities_id']
            );
        }

        return (bool) $ok;
    }

    /**
     * Promise the next update.
     *
     * Clearing `date_nagged` is load-bearing: moving the promise forward is the
     * comms owner answering the reminder, and a stale nag timestamp would
     * suppress the reminder for the new promise for a whole repeat window. Nag
     * guards against this too, from the other side; both are cheap and the
     * failure is silent.
     */
    public function promise(?string $when): bool
    {
        $ok = $this->update([
            'id'             => (int) $this->getID(),
            'next_update_at' => $when,
            'date_nagged'    => null,
            'date_mod'       => date('Y-m-d H:i:s'),
        ]);

        if ($ok) {
            Events::record(
                (int) $this->getID(),
                Events::PROMISE,
                (string) ($when ?? 'cleared'),
                (int) $this->fields['entities_id']
            );
        }

        return (bool) $ok;
    }

    /** The public title. Editing it is recorded; it is a public act. */
    public function retitle(string $title): bool
    {
        $title = trim($title);
        if ($title === '') {
            return false;
        }

        $was = (string) $this->fields['name'];
        if ($was === $title) {
            return true;
        }

        $ok = $this->update([
            'id'       => (int) $this->getID(),
            'name'     => $title,
            'date_mod' => date('Y-m-d H:i:s'),
        ]);

        if ($ok) {
            Events::record((int) $this->getID(), Events::TITLE, $was, (int) $this->fields['entities_id']);
            Publisher::onChange((int) $this->fields['entities_id'], $this->coversSubEntities());
        }

        return (bool) $ok;
    }

    /** The ticket this incident is a mode on, or null if it has been purged. */
    public function ticket(): ?Ticket
    {
        $ticket = new Ticket();

        return $ticket->getFromDB((int) ($this->fields['tickets_id'] ?? 0)) ? $ticket : null;
    }

    public static function forgetTicket(int $tickets_id): void
    {
        $row = self::forTicket($tickets_id);
        if ($row === null) {
            return;
        }

        $incident = new self();
        if ($incident->getFromDB((int) $row['id'])) {
            $entities_id = (int) $incident->fields['entities_id'];
            // Read before the delete: afterwards there is no row to ask, and a
            // recursive incident that vanishes without republishing its
            // descendants leaves an outage on three pages that no longer exists.
            $recursive   = $incident->coversSubEntities();
            $incident->delete(['id' => (int) $row['id']], true);
            Publisher::onChange($entities_id, $recursive);
        }
    }

    public function cleanDBonPurge()
    {
        /** @var \DBmysql $DB */
        global $DB;

        $id = (int) $this->getID();

        foreach (
            [
                'glpi_plugin_glpimajor_affected',
                'glpi_plugin_glpimajor_updates',
                'glpi_plugin_glpimajor_statechanges',
                'glpi_plugin_glpimajor_postmortems',
            ] as $table
        ) {
            $DB->delete($table, ['plugin_glpimajor_incidents_id' => $id]);
        }

        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_plugin_glpimajor_pirs',
                'WHERE'  => ['plugin_glpimajor_incidents_id' => $id],
            ]) as $row
        ) {
            $DB->delete('glpi_plugin_glpimajor_piractions', ['plugin_glpimajor_pirs_id' => (int) $row['id']]);
        }

        $DB->delete('glpi_plugin_glpimajor_pirs', ['plugin_glpimajor_incidents_id' => $id]);

        // The audit rows outlive the incident deliberately: "who deleted the
        // record of the outage" is exactly the question an audit trail exists
        // to answer, and it cannot answer it from inside the deleted record.
    }

    // ------------------------------------------------------------------ cron

    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'nag'   => ['description' => __('Remind the comms owner of an overdue update', 'glpimajor')],
            default => [],
        };
    }

    /**
     * Nag whoever owns comms on every open incident whose promise has passed.
     *
     * The timing decision lives in Nag, which is pure and tested; this walks
     * rows, sends, and records. Keeping the arithmetic out of here is what makes
     * "the reminder fired twice" or "never fired" something you can reproduce
     * without an outage to reproduce it in.
     */
    public static function cronNag(CronTask $task): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!Settings::flag('nag_enabled')) {
            return 0;
        }

        $grace  = (int) Settings::get('nag_grace_minutes');
        $repeat = (int) Settings::get('nag_repeat_minutes');
        $now    = time();
        $sent   = 0;

        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => [
                    'state'          => ['<>', self::RESOLVED],
                    'NOT'            => ['next_update_at' => null],
                ],
                'LIMIT' => 500,
            ]) as $row
        ) {
            $due = Nag::isDue(
                $row['next_update_at'] ?? null,
                $row['date_nagged'] ?? null,
                $grace,
                $repeat,
                $now
            );

            if (!$due) {
                continue;
            }

            $incident = new self();
            if (!$incident->getFromDB((int) $row['id'])) {
                continue;
            }

            Notifications::raise('overdue', (int) $row['id']);

            $incident->update([
                'id'          => (int) $row['id'],
                'date_nagged' => date('Y-m-d H:i:s', $now),
            ]);

            Events::record(
                (int) $row['id'],
                Events::NAGGED,
                sprintf('%d minutes late', Nag::overdueMinutes($row['next_update_at'] ?? null, $now)),
                (int) $row['entities_id'],
                0
            );

            $sent++;
        }

        $task->setVolume($sent);

        return $sent > 0 ? 1 : 0;
    }

    // --------------------------------------------------------------- search

    public function rawSearchOptions()
    {
        $options = [];

        $options[] = ['id' => 'common', 'name' => self::getTypeName(2)];

        $options[] = [
            'id'            => '1',
            'table'         => self::getTable(),
            'field'         => 'name',
            'name'          => __('Public title', 'glpimajor'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];

        $options[] = [
            'id'            => '2',
            'table'         => self::getTable(),
            'field'         => 'state',
            'name'          => __('State', 'glpimajor'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
        ];

        // The ticket's number rather than a join to its title.
        //
        // A search option that reaches glpi_tickets from here needs a join
        // GLPI's engine cannot express for this shape — the incident points at
        // the ticket, not the other way round — and getting it wrong makes the
        // whole list unrenderable rather than making one column empty. The
        // number is enough to find it, and the incident's own public
        // title is the more useful thing to read in a list anyway.
        $options[] = [
            'id'            => '3',
            'table'         => self::getTable(),
            'field'         => 'tickets_id',
            'name'          => __('Ticket number', 'glpimajor'),
            'datatype'      => 'integer',
            'massiveaction' => false,
        ];

        $options[] = [
            'id'       => '4',
            'table'    => 'glpi_users',
            'field'    => 'name',
            'name'     => __('Commander', 'glpimajor'),
            'datatype' => 'dropdown',
            'linkfield' => 'users_id_commander',
        ];

        $options[] = [
            'id'        => '5',
            'table'     => 'glpi_users',
            'field'     => 'name',
            'name'      => __('Comms owner', 'glpimajor'),
            'datatype'  => 'dropdown',
            'linkfield' => 'users_id_comms',
        ];

        $options[] = [
            'id'       => '6',
            'table'    => self::getTable(),
            'field'    => 'date_declared',
            'name'     => __('Declared', 'glpimajor'),
            'datatype' => 'datetime',
        ];

        $options[] = [
            'id'       => '7',
            'table'    => self::getTable(),
            'field'    => 'date_resolved',
            'name'     => __('Resolved', 'glpimajor'),
            'datatype' => 'datetime',
        ];

        $options[] = [
            'id'            => '8',
            'table'         => self::getTable(),
            'field'         => 'affected_count',
            'name'          => __('Affected tickets', 'glpimajor'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];

        $options[] = [
            'id'       => '9',
            'table'    => self::getTable(),
            'field'    => 'next_update_at',
            'name'     => __('Next update promised', 'glpimajor'),
            'datatype' => 'datetime',
        ];

        $options[] = [
            'id'       => '16',
            'table'    => self::getTable(),
            'field'    => 'comment',
            'name'     => __('Comments'),
            'datatype' => 'text',
        ];

        $options[] = [
            'id'       => '19',
            'table'    => self::getTable(),
            'field'    => 'date_mod',
            'name'     => __('Last update'),
            'datatype' => 'datetime',
        ];

        $options[] = [
            'id'       => '80',
            'table'    => 'glpi_entities',
            'field'    => 'completename',
            'name'     => \Entity::getTypeName(1),
            'datatype' => 'dropdown',
        ];

        // 86 is GLPI's own id for this column everywhere it appears, so a saved
        // search written against another itemtype's recursion reads the same.
        $options[] = [
            'id'       => '86',
            'table'    => self::getTable(),
            'field'    => 'is_recursive',
            'name'     => __('Also covers sub-entities', 'glpimajor'),
            'datatype' => 'bool',
        ];

        $options[] = [
            'id'       => '121',
            'table'    => self::getTable(),
            'field'    => 'date_creation',
            'name'     => __('Creation date'),
            'datatype' => 'datetime',
        ];

        return $options;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        if ($field === 'state') {
            return htmlspecialchars(self::stateLabel((string) $values['state']), ENT_QUOTES, 'UTF-8');
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if ($field === 'state') {
            return Dropdown::showFromArray(
                $name,
                self::states(),
                ['value' => $values, 'display' => false] + $options
            );
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    // ----------------------------------------------------------------- form

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        // Wraps showFormHeader()..showFormButtons() rather than sitting inside
        // the table they emit — a <div> is valid around a <form>, not inside a
        // <table> before its first <tr>. Gives the dark-theme .form-text /
        // .text-muted fix (public/css/major.css) a root to scope to, since this
        // form's markup is otherwise indistinguishable from any other core form.
        echo "<div class='glpimajor-form'>";
        $this->showFormHeader($options);

        $e   = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $row = static fn(): string => "<tr class='tab_bg_1'>";

        echo $row() . '<td>' . __s('Public title', 'glpimajor')
           . " <span class='text-red'>*</span></td><td colspan='3'>";
        echo Html::input('name', [
            'value'    => $this->fields['name'] ?? '',
            'size'     => 80,
            'required' => 'required',
        ]);
        echo "<div class='form-text'>"
           . __s('This is what the reader reads on the status page. It defaults to the ticket '
               . 'title, which is usually the wrong thing to publish — internal titles carry '
               . 'hostnames and hypotheses.', 'glpimajor')
           . '</div></td></tr>';

        echo $row() . '<td>' . __s('Ticket') . '</td><td>';
        $ticket = $this->ticket();
        if ($ticket !== null) {
            echo $ticket->getLink();
        } elseif ($this->isNewItem()) {
            echo Html::input('tickets_id', ['value' => (int) ($_GET['tickets_id'] ?? 0), 'type' => 'number']);
            echo "<div class='form-text'>"
               . __s('The ticket to promote. Declaring from the ticket itself fills this in.', 'glpimajor')
               . '</div>';
        } else {
            echo "<span class='text-muted'>" . __s('The ticket no longer exists.', 'glpimajor') . '</span>';
        }
        echo '</td>';

        echo '<td>' . __s('State', 'glpimajor') . '</td><td>';
        Dropdown::showFromArray('state', self::states(), [
            'value' => $this->fields['state'] ?? self::INVESTIGATING,
        ]);
        echo '</td></tr>';

        // Only where it can mean anything. An instance whose entities are all
        // single-site never sees the question, and a leaf entity showing a
        // "covers sub-entities" tick box that covers nothing is a control that
        // teaches people to ignore controls.
        $entities_id = (int) ($this->fields['entities_id'] ?? 0);
        if (!$this->isNewItem() && Tree::hasChildren($entities_id)) {
            $children = Tree::descendants($entities_id);

            echo $row() . '<td>' . __s('Coverage', 'glpimajor') . "</td><td colspan='3'>";
            // The unchecked box posts nothing, and a coverage that silently
            // stays on because a box was cleared is the wrong way round.
            echo "<input type='hidden' name='is_recursive' value='0'>";
            echo "<label class='form-check'>";
            echo "<input type='checkbox' class='form-check-input' name='is_recursive' value='1' "
               . ($this->coversSubEntities() ? "checked='checked'" : '') . '>';
            echo "<span class='form-check-label'>"
               . __s('Also covers sub-entities', 'glpimajor') . '</span></label>';
            echo "<div class='form-text'>"
               . sprintf(
                   __s('This entity has %d sub-entities. Ticked, the incident appears on their '
                     . 'status pages as well as this one, their tickets are offered the attach, '
                     . 'and every published page under here is rewritten when you post a '
                     . 'public update. It never travels sideways or upwards.', 'glpimajor'),
                   count($children)
               )
               . '</div></td></tr>';
        }

        echo $row() . '<td>' . __s('Commander', 'glpimajor') . '</td><td>';
        \User::dropdown([
            'name'   => 'users_id_commander',
            'value'  => (int) ($this->fields['users_id_commander'] ?? 0),
            'right'  => 'all',
            'entity' => (int) ($this->fields['entities_id'] ?? 0),
        ]);
        echo "<div class='form-text'>" . __s('Owns the technical response.', 'glpimajor') . '</div>';
        echo '</td>';

        echo '<td>' . __s('Comms owner', 'glpimajor') . '</td><td>';
        \User::dropdown([
            'name'   => 'users_id_comms',
            'value'  => (int) ($this->fields['users_id_comms'] ?? 0),
            'right'  => 'all',
            'entity' => (int) ($this->fields['entities_id'] ?? 0),
        ]);
        echo "<div class='form-text'>"
           . __s('Owns telling everyone outside. Deliberately not the same person — whoever is on the '
               . 'console is the worst-placed person to also be writing updates.', 'glpimajor')
           . '</div></td></tr>';

        echo $row() . '<td>' . __s('Next update promised', 'glpimajor') . '</td><td>';
        Html::showDateTimeField('next_update_at', [
            'value' => $this->fields['next_update_at'] ?? '',
        ]);
        echo '</td>';
        echo '<td>' . __s('Affected tickets', 'glpimajor') . '</td><td>';
        echo (int) ($this->fields['affected_count'] ?? 0);
        echo "<div class='form-text'>"
           . __s('Including the ticket this incident was declared from.', 'glpimajor')
           . '</div>';
        echo '</td></tr>';

        echo $row() . '<td>' . __s('Outcome summary', 'glpimajor') . "</td><td colspan='3'>";
        echo "<textarea class='form-control' name='outcome' rows='3'>"
           . $e($this->fields['outcome'] ?? '') . '</textarea>';
        echo "<div class='form-text'>"
           . __s('Required to resolve. Two sentences written now beat two pages written in six '
               . 'weeks, because in six weeks nobody remembers.', 'glpimajor')
           . '</div></td></tr>';

        echo $row() . '<td>' . __s('Comments') . "</td><td colspan='3'>";
        echo "<textarea class='form-control' name='comment' rows='2'>"
           . $e($this->fields['comment'] ?? '') . '</textarea></td></tr>';

        $this->showFormButtons($options);
        echo '</div>';

        return true;
    }

    public function prepareInputForAdd($input)
    {
        return $this->validate($input, true);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->validate($input, false);
    }

    /**
     * The invariants, checked in one place.
     *
     * The 1:1 with a ticket is enforced by a unique key as well; this exists so
     * the refusal is a sentence rather than a duplicate-key error page.
     */
    private function validate(array $input, bool $is_add): array|false
    {
        if (array_key_exists('name', $input)) {
            $input['name'] = trim((string) $input['name']);
            if ($input['name'] === '') {
                \Session::addMessageAfterRedirect(
                    __s('A major incident needs a public title.', 'glpimajor'),
                    false,
                    ERROR
                );

                return false;
            }
        }

        if (array_key_exists('state', $input) && !array_key_exists((string) $input['state'], self::states())) {
            return false;
        }

        // Never taken from the form, in either direction. The entity decides
        // which entity's status page a declaration lands on, and the
        // denormalised count is owned by Affected::recount().
        unset($input['entities_id'], $input['affected_count']);

        // Anything but exactly 1 is 0. This column decides how many entities'
        // pages a sentence lands on, so it is not a field to let a truthy
        // string through.
        if (array_key_exists('is_recursive', $input)) {
            $input['is_recursive'] = ((int) $input['is_recursive']) === 1 ? 1 : 0;
        }

        if ($is_add) {
            $tickets_id = (int) ($input['tickets_id'] ?? 0);
            if ($tickets_id <= 0) {
                \Session::addMessageAfterRedirect(
                    __s('A major incident is a mode on a ticket. Declare it from the ticket.', 'glpimajor'),
                    false,
                    ERROR
                );

                return false;
            }

            $ticket = new Ticket();
            if (!$ticket->getFromDB($tickets_id)) {
                return false;
            }

            if (self::forTicket($tickets_id) !== null) {
                \Session::addMessageAfterRedirect(
                    __s('That ticket is already a major incident.', 'glpimajor'),
                    false,
                    ERROR
                );

                return false;
            }

            $input['entities_id']       = (int) $ticket->fields['entities_id'];
            $input['users_id_declared'] = (int) \Session::getLoginUserID();
            $input['date_declared']     = $input['date_declared'] ?? date('Y-m-d H:i:s');
            $input['date_creation']     = date('Y-m-d H:i:s');
        }

        $input['date_mod'] = date('Y-m-d H:i:s');

        return $input;
    }

    public function post_addItem()
    {
        // The column defaults to 0 and Affected::recount() owns it; asking for
        // a recount here is what makes a fresh declaration read "1 affected
        // ticket" — the declaring ticket is the outage's first — on every add
        // path, the declare control and the bare form alike.
        Affected::recount((int) $this->getID());
    }

    public function post_updateItem($history = true)
    {
        // Any change to an incident may change what the status page says, and
        // working out which fields those are in every code path is how a page
        // ends up stale in exactly one of them.
        //
        // The fan-out is asked of the *union* of what the row said before and
        // what it says now. Turning coverage off is exactly the moment the
        // descendant pages have to be rewritten — they are still carrying an
        // outage they are no longer entitled to — and asking only the new value
        // would leave it on all of them until the cron came round.
        $recursive = $this->coversSubEntities()
            || ((int) ($this->oldvalues['is_recursive'] ?? 0)) === 1;

        Publisher::onChange((int) $this->fields['entities_id'], $recursive);
    }
}
