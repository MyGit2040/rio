<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Tenant;
use App\Models\WhatsappInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InboundDedupTest extends TestCase
{
    use RefreshDatabase;

    private WhatsappInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();

        config(['evolution.base_url' => 'https://evo.test', 'evolution.api_key' => 'k', 'evolution.webhook_secret' => null]);
        Http::fake(['*' => Http::response(['key' => ['id' => 'HOOK-1']], 200)]);

        $tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme-dedup',
            'evolution_base_url' => 'https://evo.test', 'evolution_api_key' => 'k',
            // Forward inbound replies to this hook number.
            'settings' => ['bulk_hook_number' => '971500000999'],
        ]);

        $this->instance = WhatsappInstance::create([
            'tenant_id' => $tenant->id, 'name' => 'Device 1',
            'instance_name' => 'inst-1', 'token' => 'tok', 'status' => 'open',
        ]);
    }

    private function payload(string $messageId, string $text): array
    {
        return [
            'event'    => 'messages.upsert',
            'instance' => 'inst-1',
            'data'     => [
                'key'     => ['remoteJid' => '971508564493@s.whatsapp.net', 'fromMe' => false, 'id' => $messageId],
                'pushName' => 'Bhaktar Travel',
                'message' => ['conversation' => $text],
            ],
        ];
    }

    public function test_same_inbound_processed_once_no_matter_how_many_deliveries(): void
    {
        $body = $this->payload('MSG-ABC', 'Thank you for contacting');

        // The engine delivers the same message three times.
        $this->postJson(route('webhooks.evolution'), $body)->assertOk();
        $this->postJson(route('webhooks.evolution'), $body)->assertOk();
        $this->postJson(route('webhooks.evolution'), $body)->assertOk();

        // Only one inbound record.
        $this->assertSame(1, Message::where('message_id', 'MSG-ABC')->where('direction', 'in')->count());

        // The hook number was notified exactly once (one sendText to the engine).
        $hookSends = 0;
        Http::assertSent(function ($r) use (&$hookSends) {
            if (str_contains($r->url(), '/message/sendText/') && ($r['number'] ?? null) === '971500000999') {
                $hookSends++;
            }

            return true;
        });
        $this->assertSame(1, $hookSends, 'Hook number should be notified once, not once per re-delivery.');
    }

    public function test_distinct_messages_still_recorded_and_forwarded(): void
    {
        $this->postJson(route('webhooks.evolution'), $this->payload('MSG-1', 'first'))->assertOk();
        $this->postJson(route('webhooks.evolution'), $this->payload('MSG-2', 'second'))->assertOk();

        $this->assertSame(2, Message::where('direction', 'in')->count());
    }
}
