<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

/**
 * What we said we would do differently.
 *
 * Rows rather than a textarea, with an owner and a date, because an action
 * without a name against it is a sentence in a document. It also gives the
 * improvement seam something discrete to offer — see Improve.
 */
final class PirAction
{
    public const TABLE = 'glpi_plugin_glpimajor_piractions';

    public const OPEN    = 'open';
    public const DONE    = 'done';
    public const DROPPED = 'dropped';

    /** @return array<string,string> */
    public static function statuses(): array
    {
        return [
            self::OPEN    => __('Open', 'glpimajor'),
            self::DONE    => __('Done', 'glpimajor'),
            self::DROPPED => __('Dropped', 'glpimajor'),
        ];
    }

    public static function add(int $pirs_id, string $content, int $users_id_owner, ?string $due): int|false
    {
        /** @var \DBmysql $DB */
        global $DB;

        $content = trim($content);
        if ($pirs_id <= 0 || $content === '') {
            return false;
        }

        $DB->insert(self::TABLE, [
            'plugin_glpimajor_pirs_id' => $pirs_id,
            'content'                  => $content,
            'users_id_owner'           => max(0, $users_id_owner),
            'due_date'                 => self::date($due),
            'status'                   => self::OPEN,
            'date_creation'            => date('Y-m-d H:i:s'),
        ]);

        // insert() returns true, not the id.
        $id = (int) $DB->insertId();

        return $id > 0 ? $id : false;
    }

    public static function setStatus(int $id, string $status): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!array_key_exists($status, self::statuses())) {
            return false;
        }

        return (bool) $DB->update(self::TABLE, ['status' => $status], ['id' => $id]);
    }

    public static function delete(int $id): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        return (bool) $DB->delete(self::TABLE, ['id' => $id]);
    }

    public static function byId(int $id): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (
            $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id], 'LIMIT' => 1]) as $row
        ) {
            return $row;
        }

        return null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function forPir(int $pirs_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['plugin_glpimajor_pirs_id' => $pirs_id],
                'ORDER' => ['id ASC'],
                'LIMIT' => 200,
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
    }

    public static function recordPush(int $id, int $candidates_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->update(self::TABLE, [
            'improve_candidates_id' => $candidates_id,
            'date_pushed'           => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    private static function date(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $at = strtotime($value);

        return $at === false ? null : date('Y-m-d', $at);
    }
}
