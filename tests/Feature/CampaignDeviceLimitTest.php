<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappInstance;
use App\Services\CampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CampaignDeviceLimitTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private WhatsappInstance $deviceA;

    private WhatsappInstance $deviceB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-cap']);
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Owner', 'email' => 'cap@x.dev',
            'role' => 'owner', 'password' => Hash::make('secret'),
        ]);
        $this->actingAs($user);

        $this->deviceA = WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Device A',
            'instance_name' => 'inst-a', 'token' => 'tok-a', 'status' => 'open',
        ]);
        $this->deviceB = WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Device B',
            'instance_name' => 'inst-b', 'token' => 'tok-b', 'status' => 'open',
        ]);

        // 10 reachable contacts.
        foreach (range(1, 10) as $i) {
            Contact::create([
                'tenant_id' => $this->tenant->id, 'name' => "C{$i}",
                'phone' => '9715000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'opted_out' => false, 'wa_status' => 'valid',
            ]);
        }
    }

    private function baseData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Cap Test', 'body' => 'Hi', 'audience' => 'all',
            'min_delay' => 1, 'max_delay' => 2, 'schedule' => 'later',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
        ], $overrides);
    }

    public function test_device_cap_limits_messages_per_number(): void
    {
        // A capped at 3, B unlimited → A gets exactly 3, B gets the other 7.
        $campaign = app(CampaignService::class)->create($this->baseData([
            'device_ids'    => [$this->deviceA->id, $this->deviceB->id],
            'device_limits' => [$this->deviceA->id => 3, $this->deviceB->id => 0],
        ]));

        $this->assertSame(3, $campaign->recipients()->where('whatsapp_instance_id', $this->deviceA->id)->count());
        $this->assertSame(7, $campaign->recipients()->where('whatsapp_instance_id', $this->deviceB->id)->count());
        $this->assertSame(10, $campaign->total);
        $this->assertSame(0, $campaign->skippedForCapacity);
        $this->assertSame([$this->deviceA->id => 3], $campaign->device_limits);
    }

    public function test_hard_caps_leave_out_extra_contacts(): void
    {
        // Both capped, total capacity 4 < 10 audience → only 4 built, 6 skipped.
        $campaign = app(CampaignService::class)->create($this->baseData([
            'device_ids'    => [$this->deviceA->id, $this->deviceB->id],
            'device_limits' => [$this->deviceA->id => 2, $this->deviceB->id => 2],
        ]));

        $this->assertSame(2, $campaign->recipients()->where('whatsapp_instance_id', $this->deviceA->id)->count());
        $this->assertSame(2, $campaign->recipients()->where('whatsapp_instance_id', $this->deviceB->id)->count());
        $this->assertSame(4, $campaign->total);
        $this->assertSame(6, $campaign->skippedForCapacity);
    }

    public function test_no_caps_keeps_everyone(): void
    {
        $campaign = app(CampaignService::class)->create($this->baseData([
            'device_ids' => [$this->deviceA->id, $this->deviceB->id],
        ]));

        $this->assertSame(10, $campaign->total);
        $this->assertSame(0, $campaign->skippedForCapacity);
        $this->assertNull($campaign->device_limits);
    }

    public function test_caps_hold_through_launch_when_all_connected(): void
    {
        Bus::fake();
        $svc = app(CampaignService::class);
        $campaign = $svc->create($this->baseData([
            'device_ids'    => [$this->deviceA->id, $this->deviceB->id],
            'device_limits' => [$this->deviceA->id => 3, $this->deviceB->id => 0],
        ]));

        $svc->launch($campaign);   // runs reassignPendingToConnected

        $this->assertSame(3, $campaign->recipients()->where('whatsapp_instance_id', $this->deviceA->id)->count());
        $this->assertSame(7, $campaign->recipients()->where('whatsapp_instance_id', $this->deviceB->id)->count());
    }

    public function test_disconnected_capped_number_moves_its_load_to_a_connected_one(): void
    {
        Bus::fake();
        // A is capped but DISCONNECTED; B is unlimited + connected.
        $this->deviceA->update(['status' => 'close']);

        $svc = app(CampaignService::class);
        $campaign = $svc->create($this->baseData([
            'device_ids'    => [$this->deviceA->id, $this->deviceB->id],
            'device_limits' => [$this->deviceA->id => 3, $this->deviceB->id => 0],
        ]));

        $svc->launch($campaign);

        // A's stranded recipients move to the connected B — nothing lost.
        $this->assertSame(0, $campaign->recipients()->where('whatsapp_instance_id', $this->deviceA->id)->count());
        $this->assertSame(10, $campaign->recipients()->where('whatsapp_instance_id', $this->deviceB->id)->count());
    }

    public function test_store_flashes_warning_when_contacts_left_out(): void
    {
        $res = $this->post(route('campaigns.store'), $this->baseData([
            'device_ids'    => [$this->deviceA->id, $this->deviceB->id],
            'device_limits' => [$this->deviceA->id => 1, $this->deviceB->id => 1],
        ]));

        $campaign = Campaign::latest('id')->first();
        $res->assertRedirect(route('campaigns.show', $campaign));
        $res->assertSessionHas('success', fn ($m) => str_contains($m, 'left out'));
        $this->assertSame(2, $campaign->total);
    }
}
