<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private WhatsappInstance $deviceA;

    private WhatsappInstance $deviceB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeGateway();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-chat']);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->deviceA = WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Sales line',
            'instance_name' => 'chat-dev-a', 'token' => 'tok', 'status' => 'open',
        ]);
        $this->deviceB = WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Support line',
            'instance_name' => 'chat-dev-b', 'token' => 'tok', 'status' => 'open',
        ]);
    }

    private function seedThread(WhatsappInstance $device, string $phone, string $inBody, string $outBody): Contact
    {
        $contact = Contact::firstOrCreate(['tenant_id' => $this->tenant->id, 'phone' => $phone], ['name' => 'C '.$phone]);

        Message::create([
            'tenant_id' => $this->tenant->id, 'whatsapp_instance_id' => $device->id,
            'contact_id' => $contact->id, 'direction' => 'out', 'phone' => $phone,
            'type' => 'text', 'body' => $outBody, 'status' => 'sent', 'message_id' => 'OUT-'.$device->id.'-'.$phone,
        ]);
        Message::create([
            'tenant_id' => $this->tenant->id, 'whatsapp_instance_id' => $device->id,
            'contact_id' => $contact->id, 'direction' => 'in', 'phone' => $phone,
            'type' => 'text', 'body' => $inBody, 'status' => 'received', 'message_id' => 'IN-'.$device->id.'-'.$phone,
        ]);

        return $contact;
    }

    public function test_workspace_page_loads_with_device_tabs(): void
    {
        $this->actingAs($this->user)
            ->get(route('chats.index'))
            ->assertOk()
            ->assertSee('Sales line')
            ->assertSee('Support line');
    }

    public function test_conversations_are_grouped_per_device(): void
    {
        $this->seedThread($this->deviceA, '971500000001', 'hi A', 'hello from A');
        $this->seedThread($this->deviceB, '971500000002', 'hi B', 'hello from B');

        $res = $this->actingAs($this->user)
            ->getJson(route('chats.conversations', $this->deviceA))
            ->assertOk()
            ->json();

        $phones = array_column($res['conversations'], 'phone');
        $this->assertContains('971500000001', $phones);
        $this->assertNotContains('971500000002', $phones, "Device B's conversation must not appear under device A's tab.");
        $this->assertSame('open', $res['device']['status']);
    }

    public function test_conversation_search_filters_by_name_and_number(): void
    {
        $this->seedThread($this->deviceA, '971500000001', 'hi', 'hello');
        $this->seedThread($this->deviceA, '971555999888', 'yo', 'hey');
        Contact::where('phone', '971555999888')->update(['name' => 'Bhaktar Travel']);

        $byNumber = $this->actingAs($this->user)
            ->getJson(route('chats.conversations', [$this->deviceA, 'q' => '555999']))
            ->assertOk()->json('conversations');
        $this->assertCount(1, $byNumber);
        $this->assertSame('971555999888', $byNumber[0]['phone']);

        $byName = $this->actingAs($this->user)
            ->getJson(route('chats.conversations', [$this->deviceA, 'q' => 'Bhaktar']))
            ->assertOk()->json('conversations');
        $this->assertCount(1, $byName);
        $this->assertSame('971555999888', $byName[0]['phone']);
    }

    public function test_thread_returns_messages_and_incremental_after_id(): void
    {
        $this->seedThread($this->deviceA, '971500000001', 'reply', 'first');

        $full = $this->actingAs($this->user)
            ->getJson(route('chats.thread', [$this->deviceA, 'phone' => '971500000001']))
            ->assertOk()->json();

        $this->assertCount(2, $full['messages']);
        $this->assertSame('C 971500000001', $full['contact']['name']);

        $lastId = end($full['messages'])['id'];

        $newer = Message::create([
            'tenant_id' => $this->tenant->id, 'whatsapp_instance_id' => $this->deviceA->id,
            'contact_id' => $full['contact']['id'], 'direction' => 'in', 'phone' => '971500000001',
            'type' => 'text', 'body' => 'newest', 'status' => 'received', 'message_id' => 'IN-NEW',
        ]);

        $delta = $this->actingAs($this->user)
            ->getJson(route('chats.thread', [$this->deviceA, 'phone' => '971500000001', 'after_id' => $lastId]))
            ->assertOk()->json('messages');

        $this->assertCount(1, $delta);
        $this->assertSame($newer->id, $delta[0]['id']);
        $this->assertSame('newest', $delta[0]['body']);
    }

    public function test_send_delivers_through_the_gateway_and_stores_the_row(): void
    {
        $res = $this->actingAs($this->user)
            ->postJson(route('chats.send', $this->deviceA), [
                'phone' => '+971 50 000 0009',
                'body'  => 'Hello from the workspace',
            ])
            ->assertOk()
            ->json();

        $this->assertTrue($res['ok']);
        $this->assertSame('Hello from the workspace', $res['message']['body']);

        $this->assertDatabaseHas('messages', [
            'whatsapp_instance_id' => $this->deviceA->id,
            'direction'            => 'out',
            'phone'                => '971500000009',
            'body'                 => 'Hello from the workspace',
            'status'               => 'sent',
        ]);

        // A brand-new number becomes a contact.
        $this->assertDatabaseHas('contacts', ['phone' => '971500000009', 'tenant_id' => $this->tenant->id]);
    }

    public function test_send_is_rejected_when_the_device_is_disconnected(): void
    {
        $this->deviceA->update(['status' => 'close']);

        $this->actingAs($this->user)
            ->postJson(route('chats.send', $this->deviceA), ['phone' => '971500000009', 'body' => 'x'])
            ->assertStatus(422);

        $this->assertSame(0, Message::count());
    }

    public function test_send_is_blocked_at_the_plan_monthly_message_limit(): void
    {
        Plan::create(['key' => 'tiny', 'name' => 'Tiny', 'limits' => ['monthly_messages' => 1], 'is_default' => false]);
        $this->tenant->update(['plan' => 'tiny']);

        Message::create([
            'tenant_id' => $this->tenant->id, 'whatsapp_instance_id' => $this->deviceA->id,
            'direction' => 'out', 'phone' => '971500000001', 'type' => 'text',
            'body' => 'used up', 'status' => 'sent', 'message_id' => 'M-USED',
        ]);

        $this->actingAs($this->user)
            ->postJson(route('chats.send', $this->deviceA), ['phone' => '971500000009', 'body' => 'over the cap'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('messages', ['body' => 'over the cap']);
    }

    public function test_media_send_stores_type_and_caption(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('chats.send', $this->deviceA), [
                'phone'      => '971500000009',
                'body'       => 'the brochure',
                'media_url'  => 'https://files.test/brochure.pdf',
                'media_type' => 'document',
                'media_name' => 'brochure.pdf',
            ])
            ->assertOk();

        $this->assertDatabaseHas('messages', [
            'whatsapp_instance_id' => $this->deviceA->id,
            'type'                 => 'document',
            'body'                 => 'the brochure',
            'direction'            => 'out',
        ]);
    }

    public function test_other_tenants_devices_are_invisible(): void
    {
        $other = Tenant::create(['name' => 'Rival', 'slug' => 'rival-chat']);
        $otherUser = User::factory()->create(['tenant_id' => $other->id]);

        $this->actingAs($otherUser)->getJson(route('chats.conversations', $this->deviceA))->assertNotFound();
        $this->actingAs($otherUser)->getJson(route('chats.thread', [$this->deviceA, 'phone' => '971500000001']))->assertNotFound();
        $this->actingAs($otherUser)->postJson(route('chats.send', $this->deviceA), ['phone' => '971500000001', 'body' => 'x'])->assertNotFound();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('chats.index'))->assertRedirect(route('login'));
    }
}
