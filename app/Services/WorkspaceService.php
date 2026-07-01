<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Platform-admin provisioning: create & manage client workspaces (tenants)
 * together with their owner login and subscription window.
 */
class WorkspaceService
{
    /** Subscription plan types → duration in days (null = never expires). */
    public const PLAN_TYPES = [
        'trial'       => ['label' => 'Trial (7 days)',       'days' => 7],
        'monthly'     => ['label' => 'Monthly (30 days)',    'days' => 30],
        'quarterly'   => ['label' => 'Quarterly (90 days)',  'days' => 90],
        'half_yearly' => ['label' => 'Half-yearly (180 days)', 'days' => 180],
        'yearly'      => ['label' => 'Yearly (365 days)',    'days' => 365],
        'lifetime'    => ['label' => 'Lifetime (no expiry)', 'days' => null],
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Tenant
    {
        return DB::transaction(function () use ($data) {
            $tenant = Tenant::create([
                'name'            => $data['name'],
                'slug'            => $this->uniqueSlug($data['name']),
                'plan'            => 'business',
                'status'          => 'active',
                'expires_at'      => $this->expiryFor($data['plan_type'] ?? 'monthly'),
                'max_devices'     => (int) ($data['max_devices'] ?? 0),
                'enabled_modules' => $data['modules'] ?? [],
            ]);

            User::create([
                'tenant_id' => $tenant->id,
                'name'      => $data['owner_name'],
                'email'     => $data['owner_email'],
                'role'      => 'owner',
                'password'  => Hash::make($data['password']),
            ]);

            return $tenant;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Tenant $tenant, array $data): Tenant
    {
        $tenant->fill([
            'name'            => $data['name'],
            'max_devices'     => (int) ($data['max_devices'] ?? 0),
            'enabled_modules' => $data['modules'] ?? [],
        ]);

        // Renew: (re)set the subscription window from today when a plan type is chosen.
        if (! empty($data['plan_type'])) {
            $tenant->expires_at = $this->expiryFor($data['plan_type']);
        }

        $tenant->save();

        // Optional owner password reset.
        if (! empty($data['password'])) {
            $tenant->users()->where('role', 'owner')->orderBy('id')->first()
                ?->update(['password' => Hash::make($data['password'])]);
        }

        return $tenant;
    }

    private function expiryFor(string $planType): ?\Illuminate\Support\Carbon
    {
        $days = self::PLAN_TYPES[$planType]['days'] ?? 30;

        return $days === null ? null : now()->addDays($days);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $i = 1;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
