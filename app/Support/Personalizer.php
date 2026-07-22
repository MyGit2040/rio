<?php

namespace App\Support;

use App\Models\Contact;

/**
 * Shared spintax + merge-tag + reference-ID rendering for one-off sends.
 *
 * Campaign sends (App\Jobs\SendCampaignMessage) keep their own pipeline because
 * they add campaign-only behaviour on top: per-recipient reference IDs stored
 * before delivery (retry-stable), round-robin variant slots assigned at build
 * time, footers merged from the campaign, and the opt-in anti-fingerprint pass.
 *
 * This class brings the SAME wording features to every other send surface —
 * Chats workspace, Single Message, campaign Test send, Sequences and Inbox
 * replies — so no message leaves the app with raw {a|b} spintax or unresolved
 * {{tokens}}:
 *
 *   - Spintax {a|b|c}: one option picked at random (or the first, when the
 *     workspace turned rotation off via the bulk_spintax setting).
 *   - Built-in tags: {{name}}, {{phone}}, {{date}}, and the reference-ID
 *     aliases {{variant_ref_id}} / {{ref_id}} / {{reference_id}} / {{random}} /
 *     [random] / [ref_id] / [reference_id] — six random digits behind the
 *     workspace's bulk_random_prefix.
 *   - Custom merge fields: any {{key}} resolved from the contact's attributes;
 *     unknown tokens collapse to blank so nothing leaks into the message.
 *
 * The regexes here MUST stay in sync with SendCampaignMessage::personalize().
 */
class Personalizer
{
    /**
     * Render a message body for one recipient.
     *
     * @param  array<string, mixed>|null  $settings  the workspace's tenant settings
     */
    public static function render(?string $body, ?Contact $contact, string $number, ?array $settings = null, ?string $referenceId = null): string
    {
        $settings ??= [];

        // 1) Spintax variation: {Hi|Hello} -> one option (natural wording variety).
        $text = self::spin((string) $body, (bool) data_get($settings, 'bulk_spintax', true));

        // 2) Built-in merge tags, including the prefixed random reference ID.
        $name = $contact?->name ?: 'there';
        $random = trim((string) data_get($settings, 'bulk_random_prefix', '')).($referenceId ?? self::referenceId());

        $text = preg_replace(
            ['/\{\{\s*name\s*\}\}/i', '/\{\{\s*phone\s*\}\}/i', '/\{\{\s*date\s*\}\}/i', '/\{\{\s*(?:variant_ref_id|ref_id|reference_id|random)\s*\}\}/i', '/\[(?:random|ref_id|reference_id)\]/i'],
            [$name, $number, now()->format('M j, Y'), $random, $random],
            $text
        );

        // 3) Custom merge fields: {{anything}} resolved from the contact's attributes.
        $attributes = (array) ($contact?->attributes ?? []);

        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function ($m) use ($attributes) {
            $key = strtolower($m[1]);
            foreach ($attributes as $k => $v) {
                if (strtolower((string) $k) === $key && ! is_array($v)) {
                    return (string) $v;
                }
            }

            return ''; // unknown field → blank, never a leftover {{token}}
        }, $text);
    }

    /**
     * Resolve {a|b|c} spintax. Random pick when rotation is on, else the first option.
     * Only matches single-brace groups containing a pipe, so {{name}} merge tags are untouched.
     */
    public static function spin(string $text, bool $random = true): string
    {
        return preg_replace_callback('/\{([^{}|]*(?:\|[^{}|]*)+)\}/', function ($m) use ($random) {
            $options = explode('|', $m[1]);

            return $random ? $options[array_rand($options)] : $options[0];
        }, $text);
    }

    /** Six random digits, the same shape campaign reference IDs use. */
    public static function referenceId(): string
    {
        return (string) random_int(100000, 999999);
    }

    /**
     * Variant chooser for one-off sends: pick at random from the pool
     * [main body, ...variants]. Campaigns rotate round-robin instead (the slot
     * is assigned at build time); a single send has no rotation state, so a
     * random pick gives the same spread over time.
     */
    public static function pickVariant(?string $body, ?array $variants): string
    {
        $pool = array_values(array_filter(
            array_merge([$body], $variants ?? []),
            fn ($v) => filled($v)
        ));

        return empty($pool) ? (string) $body : (string) $pool[array_rand($pool)];
    }

    /**
     * Workspace-level "common spintax" (Settings → Sending → bulk_spintax_groups):
     * synonym groups defined ONCE that vary the wording of EVERY message and
     * variant — so an author never has to paste {a|b} braces into hundreds of
     * variants.
     *
     * One group per line, options separated by | (e.g. "Book a demo|Request a
     * demo"). Wherever ANY option of a group appears in the text (whole
     * word/phrase, case-insensitive), it is swapped for a random option of that
     * group — or the FIRST option when the workspace turned rotation off
     * (bulk_spintax = false), mirroring inline spintax. The capitalisation of
     * what was written is kept, and URLs are never touched.
     *
     * @param  array<string, mixed>|null  $settings
     */
    public static function applySynonyms(string $text, ?array $settings = null): string
    {
        $settings ??= [];
        $groups = self::synonymGroups((string) data_get($settings, 'bulk_spintax_groups', ''));

        if ($text === '' || $groups === []) {
            return $text;
        }

        $random = (bool) data_get($settings, 'bulk_spintax', true);

        // Never rewrite inside a URL — split links out and vary only the prose.
        $parts = preg_split('/(https?:\/\/\S+)/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue; // URL segment — untouched
            }

            foreach ($groups as $options) {
                // Longest option first so "Book a demo now" wins over "Book a demo".
                $ordered = $options;
                usort($ordered, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
                $pattern = '/\b(?:'.implode('|', array_map(fn ($o) => preg_quote($o, '/'), $ordered)).')\b/iu';

                $part = (string) preg_replace_callback($pattern, function ($m) use ($options, $random) {
                    $choice = $random ? $options[array_rand($options)] : $options[0];
                    $first = mb_substr($m[0], 0, 1);

                    // Keep the capitalisation of what was written.
                    return $first !== mb_strtolower($first)
                        ? mb_strtoupper(mb_substr($choice, 0, 1)).mb_substr($choice, 1)
                        : $choice;
                }, $part);
            }

            $parts[$i] = $part;
        }

        return implode('', $parts);
    }

    /**
     * The workspace's common opening line (Settings → Sending → bulk_greeting):
     * one greeting — with its own spintax and merge tags — automatically topping
     * EVERY campaign message and variant, so it lives in one place instead of
     * being pasted into each variant. Empty setting = no change.
     *
     * @param  array<string, mixed>|null  $settings
     */
    public static function withCommonOpening(string $body, ?array $settings = null): string
    {
        $opening = trim((string) data_get($settings ?? [], 'bulk_greeting', ''));

        return $opening === '' ? $body : $opening."\n\n".ltrim($body);
    }

    /** @return array<int, array<int, string>> */
    private static function synonymGroups(string $raw): array
    {
        $groups = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $options = array_values(array_filter(array_map('trim', explode('|', $line)), fn ($o) => $o !== ''));

            if (count($options) >= 2) {
                $groups[] = $options;
            }
        }

        return $groups;
    }
}
