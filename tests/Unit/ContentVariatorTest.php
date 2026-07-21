<?php

namespace Tests\Unit;

use App\Support\ContentVariator;
use PHPUnit\Framework\TestCase;

class ContentVariatorTest extends TestCase
{
    private function stripZeroWidth(string $text): string
    {
        return str_replace(["\u{200B}", "\u{200C}"], '', $text);
    }

    public function test_empty_or_whitespace_input_is_returned_unchanged(): void
    {
        $this->assertSame('', ContentVariator::vary(''));
        $this->assertSame('   ', ContentVariator::vary('   '));
    }

    public function test_the_visible_word_count_is_preserved(): void
    {
        $input = 'Hello there this is a short campaign message about your order';
        $expectedWords = count(explode(' ', $input));

        for ($i = 0; $i < 300; $i++) {
            $visible = $this->stripZeroWidth(ContentVariator::vary($input));
            $this->assertCount($expectedWords, explode(' ', $visible), 'Variation must never add or drop a word.');
        }
    }

    public function test_only_the_leading_word_may_change_the_rest_are_identical(): void
    {
        $input = 'Hi Ahmed your appointment is confirmed for Monday at noon';
        $inputWords = explode(' ', $input);

        for ($i = 0; $i < 300; $i++) {
            $words = explode(' ', $this->stripZeroWidth(ContentVariator::vary($input)));
            // Every word after the first must be byte-identical to the original.
            $this->assertSame(array_slice($inputWords, 1), array_slice($words, 1));
        }
    }

    public function test_urls_are_never_broken(): void
    {
        $url = 'https://example.com/track/abc?ref=EG-482913';
        $input = "Check your order {$url} now please";

        for ($i = 0; $i < 300; $i++) {
            $visible = $this->stripZeroWidth(ContentVariator::vary($input));
            $this->assertStringContainsString($url, $visible, 'A zero-width char must never land inside a URL.');
        }
    }

    public function test_a_multiword_message_actually_gets_varied(): void
    {
        // A multi-word message always receives at least one zero-width character,
        // so its byte signature differs from the plain copy.
        $input = 'Your delivery is on the way today';
        $varied = ContentVariator::vary($input);

        $this->assertNotSame($input, $varied);
        $this->assertTrue(
            str_contains($varied, "\u{200B}") || str_contains($varied, "\u{200C}"),
            'Expected at least one zero-width character to be inserted.',
        );
    }

    public function test_greeting_synonyms_stay_within_the_safe_set(): void
    {
        $allowed = ['hi', 'hey', 'hello']; // "Hi" may become one of these

        for ($i = 0; $i < 300; $i++) {
            $first = strtolower(explode(' ', $this->stripZeroWidth(ContentVariator::vary('Hi team, quick update')))[0]);
            $this->assertContains($first, $allowed);
        }
    }
}
