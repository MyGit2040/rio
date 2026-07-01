<?php

namespace App\Jobs;

use App\Models\Alert;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Message;
use App\Models\WhatsappInstance;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a single, already-processed transactional message through the
 * Evolution API gateway.
 *
 * Design notes:
 *  - ONE message per job. Pacing is enforced by a flat, predictable delay
 *    (config evolution.flat_delay_seconds) acting purely as a load balancer
 *    for the VPS — no randomisation, no human simulation, no evasion.
 *  - On an auth failure (401/403 = the Baileys session dropped/expired) the
 *    job trips a circuit breaker: the device is marked Disconnected, the
 *    owning campaign loop is paused, and an alert is written to the dashboard.
 */
class SendTransactionalNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public WhatsappInstance $device,
        public Contact $contact,
        public string $message,
        public ?Campaign $campaign = null,
    ) {
    }

    public function handle(): void
    {
        // Bind tenant context so any models we write get the right tenant_id.
        Tenancy::run($this->device->tenant_id, function () {
            $this->dispatchMessage();
        });
    }

    private function dispatchMessage(): void
    {
        $baseUrl = rtrim((string) ($this->device->tenant->evolution_base_url ?: config('evolution.base_url')), '/');
        $apiKey  = (string) ($this->device->tenant->evolution_api_key ?: config('evolution.api_key'));

        // Evolution's real route is per-instance and authenticates via the `apikey` header.
        $endpoint = "{$baseUrl}/message/sendText/{$this->device->instance_name}";

        try {
            $response = Http::withHeaders(['apikey' => $apiKey])
                ->acceptJson()
                ->timeout(30)
                ->post($endpoint, [
                    'number' => $this->contact->phone,
                    'text'   => $this->message,
                ]);

            // --- Circuit breaker: session disconnected / token expired ---
            if (in_array($response->status(), [401, 403], true)) {
                $this->tripCircuitBreaker($response->status());

                return;
            }

            if ($response->failed()) {
                Log::error('Transactional send failed', [
                    'device' => $this->device->instance_name,
                    'status' => $response->status(),
                ]);

                return;
            }

            // Success — record the outbound message.
            Message::create([
                'whatsapp_instance_id' => $this->device->id,
                'contact_id'           => $this->contact->id,
                'campaign_id'          => $this->campaign?->id,
                'direction'            => 'out',
                'phone'                => $this->contact->phone,
                'type'                 => 'text',
                'body'                 => $this->message,
                'status'               => 'sent',
                'message_id'           => data_get($response->json(), 'key.id'),
            ]);

            $this->throttle();
        } catch (ConnectionException $e) {
            // Network/transport problem — log, do not falsely mark the device dead.
            Log::error('Evolution gateway unreachable', [
                'device' => $this->device->instance_name,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Flat, predictable inter-send delay. Pure load management — skipped in tests.
     *
     * For multi-worker scale, swap this for Redis::throttle()->allow(1)->every($n).
     */
    private function throttle(): void
    {
        $seconds = (int) config('evolution.flat_delay_seconds', 60);

        if ($seconds > 0 && ! app()->runningUnitTests()) {
            sleep($seconds);
        }
    }

    /**
     * Freeze the outbound loop and surface the failure on the dashboard.
     */
    private function tripCircuitBreaker(int $status): void
    {
        // 1) Toggle the device status column to Disconnected ('close' in our schema).
        $this->device->update(['status' => 'close']);

        // 2) Pause the owning campaign loop (remaining queued jobs then no-op).
        if ($this->campaign && $this->campaign->status === 'sending') {
            $this->campaign->update(['status' => 'paused']);
        }

        // 3) Log a clear alert to the dashboard notification table + Laravel log.
        Alert::create([
            'level'   => 'error',
            'title'   => "Device \"{$this->device->name}\" disconnected (HTTP {$status})",
            'body'    => 'The WhatsApp session expired or logged out. Outbound sending was paused. Reconnect the device, then resume.',
            'context' => ['device_id' => $this->device->id, 'http_status' => $status],
        ]);

        Log::alert("Circuit breaker: Evolution auth failure ({$status}) on '{$this->device->instance_name}' — device marked Disconnected, campaign paused.");
    }
}
