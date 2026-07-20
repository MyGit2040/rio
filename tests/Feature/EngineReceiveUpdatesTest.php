<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsappInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EngineReceiveUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeGateway();

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-updates']);
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Owner', 'email' => 'updates@x.dev',
            'role' => 'owner', 'password' => Hash::make('secret'),
        ]);
        $this->actingAs($user);
    }

    public function test_without_a_linked_number_it_asks_for_a_device_and_calls_no_gateway(): void
    {
        $res = $this->postJson(route('settings.sync-engine-updates'));

        $res->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertStringContainsString('add a device first', $res->json('message'));

        // The endpoint bails out before touching the gateway.
        Http::assertNothingSent();
    }

    public function test_it_registers_the_webhook_on_the_devices_own_gateway_session(): void
    {
        $device = WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Device 1',
            'instance_name' => 'inst-1', 'token' => 'tok-1', 'status' => 'open',
        ]);

        $res = $this->postJson(route('settings.sync-engine-updates'));

        $res->assertOk()->assertJson(['ok' => true]);
        $this->assertStringContainsString('Webhook updates enabled on 1 device', $res->json('message'));

        // Registration goes to the OpenWA session webhook endpoint, and the URL
        // it registers is this app's own inbound route.
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/sessions/'.$this->gatewaySessionId($device->instance_name).'/webhooks')
            && str_contains((string) $r['url'], '/webhooks/openwa'));
    }

    public function test_it_never_calls_a_legacy_per_device_webhook_endpoint(): void
    {
        WhatsappInstance::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Device 1',
            'instance_name' => 'inst-1', 'token' => 'tok-1', 'status' => 'open',
        ]);

        $this->postJson(route('settings.sync-engine-updates'))->assertOk();

        // The retired Evolution/webjs webhook paths must never be requested.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/webhook/set/')
            || str_contains($r->url(), '/instance/'));
    }
}
