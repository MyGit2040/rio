<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Tenant;
use App\Models\WhatsappInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * devices:sync-status — the app's device status must follow the gateway's REAL
 * session state, so a banned/logged-out number leaves campaign rotation even
 * though it never produces a send failure (a dead session that swallows
 * messages never trips the 3-failures health guard).
 */
class DeviceStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private WhatsappInstance $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-devicesync']);

        $this->device = WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Sales line',
            'instance_name' => 'sync-dev', 'status' => 'open',
        ]);
    }

    /** Point the faked gateway's session-detail endpoint at a fixed status. */
    private function gatewayReports(string $status): void
    {
        $this->fakeGateway(function ($request) use ($status) {
            if ($request->method() === 'GET' && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/sessions/sess-sync-dev')) {
                return Http::response(['status' => $status], 200);
            }

            return null;
        });
    }

    public function test_banned_session_is_disconnected_after_two_sightings_and_alerts(): void
    {
        $this->gatewayReports('DISCONNECTED');

        // First sighting: strike only — a session mid-restart must not flap.
        $this->artisan('devices:sync-status')->assertExitCode(0);
        $this->assertSame('open', $this->device->fresh()->status);
        $this->assertSame(0, Alert::withoutGlobalScopes()->count());

        // Second consecutive sighting: the number leaves rotation, loudly.
        $this->artisan('devices:sync-status')->assertExitCode(0);
        $this->assertSame('close', $this->device->fresh()->status);
        $this->assertDatabaseHas('alerts', ['tenant_id' => $this->tenant->id, 'level' => 'error']);
    }

    public function test_connected_session_stays_open_with_no_alert(): void
    {
        $this->gatewayReports('CONNECTED');

        $this->artisan('devices:sync-status')->assertExitCode(0);
        $this->artisan('devices:sync-status')->assertExitCode(0);

        $this->assertSame('open', $this->device->fresh()->status);
        $this->assertSame(0, Alert::withoutGlobalScopes()->count());
    }

    public function test_stale_close_is_healed_when_the_gateway_says_connected(): void
    {
        $this->device->update(['status' => 'close']);
        $this->gatewayReports('CONNECTED');

        $this->artisan('devices:sync-status')->assertExitCode(0);

        $this->assertSame('open', $this->device->fresh()->status);
    }

    public function test_unreachable_gateway_gives_no_verdict(): void
    {
        // Whole gateway down (directory listing included): the command must skip,
        // never disconnect a number on silence.
        config([
            'whatsapp.base_url' => 'http://gateway.test/api',
            'whatsapp.api_key' => 'k', 'whatsapp.session_id' => 'default',
        ]);
        Http::fake(fn () => Http::response('down', 500));

        $this->artisan('devices:sync-status')->assertExitCode(0);
        $this->artisan('devices:sync-status')->assertExitCode(0);

        $this->assertSame('open', $this->device->fresh()->status);
        $this->assertSame(0, Alert::withoutGlobalScopes()->count());
    }

    public function test_paused_and_connecting_devices_are_never_touched(): void
    {
        // 'paused' = deliberate health protection (manual lift); 'connecting' =
        // mid QR-link flow. Neither may be flipped, even if the gateway answers.
        $paused = WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Protected',
            'instance_name' => 'sync-paused', 'status' => 'paused',
        ]);
        $connecting = WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Linking',
            'instance_name' => 'sync-linking', 'status' => 'connecting',
        ]);
        $this->gatewayReports('CONNECTED');

        $this->artisan('devices:sync-status')->assertExitCode(0);

        $this->assertSame('paused', $paused->fresh()->status);
        $this->assertSame('connecting', $connecting->fresh()->status);
    }
}
