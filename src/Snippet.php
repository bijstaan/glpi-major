<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

/**
 * Per-entity comms templates.
 *
 * Configuration, not an object anybody searches for, so these are managed from
 * a section of the settings page and have no menu entry of their own — the same
 * call glpi-sop makes about its own settings.
 *
 * They exist because the first update of an outage is written by whoever is
 * nearest, under pressure, and the difference between a good one and a bad one
 * is mostly structure. A snippet is a starting point that already has the
 * structure in it.
 */
final class Snippet
{
    public const TABLE = 'glpi_plugin_glpimajor_snippets';

    public static function add(int $entities_id, string $name, string $audience, string $content, bool $recursive): int|false
    {
        /** @var \DBmysql $DB */
        global $DB;

        $name = trim($name);
        if ($name === '') {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        $DB->insert(self::TABLE, [
            'entities_id'   => $entities_id,
            'is_recursive'  => $recursive ? 1 : 0,
            'name'          => mb_substr($name, 0, 255),
            'audience'      => $audience === Update::INTERNAL ? Update::INTERNAL : Update::CUSTOMER,
            'content'       => trim($content),
            'date_creation' => $now,
            'date_mod'      => $now,
        ]);

        // insert() returns true, not the id.
        $id = (int) $DB->insertId();

        return $id > 0 ? $id : false;
    }

    public static function update(int $id, string $name, string $audience, string $content, bool $recursive): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $name = trim($name);
        if ($id <= 0 || $name === '') {
            return false;
        }

        return (bool) $DB->update(self::TABLE, [
            'name'         => mb_substr($name, 0, 255),
            'audience'     => $audience === Update::INTERNAL ? Update::INTERNAL : Update::CUSTOMER,
            'content'      => trim($content),
            'is_recursive' => $recursive ? 1 : 0,
            'date_mod'     => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public static function delete(int $id): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        return (bool) $DB->delete(self::TABLE, ['id' => $id]);
    }

    /** Every snippet, for the settings page. @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'ORDER' => ['entities_id ASC', 'name ASC'],
                'LIMIT' => 500,
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Snippets usable in an entity: its own, plus recursive ones from above.
     *
     * The inheritance is GLPI's own — a template written once at the top of the
     * tree should not need copying into forty customers — and the direction is
     * the safe one: a child never leaks a snippet up to its parent.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forEntity(int $entities_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ancestors = getAncestorsOf('glpi_entities', $entities_id);

        $where = [
            'OR' => [
                ['entities_id' => $entities_id],
                [
                    'entities_id'  => array_map('intval', array_values($ancestors)),
                    'is_recursive' => 1,
                ],
            ],
        ];

        $out = [];
        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => $where,
                'ORDER' => ['name ASC'],
                'LIMIT' => 200,
            ]) as $row
        ) {
            $out[] = $row;
        }

        return $out;
    }
}
