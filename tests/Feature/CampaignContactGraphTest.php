<?php

namespace Tests\Feature;

use App\Jobs\SendCampaignMessage;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappInstance;
use App\Services\CampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Contact-graph protection (opt-in): a campaign must only reach contacts who
 * already have a two-way thread with the workspace when the setting is on, and
 * must behave exactly as before when it is off.
 */
class CampaignContactGraphTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private WhatsappInstance $device;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'openwa.base_url' => 'https://openwa.test/api',
            'openwa.api_key' => 'k',
            'openwa.session_id' => 'inst-1',
        ]);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/sessions')) {
                return Http::response([['id' => 'session-uuid-1', 'name' => 'inst-1', 'status' => 'ready']], 200);
            }

            return Http::response(['messageId' => 'MSG-1'], 201);
        });

        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme-graph',
            'openwa_base_url' => 'https://openwa.test/api', 'openwa_api_key' => 'k', 'openwa_session_id' => 'inst-1',
        ]);
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Owner', 'email' => 'graph@x.dev',
            'role' => 'owner', 'password' => Hash::make('secret'),
        ]);
        $this->actingAs($user);

        $this->device = WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Device 1',
            'instance_name' => 'inst-1', 'token' => 'tok-1', 'status' => 'open',
        ]);

        $this->contact = Contact::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Sara',
            'phone' => '971500000001', 'opted_out' => false, 'wa_status' => 'valid',
        ]);
    }

    private function launchOneRecipient(): \App\Models\CampaignRecipient
    {
        $campaign = app(CampaignService::class)->create([
            'name' => 'Blast', 'device_ids' => [$this->device->id], 'body' => 'Hi {{name}}',
            'min_delay' => 1, 'max_delay' => 2, 'schedule' => 'now', 'audience' => 'all',
        ]);

        return $campaign->recipients()->firstOrFail();
    }

    public function test_cold_contact_is_skipped_when_protection_is_on(): void
    {
        $this->tenant->update(['settings' => ['bulk_contact_graph' => true]]);

        $recipient = $this->launchOneRecipient();
        (new SendCampaignMessage($recipient->id))->handle();

        $fresh = $recipient->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertStringContainsString('contact-graph', $fresh->error);
        // No message was actually transmitted.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'send-text'));
    }

    public function test_contact_with_a_prior_inbound_reply_is_sent_when_protection_is_on(): void
    {
        $this->tenant->update(['settings' => ['bulk_contact_graph' => true]]);

        // A previous inbound reply = an existing two-way thread.
        Message::create([
            'tenant_id' => $this->tenant->id,
            'whatsapp_instance_id' => $this->device->id,
            'contact_id' => $this->contact->id,
            'direction' => 'in',
            'phone' => $this->contact->phone,
            'type' => 'text',
            'body' => 'Yes please',
            'status' => 'received',
        ]);

        $recipient = $this->launchOneRecipient();
        (new SendCampaignMessage($recipient->id))->handle();

        $this->assertSame('sent', $recipient->fresh()->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'send-text'));
    }

    public function test_contact_whose_only_reply_is_older_than_the_window_is_skipped(): void
    {
        $this->tenant->update(['settings' => ['bulk_contact_graph' => true, 'bulk_contact_graph_hours' => 48]]);

        // An inbound reply from 60 hours ago — outside the rolling 48h window.
        $old = Message::create([
            'tenant_id' => $this->tenant->id,
            'whatsapp_instance_id' => $this->device->id,
            'contact_id' => $this->contact->id,
            'direction' => 'in',
            'phone' => $this->contact->phone,
            'type' => 'text',
            'body' => 'Old reply',
            'status' => 'received',
        ]);
        $old->created_at = now()->subHours(60);
        $old->save();

        $recipient = $this->launchOneRecipient();
        (new SendCampaignMessage($recipient->id))->handle();

        $this->assertSame('failed', $recipient->fresh()->status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'send-text'));
    }

    public function test_cold_contact_is_sent_when_protection_is_off(): void
    {
        // Default (setting absent/false): cold outreach is unaffected.
        $recipient = $this->launchOneRecipient();
        (new SendCampaignMessage($recipient->id))->handle();

        $this->assertSame('sent', $recipient->fresh()->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'send-text'));
    }
}
