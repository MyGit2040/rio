<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EngineReceiveUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['openwa.base_url' => 'https://openwa.test/api', 'openwa.api_key' => 'k', 'openwa.session_id' => 'default']);

        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme-updates',
            'openwa_base_url' => 'https://openwa.test/api', 'openwa_api_key' => 'k', 'openwa_session_id' => 'default',
        ]);
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Owner', 'email' => 'updates@x.dev',
            'role' => 'owner', 'password' => Hash::make('secret'),
        ]);
        $this->actingAs($user);
    }

    public function test_runtime_managed_webhooks_are_explained_without_a_gateway_call(): void
    {
        $res = $this->postJson(route('settings.sync-engine-updates'));

        $res->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertStringContainsString('runtime starts', $res->json('message'));
    }

    public function test_endpoint_does_not_attempt_legacy_per_device_webhook_registration(): void
    {
        $res = $this->postJson(route('settings.sync-engine-updates'));

        $res->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertStringContainsString('runtime starts', $res->json('message'));
    }
}
