<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CampaignRecipientReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Campaign $campaign;

    private WhatsappInstance $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-report']);
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Owner', 'email' => 'report@x.dev',
            'role' => 'owner', 'password' => Hash::make('secret'),
        ]);
        $this->actingAs($user);

        $this->device = WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Device 1',
            'instance_name' => 'inst-1', 'token' => 'tok-1', 'status' => 'open',
        ]);

        $this->campaign = Campaign::create([
            'tenant_id' => $this->tenant->id, 'name' => 'T3', 'type' => 'text',
            'body' => 'Hi {{name}}', 'variants' => ['Variant one copy', 'Variant two copy'],
            'status' => 'completed', 'min_delay' => 10, 'max_delay' => 30, 'total' => 4,
        ]);

        // 2 sent, 1 delivered, 1 failed — across variants + one device.
        $rows = [
            ['status' => 'sent', 'variant_index' => 0, 'name' => 'Alpha', 'phone' => '971500000001'],
            ['status' => 'sent', 'variant_index' => 1, 'name' => 'Bravo', 'phone' => '971500000002'],
            ['status' => 'delivered', 'variant_index' => 2, 'name' => 'Charlie', 'phone' => '971500000003'],
            ['status' => 'failed', 'variant_index' => 0, 'name' => 'Delta', 'phone' => '971500000004'],
        ];

        foreach ($rows as $r) {
            $contact = Contact::create([
                'tenant_id' => $this->tenant->id, 'name' => $r['name'], 'phone' => $r['phone'], 'opted_out' => false,
            ]);
            CampaignRecipient::create([
                'tenant_id' => $this->tenant->id, 'campaign_id' => $this->campaign->id,
                'whatsapp_instance_id' => $this->device->id, 'contact_id' => $contact->id,
                'phone' => $r['phone'], 'status' => $r['status'], 'variant_index' => $r['variant_index'],
                'sent_at' => $r['status'] === 'failed' ? null : now(),
            ]);
        }
    }

    public function test_report_renders_with_dashboard_and_all_recipients(): void
    {
        $res = $this->get(route('campaigns.show', $this->campaign));

        $res->assertOk();
        $res->assertSee('Dashboard');
        $res->assertSee('Delivery rate');
        // Variant column labels present.
        $res->assertSee('Main');
        $res->assertSee('Variant 1');
        $res->assertSee('Alpha');
        $res->assertSee('Delta');
        // Showing 4 of 4.
        $res->assertSee('of 4');
    }

    public function test_status_filter_narrows_the_list(): void
    {
        $res = $this->get(route('campaigns.show', [$this->campaign, 'status' => 'sent']));

        $res->assertOk();
        $res->assertSee('Alpha');   // sent
        $res->assertSee('Bravo');   // sent
        $res->assertDontSee('Delta'); // failed — filtered out
        $res->assertSee('of 2');
    }

    public function test_search_filter_matches_name(): void
    {
        $res = $this->get(route('campaigns.show', [$this->campaign, 'q' => 'Charlie']));

        $res->assertOk();
        $res->assertSee('Charlie');
        $res->assertDontSee('Alpha');
    }

    public function test_variant_filter_narrows_the_list(): void
    {
        $res = $this->get(route('campaigns.show', [$this->campaign, 'variant' => 0]));

        $res->assertOk();
        $res->assertSee('Alpha');   // variant 0
        $res->assertSee('Delta');   // variant 0
        $res->assertDontSee('Bravo'); // variant 1
    }

    public function test_per_page_all_shows_everyone_on_one_page(): void
    {
        $res = $this->get(route('campaigns.show', [$this->campaign, 'per_page' => 'all']));

        $res->assertOk();
        $res->assertSee('Alpha');
        $res->assertSee('Bravo');
        $res->assertSee('Charlie');
        $res->assertSee('Delta');
    }
}
