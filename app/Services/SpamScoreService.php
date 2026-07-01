<?php

namespace App\Services;

/**
 * Estimates how "spammy" a WhatsApp message looks, to help users write cleaner
 * copy that is less likely to be flagged by recipients or platform filters.
 *
 * This is a content-quality aid (the opposite of evasion): it nudges toward
 * shorter, link-light, personal, non-salesy messages.
 */
class SpamScoreService
{
    /**
     * Common spam-trigger words/phrases (recipient reports + classic filters).
     */
    private const SPAM_WORDS = [
        'free', '100% free', 'free gift', 'winner', 'you won', 'congratulations', 'urgent',
        'act now', 'click here', 'click below', 'limited time', 'limited offer', 'offer expires',
        'exclusive deal', 'discount', 'cash', 'prize', 'guarantee', 'guaranteed', 'risk free',
        'no cost', 'no obligation', 'buy now', 'order now', 'cheap', 'lowest price', 'credit',
        'loan', 'debt', 'casino', 'lottery', 'jackpot', 'bitcoin', 'crypto', 'investment',
        'earn money', 'make money', 'work from home', 'double your', 'get rich', 'weight loss',
        'miracle', 'once in a lifetime', "don't miss", 'hurry', 'instant', 'claim now',
        'verify your account', 'suspended', 'whatsapp gift', 'selected', 'reward',
    ];

    /**
     * @return array{score:int, level:string, issues:array<int,array{label:string,detail:string,points:int}>, suggestions:array<int,string>, stats:array<string,mixed>}
     */
    public function analyze(string $text): array
    {
        $text = trim($text);
        $lower = mb_strtolower($text);
        $issues = [];
        $suggestions = [];

        // --- Links ---
        preg_match_all('/\b(?:https?:\/\/|www\.)\S+/i', $text, $linkMatches);
        $links = count($linkMatches[0]);
        if ($links > 0) {
            $pts = min(30, 15 + ($links - 1) * 8);
            $issues[] = ['label' => "{$links} link".($links > 1 ? 's' : ''), 'detail' => 'Messages with links to people who didn’t opt in are a top spam signal.', 'points' => $pts];
            $suggestions[] = 'Remove links, or send the link only after the person replies.';
        }

        // --- Emails ---
        preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $text, $emailMatches);
        $emails = count($emailMatches[0]);
        if ($emails > 0) {
            $issues[] = ['label' => "{$emails} email address".($emails > 1 ? 'es' : ''), 'detail' => 'Email addresses in promo blasts look automated.', 'points' => 10];
        }

        // --- Spam words ---
        $hits = [];
        foreach (self::SPAM_WORDS as $word) {
            if (str_contains($lower, $word)) {
                $hits[] = $word;
            }
        }
        if ($hits) {
            $pts = min(35, count($hits) * 6);
            $issues[] = ['label' => count($hits).' spam-trigger words', 'detail' => 'Flagged: '.implode(', ', array_slice($hits, 0, 8)).(count($hits) > 8 ? '…' : ''), 'points' => $pts];
            $suggestions[] = 'Reword salesy terms like “'.implode('”, “', array_slice($hits, 0, 3)).'”.';
        }

        // --- ALL CAPS ---
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $capsWords = array_filter($words, fn ($w) => mb_strlen($w) >= 3 && preg_match('/[A-Z]/', $w) && $w === mb_strtoupper($w) && ! preg_match('/[0-9]/', $w));
        if (count($capsWords) >= 2) {
            $issues[] = ['label' => 'Shouting (ALL CAPS)', 'detail' => count($capsWords).' fully-capitalised words.', 'points' => 10];
            $suggestions[] = 'Use normal sentence case instead of ALL CAPS.';
        }

        // --- Excessive punctuation ---
        if (preg_match('/[!?]{3,}|!{2,}.*!{2,}/', $text)) {
            $issues[] = ['label' => 'Excessive punctuation', 'detail' => 'Repeated !!! / ??? reads as spam.', 'points' => 8];
            $suggestions[] = 'Use at most one exclamation mark.';
        }

        // --- Emojis ---
        preg_match_all('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}]/u', $text, $emojiMatches);
        $emojis = count($emojiMatches[0]);
        if ($emojis > 5) {
            $issues[] = ['label' => "{$emojis} emojis", 'detail' => 'Lots of emojis looks promotional.', 'points' => 6];
            $suggestions[] = 'Keep emojis to one or two.';
        }

        // --- Personalisation ---
        if ($text !== '' && ! preg_match('/\{\{\s*\w+\s*\}\}/', $text)) {
            $issues[] = ['label' => 'No personalisation', 'detail' => 'Generic, identical copy is easier to flag as a blast.', 'points' => 6];
            $suggestions[] = 'Add {{name}} so each message is personalised.';
        }

        // --- Length ---
        $len = mb_strlen($text);
        if ($len > 0 && $len < 15) {
            $issues[] = ['label' => 'Very short', 'detail' => 'Tiny messages with a link/offer look like spam.', 'points' => 5];
        } elseif ($len > 900) {
            $issues[] = ['label' => 'Very long', 'detail' => 'Walls of text get low engagement.', 'points' => 5];
        }

        // --- Money ---
        if (preg_match('/(\$|€|£|aed|usd|inr|rs\.?)\s?\d|\d+%\s?(off|discount)/i', $text)) {
            $issues[] = ['label' => 'Price / money offer', 'detail' => 'Explicit prices and “% off” are promo signals.', 'points' => 5];
        }

        $score = min(100, array_sum(array_column($issues, 'points')));
        $level = $score <= 25 ? 'low' : ($score <= 55 ? 'medium' : 'high');

        if (! $issues) {
            $suggestions[] = 'Looks clean. Still only message people who opted in.';
        }

        return [
            'score'       => $score,
            'level'       => $level,
            'issues'      => $issues,
            'suggestions' => array_values(array_unique($suggestions)),
            'stats'       => ['links' => $links, 'emails' => $emails, 'spam_words' => $hits, 'length' => $len, 'emojis' => $emojis],
        ];
    }
}
