<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

use Plugin;

/**
 * Your identity, for a page an outsider reads.
 *
 * The status page must never wear GLPI's face. If glpi-whitelabel is installed
 * it already knows the product name and holds the logo, so that is where this
 * looks first; absent, it falls back to what an administrator typed into this
 * plugin's own settings, and absent that, to a plain unbranded page — which is
 * honest, and better than a reader being shown somebody else's logo.
 *
 * Read-only. `Settings::save()`, `Settings::bump()` and everything in
 * `Assets` that writes are none of our business, and calling them from here
 * would make one plugin's cache-busting revision depend on another's outage.
 *
 * PLUGIN_WHITELABEL_CONFIG_CONTEXT is deliberately never referenced: that
 * constant only exists once whitelabel's own setup.php has run, so naming it is
 * a fatal error on an instance without the plugin.
 */
final class Brand
{
    /**
     * @return array{name:string,logo:string,support_email:string,support_phone:string,note:string}
     */
    public static function forEntity(int $entities_id, array $page = []): array
    {
        $cfg = Settings::all();

        return [
            'name'          => self::name(),
            'logo'          => self::logo(),
            'support_email' => (string) $cfg['status_support_email'],
            'support_phone' => (string) $cfg['status_support_phone'],
            'note'          => trim((string) ($page['support_note'] ?? '')),
        ];
    }

    /** The page's heading: the per-entity title, then the brand, then a default. */
    public static function title(array $page = []): string
    {
        $per_entity = trim((string) ($page['page_title'] ?? ''));
        if ($per_entity !== '') {
            return $per_entity;
        }

        $configured = trim((string) Settings::get('status_page_title'));
        if ($configured !== '') {
            return $configured;
        }

        $name = self::name();

        return $name !== '' ? $name . ' — Service status' : 'Service status';
    }

    public static function name(): string
    {
        $settings = self::whitelabelSettings();
        if ($settings !== null) {
            $name = trim((string) $settings::get('name'));
            if ($name !== '') {
                return $name;
            }
        }

        // Not $CFG_GLPI['name'] or anything else GLPI-flavoured. An unbranded
        // page says nothing rather than saying "GLPI".
        return '';
    }

    /**
     * The logo, as a `data:` URI when that is cheap, or an absolute URL.
     *
     * The threshold is not fussiness. The dev instance's logo is 1.18 MB, which
     * is 1.6 MB of base64 on a page somebody loads on a phone during an outage.
     * Self-containment is worth a lot and it is not worth that, so above the
     * ceiling the page links to whitelabel's own public asset endpoint — which
     * is already reachable without a session, and already sends an immutable
     * cache header keyed to its revision.
     */
    public static function logo(): string
    {
        $assets = self::whitelabelAssets();
        if ($assets === null) {
            return '';
        }

        // The login-card logo first: it is the one chosen to stand alone on a
        // plain background, which is exactly this page. Then the main one,
        // because a site that uploaded one logo meant it to be used.
        $slot = null;
        foreach (['logo_login', 'logo'] as $candidate) {
            if ($assets::has($candidate)) {
                $slot = $candidate;
                break;
            }
        }

        if ($slot === null) {
            return '';
        }

        $path = $assets::path($slot);
        if ($path === null || !is_readable($path)) {
            return self::assetUrl($slot);
        }

        if (!Settings::flag('status_embed_logo')) {
            return self::assetUrl($slot);
        }

        $ceiling = ((int) Settings::get('status_logo_max_kb')) * 1024;
        $size    = (int) @filesize($path);
        if ($size <= 0 || $size > $ceiling) {
            return self::assetUrl($slot);
        }

        $mime = self::mime($path);
        if ($mime === '') {
            return self::assetUrl($slot);
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return self::assetUrl($slot);
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    /** Whether the logo will be embedded, for the settings page to say so. */
    public static function logoIsEmbedded(): bool
    {
        return str_starts_with(self::logo(), 'data:');
    }

    private static function assetUrl(string $slot): string
    {
        global $CFG_GLPI;

        $settings = self::whitelabelSettings();
        $revision = $settings !== null ? (int) $settings::get('revision') : 1;

        return rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
            . rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/')
            . '/plugins/whitelabel/front/asset.php?name=' . rawurlencode($slot)
            . '&v=' . $revision;
    }

    private static function mime(string $path): string
    {
        if (str_ends_with(strtolower($path), '.svg')) {
            return 'image/svg+xml';
        }

        $info = @getimagesize($path);

        return is_array($info) ? (string) ($info['mime'] ?? '') : '';
    }

    /** @return class-string|null */
    private static function whitelabelSettings(): ?string
    {
        $class = 'GlpiPlugin\\Whitelabel\\Settings';

        if (!Plugin::isPluginActive('whitelabel') || !class_exists($class)) {
            return null;
        }

        return method_exists($class, 'get') ? $class : null;
    }

    /** @return class-string|null */
    private static function whitelabelAssets(): ?string
    {
        $class = 'GlpiPlugin\\Whitelabel\\Assets';

        if (!Plugin::isPluginActive('whitelabel') || !class_exists($class)) {
            return null;
        }

        return method_exists($class, 'path') && method_exists($class, 'has') ? $class : null;
    }
}
