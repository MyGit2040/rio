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
}
