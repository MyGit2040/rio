<?php

namespace App\Jobs;

use App\Models\WebhookEndpoint;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Delivers an event to the tenant's active outbound webhook endpoints.
 * Each POST is signed with HMAC-SHA256 over the raw body (header X-Eagle-Signature).
 */
class DispatchWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $tenantId,
        public string $event,
        public array $payload,
    ) {
    }

    /**
     * Fire-and-forget helper — queues the job only if the event has listeners.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fire(int $tenantId, string $event, array $payload): void
    {
        Tenancy::run($tenantId, function () use ($tenantId, $event, $payload) {
            $hasListener = WebhookEndpoint::where('is_active', true)
                ->whereJsonContains('events', $event)
                ->exists();

            if ($hasListener) {
                self::dispatch($tenantId, $event, $payload);
            }
        });
    }

    public function handle(): void
    {
        Tenancy::run($this->tenantId, function () {
            $endpoints = WebhookEndpoint::where('is_active', true)
                ->whereJsonContains('events', $this->event)
                ->get();

            foreach ($endpoints as $endpoint) {
                $this->deliver($endpoint);
            }
        });
    }

    private function deliver(WebhookEndpoint $endpoint): void
    {
        $body = json_encode([
            'event'      => $this->event,
            'data'       => $this->payload,
            'sent_at'    => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES);

        $headers = ['Content-Type' => 'application/json', 'X-Eagle-Event' => $this->event];

        if ($endpoint->secret) {
            $headers['X-Eagle-Signature'] = hash_hmac('sha256', $body, $endpoint->secret);
        }

        try {
            Http::withHeaders($headers)->timeout(10)->withBody($body, 'application/json')->post($endpoint->url);
            $endpoint->forceFill(['last_fired_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('Outbound webhook failed', ['endpoint' => $endpoint->id, 'error' => $e->getMessage()]);
        }
    }
}
