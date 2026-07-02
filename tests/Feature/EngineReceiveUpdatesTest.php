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

        config(['evolution.base_url' => 'https://evo.test', 'evolution.api_key' => 'k']);

        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme-updates',
            'evolution_base_url' => 'https://evo.test', 'evolution_api_key' => 'k',
        ]);
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Owner', 'email' => 'updates@x.dev',
            'role' => 'owner', 'password' => Hash::make('secret'),
        ]);
        $this->actingAs($user);
    }

    public function test_button_registers_webhook_on_every_linked_number(): void
    {
        Http::fake(['*/webhook/set/*' => Http::response(['webhook' => ['enabled' => true]], 200)]);

        foreach (['inst-1', 'inst-2'] as $i => $name) {
            WhatsappInstance::create([
                'tenant_id' => $this->tenant->id, 'name' => 'Device '.($i + 1),
                'instance_name' => $name, 'token' => 'tok', 'status' => 'open',
            ]);
        }

        $res = $this->postJson(route('settings.sync-engine-updates'));

        $res->assertOk()->assertJson(['ok' => true]);
        $res->assertJsonFragment(['ok' => true]);
        $this->assertStringContainsString('2 numbers', $res->json('message'));

        // Webhook was set on both instances with the app's webhook URL + events.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/webhook/set/inst-1')
            && $r['webhook']['enabled'] === true
            && str_contains($r['webhook']['url'], '/webhooks/evolution'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/webhook/set/inst-2'));
    }

    public function test_button_reports_when_no_numbers_linked(): void
    {
        $res = $this->postJson(route('settings.sync-engine-updates'));

        $res->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertStringContainsString('No linked WhatsApp numbers', $res->json('message'));
    }

    public function test_button_reports_engine_not_configured(): void
    {
        config(['evolution.base_url' => '', 'evolution.api_key' => '']);
        $this->tenant->update(['evolution_base_url' => null, 'evolution_api_key' => null]);

        $res = $this->postJson(route('settings.sync-engine-updates'));

        $res->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertStringContainsString('engine URL and API key', $res->json('message'));
    }
}
