<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Message;
use App\Models\Suppression;
use App\Models\Tenant;
use App\Models\WhatsappInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inbound messages arriving on the BAILEYS webhook must be persisted through
 * the same pipeline as the OpenWA engine. Before InboundMessageRecorder the
 * Baileys endpoint only called the chatbot — no Message row, so the Inbox,
 * the chat workspace, campaign Responses, contact-graph protection and STOP
 * opt-outs were all silently dead on Baileys devices.
 */
class BaileysInboundStoreTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec-test';

    private Tenant $tenant;

    private WhatsappInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeGateway();
        config(['baileys.webhook_secret' => self::SECRET]);

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-baileys-in']);

        $this->instance = WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Line 1', 'driver' => 'baileys',
            'instance_name' => 'bl-dev-1', 'token' => 'tok', 'status' => 'open',
        ]);
    }

    /** Deliver a signed gateway event exactly as eagleto-baileys-gateway posts it. */
    private function deliver(array $payload, ?string $eventId = null): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($payload);
        $timestamp = (string) time();

        return $this->call('POST', route('webhooks.baileys'), [], [], [], [
            'HTTP_X_EAGLETO_TIMESTAMP' => $timestamp,
            'HTTP_X_EAGLETO_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$raw, self::SECRET),
            'HTTP_X_EAGLETO_EVENT_ID'  => $eventId ?? ($payload['event_id'] ?? 'evt-'.uniqid()),
            'CONTENT_TYPE'             => 'application/json',
            'HTTP_ACCEPT'              => 'application/json',
        ], $raw);
    }

    private function receivedPayload(string $eventId, string $messageId, string $text, string $phone = '971508564493'): array
    {
        return [
            'event_id'    => $eventId,
            'event_type'  => 'message.received',
            'instance_id' => 'bl-dev-1',
            'data'        => [
                'whatsapp_message_id' => $messageId,
                'chat_jid'            => $phone.'@s.whatsapp.net',
                'sender_jid'          => $phone.'@s.whatsapp.net',
                'phone'               => $phone,
                'push_name'           => 'Bhaktar Travel',
                'kind'                => 'text',
                'text'                => $text,
                'quoted_message_id'   => null,
                'media'               => null,
                'timestamp'           => now()->toIso8601String(),
            ],
        ];
    }

    public function test_inbound_text_is_stored_as_a_message_row(): void
    {
        $this->deliver($this->receivedPayload('evt-1', 'WAMID-1', 'Hello, is this available?'))->assertOk();

        $this->assertDatabaseHas('messages', [
            'whatsapp_instance_id' => $this->instance->id,
            'direction'            => 'in',
            'phone'                => '971508564493',
            'body'                 => 'Hello, is this available?',
            'message_id'           => 'WAMID-1',
            'tenant_id'            => $this->tenant->id,
        ]);

        $contact = Contact::withoutGlobalScopes()->where('phone', '971508564493')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Bhaktar Travel', $contact->name);
    }

    public function test_redelivered_event_is_recorded_once(): void
    {
        // Same WhatsApp message re-delivered under DIFFERENT event ids (a gateway
        // retry after a timeout mints a new delivery) — the message dedup must
        // catch what the event-id claim cannot.
        $this->deliver($this->receivedPayload('evt-a', 'WAMID-DUP', 'once'))->assertOk();
        $this->deliver($this->receivedPayload('evt-b', 'WAMID-DUP', 'once'))->assertOk();

        $this->assertSame(1, Message::withoutGlobalScopes()->where('message_id', 'WAMID-DUP')->count());
    }

    public function test_stop_reply_opts_the_contact_out_on_baileys_devices(): void
    {
        $this->deliver($this->receivedPayload('evt-stop', 'WAMID-STOP', 'STOP'))->assertOk();

        $contact = Contact::withoutGlobalScopes()->where('phone', '971508564493')->first();
        $this->assertTrue((bool) $contact->opted_out, 'A STOP reply on a Baileys device must opt the contact out.');

        $this->assertSame(1, Suppression::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('phone', '971508564493')
            ->count());
    }

    public function test_inbound_media_is_stored_with_its_kind(): void
    {
        $payload = $this->receivedPayload('evt-img', 'WAMID-IMG', 'the receipt');
        $payload['data']['kind'] = 'image';

        $this->deliver($payload)->assertOk();

        $this->assertDatabaseHas('messages', [
            'message_id' => 'WAMID-IMG',
            'direction'  => 'in',
            'type'       => 'image',
            'body'       => 'the receipt',
        ]);
    }

    public function test_delivery_receipts_update_the_chat_rows_status_without_downgrading(): void
    {
        $message = Message::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'whatsapp_instance_id' => $this->instance->id,
            'direction' => 'out', 'phone' => '971508564493', 'type' => 'text',
            'body' => 'sent earlier', 'status' => 'sent', 'message_id' => 'GW-OUT-1',
        ]);

        $this->deliver([
            'event_id'    => 'evt-d1',
            'event_type'  => 'message.delivered',
            'instance_id' => 'bl-dev-1',
            'data'        => ['gateway_message_id' => 'GW-OUT-1'],
        ])->assertOk();

        $this->assertSame('delivered', $message->fresh()->status);

        $this->deliver([
            'event_id'    => 'evt-d2',
            'event_type'  => 'message.read',
            'instance_id' => 'bl-dev-1',
            'data'        => ['gateway_message_id' => 'GW-OUT-1'],
        ])->assertOk();

        $this->assertSame('read', $message->fresh()->status);

        // A late 'delivered' receipt must never downgrade an already-read row.
        $this->deliver([
            'event_id'    => 'evt-d3',
            'event_type'  => 'message.delivered',
            'instance_id' => 'bl-dev-1',
            'data'        => ['gateway_message_id' => 'GW-OUT-1'],
        ])->assertOk();

        $this->assertSame('read', $message->fresh()->status);
    }

    public function test_unsigned_delivery_is_rejected_and_stores_nothing(): void
    {
        $raw = json_encode($this->receivedPayload('evt-x', 'WAMID-X', 'spoofed'));

        $this->call('POST', route('webhooks.baileys'), [], [], [], [
            'HTTP_X_EAGLETO_TIMESTAMP' => (string) time(),
            'HTTP_X_EAGLETO_SIGNATURE' => 'not-a-real-signature',
            'HTTP_X_EAGLETO_EVENT_ID'  => 'evt-x',
            'CONTENT_TYPE'             => 'application/json',
        ], $raw)->assertStatus(401);

        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }

    public function test_inbound_reply_satisfies_contact_graph_protection(): void
    {
        // The bulk safety feature reads direction='in' rows — the exact rows the
        // old Baileys endpoint never wrote. Prove the wiring end-to-end.
        $this->deliver($this->receivedPayload('evt-cg', 'WAMID-CG', 'interested!'))->assertOk();

        $contact = Contact::withoutGlobalScopes()->where('phone', '971508564493')->first();

        $recent = Message::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->where('direction', 'in')
            ->where('created_at', '>=', now()->subHours(48))
            ->exists();

        $this->assertTrue($recent, 'The stored inbound row is what contact-graph protection checks.');
    }
}
