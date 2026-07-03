<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProcessControlsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-proc']);
    }

    private function user(string $role): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst($role),
            'email' => $role.'@x.dev', 'role' => $role, 'password' => Hash::make('secret'),
        ]);
    }

    public function test_owner_can_restart_workers(): void
    {
        Artisan::spy();
        $this->actingAs($this->user('owner'));

        $res = $this->postJson(route('settings.restart-workers'));

        $res->assertOk()->assertJson(['ok' => true]);
        Artisan::shouldHaveReceived('call')->with('queue:restart')->once();
    }

    public function test_owner_can_retry_and_flush_failed_jobs(): void
    {
        $this->actingAs($this->user('owner'));

        $this->postJson(route('settings.retry-failed-jobs'))->assertOk()->assertJson(['ok' => true]);
        $this->postJson(route('settings.flush-failed-jobs'))->assertOk()->assertJson(['ok' => true]);
    }

    public function test_non_owner_is_forbidden(): void
    {
        $this->actingAs($this->user('agent'));

        $this->postJson(route('settings.restart-workers'))->assertForbidden();
        $this->postJson(route('settings.retry-failed-jobs'))->assertForbidden();
        $this->postJson(route('settings.flush-failed-jobs'))->assertForbidden();
    }

    public function test_guest_is_blocked(): void
    {
        // Web auth: a guest is redirected to login, never reaching the action.
        $this->post(route('settings.restart-workers'))->assertRedirect(route('login'));
    }
}
