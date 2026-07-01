<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One interface over the three AI providers (OpenAI / Gemini / Claude).
 * Each workspace configures its own provider + key in Settings; falls back to
 * the platform's OPENAI_API_KEY.
 */
class AiService
{
    public function __construct(
        protected string $provider,
        protected string $key,
    ) {
    }

    public static function forTenant(?Tenant $tenant): static
    {
        $s = $tenant?->settings ?? [];
        $provider = $s['ai_provider'] ?? 'openai';

        $key = match ($provider) {
            'gemini' => $s['ai_gemini_key'] ?? '',
            'claude' => $s['ai_claude_key'] ?? '',
            default  => $s['ai_openai_key'] ?? config('services.openai.key', ''),
        };

        return new static($provider, (string) $key);
    }

    public function configured(): bool
    {
        return $this->key !== '';
    }

    public function providerLabel(): string
    {
        return ['openai' => 'ChatGPT', 'gemini' => 'Gemini', 'claude' => 'Claude'][$this->provider] ?? $this->provider;
    }

    /**
     * Generate text from a system + user prompt. Returns null on failure.
     */
    public function generate(string $system, string $user): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            return match ($this->provider) {
                'gemini' => $this->gemini($system, $user),
                'claude' => $this->claude($system, $user),
                default  => $this->openai($system, $user),
            };
        } catch (\Throwable $e) {
            Log::error('AI generate failed', ['provider' => $this->provider, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function openai(string $system, string $user): ?string
    {
        $r = Http::withToken($this->key)->timeout(45)->post('https://api.openai.com/v1/chat/completions', [
            'model'       => config('services.ai.openai_model', 'gpt-4o-mini'),
            'temperature' => 0.9,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ]);

        return $r->successful() ? trim((string) data_get($r->json(), 'choices.0.message.content')) : null;
    }

    private function claude(string $system, string $user): ?string
    {
        $r = Http::withHeaders([
            'x-api-key'         => $this->key,
            'anthropic-version' => '2023-06-01',
        ])->timeout(45)->post('https://api.anthropic.com/v1/messages', [
            'model'      => config('services.ai.claude_model', 'claude-haiku-4-5-20251001'),
            'max_tokens' => 1500,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $user]],
        ]);

        return $r->successful() ? trim((string) data_get($r->json(), 'content.0.text')) : null;
    }

    private function gemini(string $system, string $user): ?string
    {
        $model = config('services.ai.gemini_model', 'gemini-1.5-flash');
        $r = Http::timeout(45)->post(
            "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$this->key}",
            ['contents' => [['parts' => [['text' => $system."\n\n".$user]]]]],
        );

        return $r->successful() ? trim((string) data_get($r->json(), 'candidates.0.content.parts.0.text')) : null;
    }
}
