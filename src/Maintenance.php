<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

use CommonDBTM;
use Dropdown;
use Html;

/**
 * A scheduled maintenance announcement.
 *
 * Self-contained: this plugin owns the table, the form and the list. It is an
 * itemtype rather than a section of the settings page because these are dated
 * objects with a lifecycle, there are several per entity, and — the part that
 * decides it — other plugins will eventually want to push them.
 *
 * That last part is what `source` and `external_key` are for. A change-approval
 * plugin that pushes "we are patching the firewall on Sunday" needs to be able
 * to push the same announcement twice without creating two, and to withdraw it
 * when the change is cancelled, without remembering our row id. See announce()
 * and withdraw(), which are the documented public API and the only two methods
 * another plugin should call.
 */
class Maintenance extends CommonDBTM
{
    public static $rightname = 'plugin_glpimajor_config';

    public $dohistory = true;

    public const SCHEDULED   = 'scheduled';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED   = 'completed';
    public const CANCELLED   = 'cancelled';

    /** The last reason announce() or withdraw() returned false. */
    private static string $last_error = '';

    public static function getTypeName($nb = 0)
    {
        return _n('Maintenance window', 'Maintenance windows', $nb, 'glpimajor');
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_glpimajor_maintenances';
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-clock';
    }

    public function isEntityAssign()
    {
        return true;
    }

    /**
     * Recursive downwards, on the same terms as an incident.
     *
     * A window announced at the entity's head office that takes all three
     * branches offline is one window. See Incident::maybeRecursive() for the
     * argument, and Tree for the reason a sibling cannot be reached.
     */
    public function maybeRecursive()
    {
        return true;
    }

    /** Does this window cover the sub-entities of the entity it is in? */
    public function coversSubEntities(): bool
    {
        return ((int) ($this->fields['is_recursive'] ?? 0)) === 1;
    }

    /** @return array<string,string> */
    public static function states(): array
    {
        return [
            self::SCHEDULED   => __('Scheduled', 'glpimajor'),
            self::IN_PROGRESS => __('In progress', 'glpimajor'),
            self::COMPLETED   => __('Completed', 'glpimajor'),
            self::CANCELLED   => __('Cancelled', 'glpimajor'),
        ];
    }

    public static function getMenuContent()
    {
        if (!\Session::haveRight(self::$rightname, READ)) {
            return false;
        }

        return [
            'title' => self::getTypeName(2),
            'page'  => '/plugins/glpimajor/front/maintenance.php',
            'icon'  => self::getIcon(),
            'links' => [
                'search' => '/plugins/glpimajor/front/maintenance.php',
                'add'    => '/plugins/glpimajor/front/maintenance.form.php',
            ],
        ];
    }

    // ======================================================================
    // Public API for other plugins
    // ======================================================================

