<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Tenant;
use App\Models\WhatsappInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DedupMessagesCommandTest extends TestCase
{
    use RefreshDatabase;

    private WhatsappInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-dedupcmd']);
        $this->instance = WhatsappInstance::create([
            'tenant_id' => $tenant->id, 'name' => 'Device 1',
            'instance_name' => 'inst-1', 'token' => 'tok', 'status' => 'open',
        ]);
    }

    private function makeMessage(string $messageId): Message
    {
        return Message::withoutGlobalScopes()->create([
            'tenant_id'            => $this->instance->tenant_id,
            'whatsapp_instance_id' => $this->instance->id,
            'direction'            => 'in',
            'phone'                => '971508564493',
            'type'                 => 'text',
            'body'                 => 'Thank you for contacting',
            'status'               => 'received',
            'message_id'           => $messageId,
        ]);
    }

    public function test_removes_duplicates_keeping_earliest_and_leaves_others(): void
    {
        // Same message recorded 4 times (the every-minute bug).
        $first = $this->makeMessage('MSG-DUP');
        $this->makeMessage('MSG-DUP');
        $this->makeMessage('MSG-DUP');
        $this->makeMessage('MSG-DUP');
        // A distinct message + a null-id message must be untouched.
        $this->makeMessage('MSG-UNIQUE');
        Message::withoutGlobalScopes()->create([
            'tenant_id' => $this->instance->tenant_id, 'whatsapp_instance_id' => $this->instance->id,
            'direction' => 'in', 'phone' => '971500000000', 'type' => 'text', 'body' => 'no id',
            'status' => 'received', 'message_id' => null,
        ]);

        $this->assertSame(6, Message::withoutGlobalScopes()->count());

        $this->artisan('messages:dedup')
            ->expectsOutputToContain('3 extra row(s) to remove.')
            ->assertSuccessful();

        // One MSG-DUP left (the earliest), MSG-UNIQUE + null-id survive.
        $this->assertSame(1, Message::withoutGlobalScopes()->where('message_id', 'MSG-DUP')->count());
        $this->assertNotNull(Message::withoutGlobalScopes()->find($first->id), 'Earliest copy is kept.');
        $this->assertSame(1, Message::withoutGlobalScopes()->where('message_id', 'MSG-UNIQUE')->count());
        $this->assertSame(1, Message::withoutGlobalScopes()->whereNull('message_id')->count());
        $this->assertSame(3, Message::withoutGlobalScopes()->count());
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $this->makeMessage('MSG-DUP');
        $this->makeMessage('MSG-DUP');

        $this->artisan('messages:dedup --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(2, Message::withoutGlobalScopes()->count());
    }
}
