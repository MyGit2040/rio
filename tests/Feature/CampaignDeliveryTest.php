<?php

namespace Tests\Feature;

use App\Jobs\SendCampaignMessage;
use App\Models\Contact;
use App\Models\Template;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappInstance;
use App\Services\CampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CampaignDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private WhatsappInstance $device;

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
            'name' => 'Acme', 'slug' => 'acme-del',
            'openwa_base_url' => 'https://openwa.test/api', 'openwa_api_key' => 'k', 'openwa_session_id' => 'inst-1',
        ]);
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Owner', 'email' => 'del@x.dev',
            'role' => 'owner', 'password' => Hash::make('secret'),
        ]);
        $this->actingAs($user);

        // A connected OpenWA device has status 'open'.
        $this->device = WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Device 1',
            'instance_name' => 'inst-1', 'token' => 'tok-1', 'status' => 'open',
        ]);

        Contact::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Sara',
            'phone' => '971500000001', 'opted_out' => false, 'wa_status' => 'valid',
        ]);
    }

    public function test_single_text_campaign_delivers_and_marks_sent(): void
    {
        $campaign = app(CampaignService::class)->create([
            'name' => 'Text Blast', 'device_ids' => [$this->device->id], 'body' => 'Hi {{name}}',
            'min_delay' => 1, 'max_delay' => 2, 'schedule' => 'now', 'audience' => 'all',
        ]);

        $recipient = $campaign->recipients()->firstOrFail();
        (new SendCampaignMessage($recipient->id))->handle();

        $this->assertSame('sent', $recipient->fresh()->status);
        $this->assertSame(1, (int) $campaign->fresh()->sent);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/sessions/session-uuid-1/messages/send-text'));
    }

    public function test_poll_campaign_delivers_poll(): void
    {
        $template = Template::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Poll', 'type' => 'poll',
            'body' => 'Vote {{name}}',
            'poll' => ['question' => 'Best day?', 'options' => ['Mon', 'Tue'], 'multiple' => false],
        ]);

        $campaign = app(CampaignService::class)->create([
            'name' => 'Poll Blast', 'device_ids' => [$this->device->id], 'template_id' => $template->id,
            'min_delay' => 1, 'max_delay' => 2, 'schedule' => 'now', 'audience' => 'all',
        ]);

        $recipient = $campaign->recipients()->firstOrFail();
        (new SendCampaignMessage($recipient->id))->handle();

        $this->assertSame('sent', $recipient->fresh()->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/sessions/session-uuid-1/messages/send-poll'));
    }

    public function test_scheduled_campaign_is_marked_scheduled(): void
    {
        $campaign = app(CampaignService::class)->create([
            'name' => 'Later Blast', 'device_ids' => [$this->device->id], 'body' => 'Hi',
            'min_delay' => 1, 'max_delay' => 2,
            'schedule' => 'later', 'scheduled_at' => now()->addDay()->toDateTimeString(),
            'audience' => 'all',
        ]);

        $this->assertSame('scheduled', $campaign->status);
        $this->assertNotNull($campaign->scheduled_at);
        $this->assertSame(1, $campaign->recipients()->count());
        // A future campaign must not have fired yet.
        Http::assertNothingSent();
    }
}
