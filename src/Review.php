<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpimajor;

use Plugin;

/**
 * The optional second pair of eyes on a customer update.
 *
 * The model **reviews**; it never writes. That is not a limitation to be lifted
 * later — an outage is the single worst place in this suite to put a generative
 * model, because the failure mode is a confident sentence sent to a customer
 * about a system it has never seen. What it is good at is the thing a person
 * writing under pressure at 03:00 is worst at: noticing that they used the
 * word "failover", named a vendor, or promised nothing at all.
 *
 * Everything here is guarded four ways — plugin active, class present, feature
 * switched on locally, and the entity permitted by glpi-ai's own gate — and any
 * one of them being false yields a stated reason, never a silent skip. The
 * publish button works identically whether or not any of this is available.
 */
final class Review
{
    /** Roughly two pages. Longer than any update anybody should be publishing. */
    private const MAX_CHARS = 6000;

    /**
     * Why a review cannot happen here, or null when it can.
     *
     * A reason string rather than a bool, because every one of these ends up on
     * a technician's screen next to a button that did not do anything.
     */
    public static function refusal(int $entities_id): ?string
    {
        if (!Settings::flag('ai_review_enabled')) {
            return __('Update review is switched off in this plugin\'s settings.', 'glpimajor');
        }

        if (!Plugin::isPluginActive('glpiai') || !class_exists('GlpiPlugin\\Glpiai\\Client')) {
            return __('The AI plugin is not installed, so there is nothing to review with.', 'glpimajor');
        }

        $ai_settings = 'GlpiPlugin\\Glpiai\\Settings';
        $ai_client   = 'GlpiPlugin\\Glpiai\\Client';

        if (
            !class_exists($ai_settings)
            || !method_exists($ai_settings, 'entityAllowed')
            || !method_exists($ai_settings, 'flag')
            || !method_exists($ai_client, 'isReady')
            || !method_exists($ai_client, 'complete')
        ) {
            return __('The AI plugin is present but does not expose the interface this expects.', 'glpimajor');
        }

        if (!$ai_settings::flag('enabled') || !$ai_client::isReady()) {
            return __('AI features are switched off, or no provider is configured.', 'glpimajor');
        }

        // glpi-ai's gate, asked before we do the work rather than after. It
        // re-enforces this itself on every call; asking first is how the
        // refusal lands before somebody has written three paragraphs.
        if (!$ai_settings::entityAllowed($entities_id)) {
            return __('AI is not permitted for this entity in the AI plugin\'s settings.', 'glpimajor');
        }

        return null;
    }

    public static function available(int $entities_id): bool
    {
        return self::refusal($entities_id) === null;
    }

    /**
     * Review a draft.
     *
     * @return array{ok:bool,error?:string,jargon?:string[],internal?:string[],
     *               missing_next_step?:bool,notes?:string}
     */
    public static function of(string $draft, int $entities_id): array
    {
        $refusal = self::refusal($entities_id);
        if ($refusal !== null) {
            return ['ok' => false, 'error' => $refusal];
        }

        $draft = trim($draft);
        if ($draft === '') {
            return ['ok' => false, 'error' => __('There is nothing to review yet.', 'glpimajor')];
        }

        $draft = mb_substr($draft, 0, self::MAX_CHARS);

        $prompt_class = 'GlpiPlugin\\Glpiai\\Prompt';
        $client_class = 'GlpiPlugin\\Glpiai\\Client';

        try {
            $prompt = $prompt_class::make($draft, self::instruction())
                ->withTier($prompt_class::TIER_FAST)
                ->withSchema(self::schema(), 'update_review')
                ->withMaxTokens(700);

            // A ceiling, not an override. Somebody is waiting on this with a
            // half-written update in front of them; thirty seconds is already
            // longer than they will tolerate.
            $prompt->timeout = 30;

            $completion = $client_class::complete($prompt, $entities_id);
        } catch (\Throwable $e) {
            // Degrade honestly: a visible "could not answer", never a silent
            // skip that looks like a clean bill of health.
            return [
                'ok'    => false,
                'error' => sprintf(
                    __('The review could not be completed: %s', 'glpimajor'),
                    $e->getMessage()
                ),
            ];
        }

        $data = $completion->data;
        if (!is_array($data)) {
            return [
                'ok'    => false,
                'error' => __('The model did not return a usable review. Publish on your own '
                    . 'judgement.', 'glpimajor'),
            ];
        }

        return [
            'ok'                => true,
            'jargon'            => self::strings($data['jargon'] ?? []),
            'internal'          => self::strings($data['internal_detail'] ?? []),
            'missing_next_step' => (bool) ($data['missing_next_step'] ?? false),
            'notes'             => trim((string) ($data['notes'] ?? '')),
        ];
    }

    /** Was this review worth showing? An empty one is not worth a panel. */
    public static function hasFindings(array $review): bool
    {
        return ($review['jargon'] ?? []) !== []
            || ($review['internal'] ?? []) !== []
            || !empty($review['missing_next_step'])
            || trim((string) ($review['notes'] ?? '')) !== '';
    }

    private static function instruction(): string
    {
        // Written at the model rather than about it: it is being asked to be a
        // careful colleague reading over somebody's shoulder, not an editor.
        return "You are reviewing a short status update that an IT service provider is about to "
             . "publish to a customer during an outage. You do not rewrite it and you do not "
             . "suggest replacement wording.\n\n"
             . "Report only three things:\n"
             . "1. jargon — words or phrases a non-technical reader would not understand, quoted "
             . "exactly as they appear.\n"
             . "2. internal_detail — anything that should not leave the provider: server or host "
             . "names, vendor or product names, internal team or person names, ticket or case "
             . "references, IP addresses, blame, or speculation about cause stated as fact.\n"
             . "3. missing_next_step — true when the update does not tell the reader what happens "
             . "next or when they will hear again.\n\n"
             . "Report nothing you are not sure about. An update with no problems must come back "
             . "with empty lists and missing_next_step false. Keep notes to one sentence, and "
             . "leave it empty if you have nothing to add.";
    }

    /**
     * Plain types, plain nesting, no unions.
     *
     * glpi-ai translates one schema for four vendors and only that subset
     * survives all four — a cleverer schema is one that works on the provider
     * it was written against.
     */
    private static function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'jargon' => [
                    'type'  => 'array',
                    'items' => ['type' => 'string'],
                ],
                'internal_detail' => [
                    'type'  => 'array',
                    'items' => ['type' => 'string'],
                ],
                'missing_next_step' => ['type' => 'boolean'],
                'notes'             => ['type' => 'string'],
            ],
            'required' => ['jargon', 'internal_detail', 'missing_next_step'],
        ];
    }

    /** @return string[] */
    private static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $text = trim((string) $item);
                if ($text !== '') {
                    $out[] = mb_substr($text, 0, 200);
                }
            }
        }

        return array_slice($out, 0, 20);
    }
}
