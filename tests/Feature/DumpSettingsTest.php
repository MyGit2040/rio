<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DumpSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dump(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-x']);
        $user = User::create(['tenant_id' => $tenant->id, 'name' => 'O', 'email' => 'o@x.dev', 'role' => 'owner', 'password' => Hash::make('x')]);
        file_put_contents(sys_get_temp_dir().'/settings-dump.html', $this->actingAs($user)->get('/settings')->getContent());
        $this->assertTrue(true);
    }
}
