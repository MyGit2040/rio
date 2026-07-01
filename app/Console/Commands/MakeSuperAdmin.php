<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MakeSuperAdmin extends Command
{
    protected $signature = 'eagle:make-admin {email} {--password=} {--name=Administrator}';

    protected $description = 'Create or promote a platform super-admin (root login for the SaaS admin panel)';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update(['is_super_admin' => true]);
            if ($password = $this->option('password')) {
                $user->update(['password' => Hash::make($password)]);
            }
            $this->info("Promoted {$email} to super-admin.");

            return self::SUCCESS;
        }

        $password = $this->option('password') ?: Str::password(14);

        $tenant = Tenant::create([
            'name'   => 'Platform Admin',
            'slug'   => 'platform-admin-'.Str::lower(Str::random(6)),
            'plan'   => 'business',
            'status' => 'active',
        ]);

        User::create([
            'tenant_id'      => $tenant->id,
            'name'           => $this->option('name'),
            'email'          => $email,
            'role'           => 'owner',
            'is_super_admin' => true,
            'password'       => Hash::make($password),
        ]);

        $this->info("Created super-admin {$email}");
        $this->line("Password: {$password}");

        return self::SUCCESS;
    }
}
