<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappInstance;
use App\Services\CampaignService;
use App\Models\Campaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CampaignAssignDevicesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private WhatsappInstance $oldDevice;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-assign']);
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Owner', 'email' => 'assign@x.dev',
            'role' => 'owner', 'password' => Hash::make('secret'),
        ]);
        $this->actingAs($user);

        $this->oldDevice = WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Old number',
            'instance_name' => 'inst-old', 'token' => 'tok-old', 'status' => 'close',
        ]);

        foreach (range(1, 6) as $i) {
            Contact::create([
                'tenant_id' => $this->tenant->id, 'name' => "C{$i}",
                'phone' => '97151000000'.$i, 'opted_out' => false,
            ]);
        }

        $this->campaign = app(CampaignService::class)->create([
            'name' => 'Assign Test', 'body' => 'Hi', 'audience' => 'all',
            'min_delay' => 1, 'max_delay' => 2, 'schedule' => 'later',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'device_ids' => [$this->oldDevice->id],
        ]);

        // The only number disconnected mid-send → the campaign paused.
        $this->campaign->update(['status' => 'paused']);
    }

    public function test_assigning_new_devices_lets_a_paused_campaign_resume_on_them(): void
    {
        Bus::fake();

        $new = collect(range(1, 3))->map(fn ($i) => WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => "New {$i}",
            'instance_name' => "inst-new-{$i}", 'token' => "tok-new-{$i}", 'status' => 'open',
        ]));

        // The paused show page offers the assign form with every number listed.
        $page = $this->get(route('campaigns.show', $this->campaign));
        $page->assertOk()
            ->assertSee('Assign / change sending numbers')
            ->assertSee('New 1')
            ->assertSee(route('campaigns.devices', $this->campaign));

        $this->post(route('campaigns.devices', $this->campaign), [
            'device_ids' => $new->pluck('id')->all(),
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'Resume'));

        $this->campaign->refresh();
        $this->assertEqualsCanonicalizing($new->pluck('id')->all(), $this->campaign->device_ids);
        $this->assertSame((int) $new->first()->id, (int) $this->campaign->whatsapp_instance_id);

        // Resume: every pending recipient moves off the dead number onto the new ones.
        $this->post(route('campaigns.launch', $this->campaign))->assertSessionHas('success');

        $this->assertSame(0, $this->campaign->recipients()->where('whatsapp_instance_id', $this->oldDevice->id)->count());
        $this->assertSame(6, $this->campaign->recipients()->whereIn('whatsapp_instance_id', $new->pluck('id'))->count());
        $this->assertSame('sending', $this->campaign->fresh()->status);
    }

    public function test_caps_belonging_to_removed_numbers_are_dropped(): void
    {
        $this->campaign->update(['device_limits' => [$this->oldDevice->id => 50]]);

        $new = WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => 'New solo',
            'instance_name' => 'inst-solo', 'token' => 'tok-solo', 'status' => 'open',
        ]);

        $this->post(route('campaigns.devices', $this->campaign), ['device_ids' => [$new->id]])
            ->assertSessionHas('success');

        $this->assertNull($this->campaign->fresh()->device_limits);
    }

    public function test_a_foreign_tenants_device_is_rejected(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-assign']);
        $foreign = WhatsappInstance::create([
            'tenant_id' => $other->id, 'name' => 'Foreign',
            'instance_name' => 'inst-foreign', 'token' => 'tok-foreign', 'status' => 'open',
        ]);

        $this->post(route('campaigns.devices', $this->campaign), ['device_ids' => [$foreign->id]])
            ->assertSessionHas('error');

        $this->assertEqualsCanonicalizing([$this->oldDevice->id], $this->campaign->fresh()->device_ids);
    }

    public function test_numbers_cannot_change_while_actively_sending(): void
    {
        $this->campaign->update(['status' => 'sending']);

        $new = WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => 'New mid-send',
            'instance_name' => 'inst-mid', 'token' => 'tok-mid', 'status' => 'open',
        ]);

        $this->post(route('campaigns.devices', $this->campaign), ['device_ids' => [$new->id]])
            ->assertSessionHas('error');

        $this->assertEqualsCanonicalizing([$this->oldDevice->id], $this->campaign->fresh()->device_ids);
    }

    public function test_at_least_one_number_is_required(): void
    {
        $this->post(route('campaigns.devices', $this->campaign), ['device_ids' => []])
            ->assertSessionHasErrors('device_ids');

        $this->assertEqualsCanonicalizing([$this->oldDevice->id], $this->campaign->fresh()->device_ids);
    }
}
