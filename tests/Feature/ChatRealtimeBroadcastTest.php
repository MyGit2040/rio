<?php

namespace Tests\Feature;

use App\Events\ChatMessageStatusUpdated;
use App\Events\ChatMessageStored;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The Chats workspace's live push: every stored message and every tick change
 * must broadcast on the workspace's PRIVATE channel — and only that
 * workspace's own users may subscribe to it.
 */
class ChatRealtimeBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec-rt';

    private Tenant $tenant;

    private User $user;

    private WhatsappInstance $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeGateway();
        config(['baileys.webhook_secret' => self::WEBHOOK_SECRET]);

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-rt']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->device = WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Line 1',
            'instance_name' => 'rt-dev-1', 'token' => 'tok', 'status' => 'open',
        ]);
    }

    private function signedBaileys(array $payload): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($payload);
        $timestamp = (string) time();

        return $this->call('POST', route('webhooks.baileys'), [], [], [], [
            'HTTP_X_EAGLETO_TIMESTAMP' => $timestamp,
            'HTTP_X_EAGLETO_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$raw, self::WEBHOOK_SECRET),
            'HTTP_X_EAGLETO_EVENT_ID'  => $payload['event_id'],
            'CONTENT_TYPE'             => 'application/json',
        ], $raw);
    }

    public function test_chat_send_broadcasts_on_the_workspace_channel(): void
    {
        Event::fake([ChatMessageStored::class]);

        $this->actingAs($this->user)
            ->postJson(route('chats.send', $this->device), ['phone' => '971500000009', 'body' => 'live!'])
            ->assertOk();

        Event::assertDispatched(ChatMessageStored::class, function (ChatMessageStored $e) {
            return $e->tenantId === $this->tenant->id
                && $e->deviceId === $this->device->id
                && $e->phone === '971500000009'
                && $e->message['direction'] === 'out'
                && $e->message['body'] === 'live!'
                && $e->broadcastOn()->name === 'private-chat.'.$this->tenant->id
                && $e->broadcastAs() === 'chat.message';
        });
    }

    public function test_baileys_inbound_broadcasts_the_stored_message(): void
    {
        Event::fake([ChatMessageStored::class]);

        $this->signedBaileys([
            'event_id'    => 'rt-evt-1',
            'event_type'  => 'message.received',
            'instance_id' => 'rt-dev-1',
            'data'        => [
                'whatsapp_message_id' => 'RT-WAMID-1',
                'chat_jid'            => '971508564493@s.whatsapp.net',
                'sender_jid'          => '971508564493@s.whatsapp.net',
                'phone'               => '971508564493',
                'push_name'           => 'Bhaktar Travel',
                'kind'                => 'text',
                'text'                => 'got your message',
                'timestamp'           => now()->toIso8601String(),
            ],
        ])->assertOk();

        Event::assertDispatched(ChatMessageStored::class, function (ChatMessageStored $e) {
            return $e->tenantId === $this->tenant->id
                && $e->phone === '971508564493'
                && $e->contactName === 'Bhaktar Travel'
                && $e->message['direction'] === 'in'
                && $e->message['body'] === 'got your message';
        });
    }

    public function test_delivery_receipt_broadcasts_the_tick_change(): void
    {
        $message = Message::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'whatsapp_instance_id' => $this->device->id,
            'direction' => 'out', 'phone' => '971508564493', 'type' => 'text',
            'body' => 'sent earlier', 'status' => 'sent', 'message_id' => 'RT-GW-1',
        ]);

        Event::fake([ChatMessageStatusUpdated::class]);

        $this->signedBaileys([
            'event_id'    => 'rt-evt-2',
            'event_type'  => 'message.delivered',
            'instance_id' => 'rt-dev-1',
            'data'        => ['gateway_message_id' => 'RT-GW-1'],
        ])->assertOk();

        $this->assertSame('delivered', $message->fresh()->status);

        Event::assertDispatched(ChatMessageStatusUpdated::class, function (ChatMessageStatusUpdated $e) use ($message) {
            return $e->tenantId === $this->tenant->id
                && $e->deviceId === $this->device->id
                && $e->messageIds === [$message->id]
                && $e->status === 'delivered'
                && $e->broadcastAs() === 'chat.status';
        });
    }

    public function test_broadcast_failure_never_breaks_the_send(): void
    {
        // A configured-but-unreachable socket server: the driver throws inside
        // the dispatch, ChatRealtime swallows + logs, the send still succeeds.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb',
                'key' => 'k', 'secret' => 's', 'app_id' => '1',
                'options' => ['host' => '127.0.0.1', 'port' => 59999, 'scheme' => 'http', 'useTLS' => false],
            ],
        ]);

        $this->actingAs($this->user)
            ->postJson(route('chats.send', $this->device), ['phone' => '971500000009', 'body' => 'still works'])
            ->assertOk();

        $this->assertDatabaseHas('messages', ['body' => 'still works', 'direction' => 'out']);
    }

    public function test_workspace_channel_rejects_users_of_another_tenant(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb' => [
                'driver' => 'reverb',
                'key' => 'k', 'secret' => 's', 'app_id' => '1',
                'options' => ['host' => '127.0.0.1', 'port' => 8085, 'scheme' => 'http', 'useTLS' => false],
            ],
        ]);

        // Channel callbacks register on the DEFAULT broadcaster at boot, which
        // under phpunit is the null driver. Re-require the channels file so the
        // callbacks land on the reverb broadcaster — exactly what a real boot
        // does when .env sets BROADCAST_CONNECTION=reverb before the app starts.
        require base_path('routes/channels.php');

        $auth = ['socket_id' => '1234.5678', 'channel_name' => 'private-chat.'.$this->tenant->id];

        // A user of the SAME workspace gets a signed subscription.
        $this->actingAs($this->user)->postJson('/broadcasting/auth', $auth)->assertOk();

        // A user of ANOTHER workspace is refused.
        $other = Tenant::create(['name' => 'Rival', 'slug' => 'rival-rt']);
        $otherUser = User::factory()->create(['tenant_id' => $other->id]);

        $this->actingAs($otherUser)->postJson('/broadcasting/auth', $auth)->assertForbidden();
    }
}
