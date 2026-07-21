<?php

namespace App\Support;

/**
 * Structural anti-fingerprint variation for outgoing message text.
 *
 * Meta's spam models look for structural fingerprints — the same bytes sent to
 * many recipients. Spintax (App\Jobs\SendCampaignMessage::spin) already varies
 * the *wording*; this varies the *structure* invisibly, so two sends of the
 * "same" message are not byte-identical even when the words happen to match.
 *
 * Every technique here is deliberately conservative, because the reader must see
 * no difference and nothing must break:
 *
 *  - Zero-width characters are inserted ONLY at spaces between words. A
 *    zero-width character inside a URL or a phone number would corrupt it, so
 *    insertion never happens mid-token — only in the gaps, which are always safe.
 *  - Whitespace jitter only ever adds an invisible zero-width at a gap; it never
 *    changes visible spacing.
 *  - Synonym swaps are limited to a tiny, safe, single-word greeting map applied
 *    to at most one leading word, so meaning, tone and word count are preserved.
 *
 * Opt-in per workspace (bulk_antifingerprint), off by default. Applied as the
 * final step of personalisation, after spintax, merge tags and link wrapping —
 * so there are no {{tokens}} left to disturb, only resolved text.
 */
class ContentVariator
{
    /** ZWSP (U+200B) and ZWNJ (U+200C): invisible in WhatsApp, they change the byte signature. */
    private const ZERO_WIDTH = ["\u{200B}", "\u{200C}"];

    /** How many word-gaps, at most, receive a zero-width character. */
    private const MAX_INSERTS = 3;

    /**
     * A small, safe, single-word greeting map. Single words only, so a swap is
     * always 1:1 and never changes the word count or the sentence structure.
     *
     * @var array<string, array<int, string>>
     */
    private const SYNONYMS = [
        'hi'     => ['hey', 'hello'],
        'hello'  => ['hi', 'hey'],
        'hey'    => ['hi', 'hello'],
        'thanks' => ['cheers'],
        'greetings' => ['hello'],
    ];

    /**
     * Return a structurally varied copy of $text that reads identically.
     *
     * Empty/whitespace-only input is returned unchanged: there is nothing to
     * vary, and inserting invisible characters into an empty string is pointless.
     */
    public static function vary(string $text): string
    {
        if (trim($text) === '') {
            return $text;
        }

        $text = self::swapLeadingGreeting($text);

        return self::insertZeroWidth($text);
    }

    /**
     * Insert up to MAX_INSERTS zero-width characters at randomly chosen spaces.
     *
     * Splitting on a single space and re-joining with an occasional zero-width
     * appended keeps every insertion at a word boundary — never inside a URL,
     * mention or number — so no visible token can be broken.
     */
    private static function insertZeroWidth(string $text): string
    {
        // Only single spaces are eligible seams. Preserving newlines and runs of
        // whitespace verbatim avoids disturbing formatted (multi-line) messages.
        $parts = explode(' ', $text);

        $gapCount = count($parts) - 1;
        if ($gapCount < 1) {
            return $text;
        }

        $inserts = random_int(1, min(self::MAX_INSERTS, $gapCount));

        // Choose distinct gap indices (1..gapCount) to receive a zero-width char.
        $chosen = (array) array_rand(array_fill(1, $gapCount, true), min($inserts, $gapCount));
        $chosen = array_flip($chosen);

        $out = '';
        foreach ($parts as $i => $part) {
            $out .= $part;

            if ($i < $gapCount) {
                $out .= ' ';
                if (isset($chosen[$i + 1])) {
                    $out .= self::ZERO_WIDTH[random_int(0, count(self::ZERO_WIDTH) - 1)];
                }
            }
        }

        return $out;
    }

    /**
     * Swap the first word for a safe synonym, occasionally, when it is a known
     * greeting. Case (Title / lower) is preserved; punctuation attached to the
     * word (e.g. "Hi,") is kept in place.
     */
    private static function swapLeadingGreeting(string $text): string
    {
        // ~40% of the time, so the original wording still appears often.
        if (random_int(1, 100) > 40) {
            return $text;
        }

        return preg_replace_callback('/^(\s*)([A-Za-z]+)/', function (array $m): string {
            $lead = $m[1];
            $word = $m[2];
            $options = self::SYNONYMS[strtolower($word)] ?? null;

            if ($options === null) {
                return $m[0];
            }

            $choice = $options[random_int(0, count($options) - 1)];

            // Match the original capitalisation of the first letter.
            if ($word[0] === strtoupper($word[0])) {
                $choice = ucfirst($choice);
            }

            return $lead.$choice;
        }, $text, 1);
    }
}