    /**
     * Announce a maintenance window, or update one already announced.
     *
     * This is the seam another plugin uses. It does not require a session, a
     * right, or knowledge of our schema — a cron in another plugin can call it.
     *
     * Payload:
     *   entities_id   int      required. The entity the window belongs to.
     *   name          string   required. The title a reader reads.
     *   content       string   optional. What readers should expect.
     *   date_start    string   optional, 'Y-m-d H:i:s'.
     *   date_end      string   optional, 'Y-m-d H:i:s'.
     *   state         string   optional, one of scheduled|in_progress|completed|cancelled.
     *   source        string   optional but strongly advised. Your plugin key.
     *   external_key  string   optional but strongly advised. Your own id for this window.
     *
     * When `source` and `external_key` are both given, the call is an upsert:
     * announcing the same pair twice updates the first row rather than creating
     * a second. That is the whole point — a caller re-running its own sync must
     * not litter an entity's status page.
     *
     * Returns the row id, or false. `Maintenance::lastError()` says why.
     */
    public static function announce(array $payload): int|false
    {
        /** @var \DBmysql $DB */
        global $DB;

        self::$last_error = '';

        $entities_id = (int) ($payload['entities_id'] ?? -1);
        if ($entities_id < 0) {
            self::$last_error = 'entities_id is required';

            return false;
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            self::$last_error = 'name is required';

            return false;
        }

        $state = (string) ($payload['state'] ?? self::SCHEDULED);
        if (!array_key_exists($state, self::states())) {
            $state = self::SCHEDULED;
        }

        $source = mb_substr(trim((string) ($payload['source'] ?? '')), 0, 100);
        $key    = mb_substr(trim((string) ($payload['external_key'] ?? '')), 0, 190);
        $now    = date('Y-m-d H:i:s');

        $fields = [
            'entities_id' => $entities_id,
            // Back-compatible: a caller written before this existed sends
            // nothing and gets a window that covers exactly the entity it named,
            // which is what it has always meant.
            'is_recursive' => !empty($payload['is_recursive']) ? 1 : 0,
            'name'        => mb_substr($name, 0, 255),
            'content'     => trim((string) ($payload['content'] ?? '')),
            'date_start'  => self::datetime($payload['date_start'] ?? null),
            'date_end'    => self::datetime($payload['date_end'] ?? null),
            'state'       => $state,
            'date_mod'    => $now,
        ];

        $existing = null;
        if ($source !== '' && $key !== '') {
            $existing = self::byExternal($source, $key);
        }

        if ($existing !== null) {
            $DB->update(self::getTable(), $fields, ['id' => (int) $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $DB->insert(self::getTable(), $fields + [
                // NULL rather than '': the unique key on the pair is what makes
                // a re-push idempotent, and two empty strings are equal while
                // two NULLs are not. Stored as '' every window nobody pushed
                // would collide with every other one.
                'source'        => $source !== '' ? $source : null,
                'external_key'  => $key !== '' ? $key : null,
                'users_id'      => (int) \Session::getLoginUserID(),
                'date_creation' => $now,
            ]);

            // insert() returns true, not the id — and the id is the whole
            // return value of this method, since it is the handle the calling
            // plugin keeps on the window it just announced.
            $id = (int) $DB->insertId();

            if ($id <= 0) {
                self::$last_error = 'the row could not be written';

                return false;
            }
        }

        Publisher::onChange($entities_id, !empty($payload['is_recursive']));

        return $id;
    }

    /**
     * Withdraw an announcement pushed by another plugin.
     *
     * Marks it cancelled rather than deleting it: a window a reader already
     * read about and planned around should visibly go away, and a row that
     * silently vanishes is indistinguishable from one that was never pushed.
     */
    public static function withdraw(string $source, string $external_key): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        self::$last_error = '';

        $row = self::byExternal($source, $external_key);
        if ($row === null) {
            self::$last_error = 'no announcement with that source and key';

            return false;
        }

        $DB->update(self::getTable(), [
            'state'    => self::CANCELLED,
            'date_mod' => date('Y-m-d H:i:s'),
        ], ['id' => (int) $row['id']]);

        Publisher::onChange((int) $row['entities_id'], ((int) ($row['is_recursive'] ?? 0)) === 1);

        return true;
    }

    public static function lastError(): string
    {
        return self::$last_error;
    }

    // ======================================================================

    /** @return array<string,mixed>|null */
    public static function byExternal(string $source, string $external_key): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($source === '' || $external_key === '') {
            return null;
        }

        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['source' => $source, 'external_key' => $external_key],
                'LIMIT' => 1,
            ]) as $row
        ) {
            return $row;
        }

        return null;
    }

    /**
     * What an entity's status page should show: scheduled and in-progress only.
     *
     * Its own windows, and any declared at an ancestor as covering its
     * sub-entities. A firewall replacement at the entity's head office that
     * takes all three branches offline is announced once and read in three
     * places, which is the same argument as the recursive incident and has to
     * be the same rule — a child page carrying the parent's outage but not the
     * parent's planned work about it is worse than either alone.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function upcomingFor(int $entities_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => [
                    'OR'    => Tree::visibleFrom($entities_id),
                    'state' => [self::SCHEDULED, self::IN_PROGRESS],
                ],
                'ORDER' => ['date_start ASC'],
                'LIMIT' => 50,
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Move windows through their own lifecycle, and republish what changed.
     *
     * A window whose start has passed is in progress; one whose end has passed
     * is over. Nobody comes back at 02:00 to press a button, and a status page
     * still promising Sunday's maintenance on Tuesday is worse than no page.
     *
     * @return int how many rows moved
     */
    public static function rollWindows(): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $now     = date('Y-m-d H:i:s');
        $touched = [];

        foreach (
            $DB->request([
                'FROM'  => self::getTable(),
                'WHERE' => ['state' => [self::SCHEDULED, self::IN_PROGRESS]],
                'LIMIT' => 500,
            ]) as $row
        ) {
            $start = (string) ($row['date_start'] ?? '');
            $end   = (string) ($row['date_end'] ?? '');
            $state = (string) $row['state'];
            $next  = $state;

            if ($end !== '' && $end < $now) {
                $next = self::COMPLETED;
            } elseif ($start !== '' && $start <= $now && $state === self::SCHEDULED) {
                $next = self::IN_PROGRESS;
            }

            if ($next === $state) {
                continue;
            }

            $DB->update(self::getTable(), [
                'state'    => $next,
                'date_mod' => $now,
            ], ['id' => (int) $row['id']]);

            // A recursive window rolling into progress changes what every page
            // under it says, so the flag travels with the entity rather than
            // being looked up again after the loop.
            $entities_id = (int) $row['entities_id'];
            $touched[$entities_id] = ($touched[$entities_id] ?? false)
                || ((int) ($row['is_recursive'] ?? 0)) === 1;
        }

        foreach ($touched as $entities_id => $recursive) {
            Publisher::onChange((int) $entities_id, $recursive);
        }

        return count($touched);
    }

    // --------------------------------------------------------------- itemtype

    public function post_addItem()
    {
        Publisher::onChange((int) $this->fields['entities_id'], $this->coversSubEntities());
    }

    public function post_updateItem($history = true)
    {
        // The union of before and after, for the same reason as an incident:
        // clearing the box is the moment the descendants' pages are still
        // carrying a window that no longer belongs to them.
        Publisher::onChange(
            (int) $this->fields['entities_id'],
            $this->coversSubEntities() || ((int) ($this->oldvalues['is_recursive'] ?? 0)) === 1
        );
    }

    public function post_purgeItem()
    {
        Publisher::onChange((int) $this->fields['entities_id'], $this->coversSubEntities());
    }

    public function prepareInputForAdd($input)
    {
        $input['date_creation'] = date('Y-m-d H:i:s');

        return $this->validate($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->validate($input);
    }

    private function validate(array $input): array|false
    {
        if (array_key_exists('name', $input)) {
            $input['name'] = trim((string) $input['name']);
            if ($input['name'] === '') {
                \Session::addMessageAfterRedirect(
                    __s('A maintenance window needs a title the reader can read.', 'glpimajor'),
                    false,
                    ERROR
                );

                return false;
            }
        }

        if (array_key_exists('state', $input) && !array_key_exists((string) $input['state'], self::states())) {
            $input['state'] = self::SCHEDULED;
        }

        if (array_key_exists('is_recursive', $input)) {
            $input['is_recursive'] = ((int) $input['is_recursive']) === 1 ? 1 : 0;
        }

        // Same reason as announce(): an empty pair has to be NULL or the unique
        // key would let the instance hold exactly one hand-made window.
        foreach (['source', 'external_key'] as $field) {
            if (array_key_exists($field, $input) && trim((string) $input[$field]) === '') {
                $input[$field] = null;
            }
        }

        $start = (string) ($input['date_start'] ?? '');
        $end   = (string) ($input['date_end'] ?? '');
        if ($start !== '' && $end !== '' && $end < $start) {
            \Session::addMessageAfterRedirect(
                __s('The window ends before it starts.', 'glpimajor'),
                false,
                ERROR
            );

            return false;
        }

        $input['date_mod'] = date('Y-m-d H:i:s');

        return $input;
    }

    public function rawSearchOptions()
    {
        $options = [];

        $options[] = ['id' => 'common', 'name' => self::getTypeName(2)];

        $options[] = [
            'id'            => '1',
            'table'         => self::getTable(),
            'field'         => 'name',
            'name'          => __('Title', 'glpimajor'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];

        $options[] = [
            'id'         => '2',
            'table'      => self::getTable(),
            'field'      => 'state',
            'name'       => __('State', 'glpimajor'),
            'datatype'   => 'specific',
            'searchtype' => ['equals', 'notequals'],
        ];

        $options[] = [
            'id'       => '3',
            'table'    => self::getTable(),
            'field'    => 'date_start',
            'name'     => __('Starts', 'glpimajor'),
            'datatype' => 'datetime',
        ];

        $options[] = [
            'id'       => '4',
            'table'    => self::getTable(),
            'field'    => 'date_end',
            'name'     => __('Ends', 'glpimajor'),
            'datatype' => 'datetime',
        ];

        $options[] = [
            'id'       => '5',
            'table'    => self::getTable(),
            'field'    => 'source',
            'name'     => __('Announced by', 'glpimajor'),
            'datatype' => 'string',
        ];

        $options[] = [
            'id'       => '16',
            'table'    => self::getTable(),
            'field'    => 'content',
            'name'     => __('Description'),
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
            $label = self::states()[(string) $values['state']] ?? (string) $values['state'];

            return htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
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

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        // See Incident::showForm() for why this wraps the header/button pair
        // rather than sitting inside their table, and what it is for.
        echo "<div class='glpimajor-form'>";
        $this->showFormHeader($options);

        $e   = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $row = static fn(): string => "<tr class='tab_bg_1'>";

        echo $row() . '<td>' . __s('Title', 'glpimajor') . " <span class='text-red'>*</span></td>";
        echo "<td colspan='3'>";
        echo Html::input('name', ['value' => $this->fields['name'] ?? '', 'size' => 80, 'required' => 'required']);
        echo "<div class='form-text'>"
           . __s('This appears on the public status page exactly as written.', 'glpimajor')
           . '</div></td></tr>';

        echo $row() . '<td>' . __s('Starts', 'glpimajor') . '</td><td>';
        Html::showDateTimeField('date_start', ['value' => $this->fields['date_start'] ?? '']);
        echo '</td><td>' . __s('Ends', 'glpimajor') . '</td><td>';
        Html::showDateTimeField('date_end', ['value' => $this->fields['date_end'] ?? '']);
        echo '</td></tr>';

        echo $row() . '<td>' . __s('State', 'glpimajor') . '</td><td>';
        Dropdown::showFromArray('state', self::states(), [
            'value' => $this->fields['state'] ?? self::SCHEDULED,
        ]);
        echo "<div class='form-text'>"
           . __s('Moved automatically as the window opens and closes; set it by hand to cancel.', 'glpimajor')
           . '</div></td>';

        echo '<td>' . __s('Announced by', 'glpimajor') . '</td><td>';
        $source = (string) ($this->fields['source'] ?? '');
        echo $source !== ''
            ? $e($source) . ' <span class="text-muted">(' . $e((string) $this->fields['external_key']) . ')</span>'
            : '<span class="text-muted">' . __s('entered here', 'glpimajor') . '</span>';
        echo '</td></tr>';

        $entities_id = (int) ($this->fields['entities_id'] ?? 0);
        if (Tree::hasChildren($entities_id)) {
            echo $row() . '<td>' . __s('Coverage', 'glpimajor') . "</td><td colspan='3'>";
            echo "<input type='hidden' name='is_recursive' value='0'>";
            echo "<label class='form-check'>";
            echo "<input type='checkbox' class='form-check-input' name='is_recursive' value='1' "
               . ($this->coversSubEntities() ? "checked='checked'" : '') . '>';
            echo "<span class='form-check-label'>"
               . __s('Also covers sub-entities', 'glpimajor') . '</span></label>';
            echo "<div class='form-text'>"
               . __s('Ticked, this window appears on the status pages of every sub-entity of this '
                   . 'one as well as its own. It never travels sideways or upwards.', 'glpimajor')
               . '</div></td></tr>';
        }

        echo $row() . '<td>' . __s('Description') . "</td><td colspan='3'>";
        echo "<textarea class='form-control' name='content' rows='4'>"
           . $e($this->fields['content'] ?? '') . '</textarea>';
        echo "<div class='form-text'>"
           . __s('What readers should expect: what will be unavailable, and for how long. '
               . 'Plain language — this is published as written.', 'glpimajor')
           . '</div></td></tr>';

        $this->showFormButtons($options);
        echo '</div>';

        return true;
    }

    private static function datetime(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $at = strtotime($value);

        return $at === false ? null : date('Y-m-d H:i:s', $at);
    }
}
