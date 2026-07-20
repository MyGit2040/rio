<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappInstance;
use App\Services\CampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CampaignLargeAudienceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression: a big audience must not blow SQLite's 999 bound-variable cap.
     * The recipient rows are inserted in batches; one giant insert would fail on
     * the live SQLite build (each row binds 9 columns → 130 rows = 1170 vars).
     */
    public function test_large_audience_inserts_in_batches_under_sqlite_variable_cap(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-la']);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Owner', 'email' => 'la@x.dev',
            'role' => 'owner', 'password' => Hash::make('secret'),
        ]);
        $this->actingAs($user);

        $device = WhatsappInstance::create([
            'tenant_id' => $tenant->id, 'name' => 'Device 1',
            'instance_name' => 'inst-1', 'token' => 'tok-1', 'status' => 'connected',
        ]);

        // 130 reachable contacts → a single insert would bind 130 * 9 = 1170 vars.
        $rows = [];
        for ($i = 1; $i <= 130; $i++) {
            $rows[] = [
                'tenant_id' => $tenant->id, 'name' => "Contact $i",
                'phone' => '97150'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'opted_out' => false,
                // Campaigns only build recipients for WhatsApp-verified contacts.
                'wa_status' => 'valid',
            ];
        }
        Contact::insert($rows);

        DB::enableQueryLog();
        $campaign = app(CampaignService::class)->create([
            'name' => 'Big Blast', 'device_ids' => [$device->id], 'body' => 'Hi {{name}}',
            'min_delay' => 1, 'max_delay' => 2, 'schedule' => 'now', 'audience' => 'all',
        ]);

        $this->assertSame(130, (int) $campaign->total);
        $this->assertSame(130, $campaign->recipients()->count());

        // Every recipient insert must stay under SQLite's 999-variable statement cap.
        $inserts = array_filter(
            DB::getQueryLog(),
            fn ($q) => str_contains($q['query'], 'insert into "campaign_recipients"')
        );
        $this->assertNotEmpty($inserts);
        foreach ($inserts as $q) {
            $this->assertLessThanOrEqual(
                999,
                count($q['bindings']),
                'A recipient insert exceeded the SQLite variable cap.'
            );
        }
    }
}
