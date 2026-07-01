<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\WhatsappInstance;

/**
 * Resolves the current workspace's plan and enforces its usage limits.
 * Plans live in the `plans` table (managed in Super-Admin); config/plans.php
 * is used only as a fallback when the table hasn't been populated yet.
 */
class PlanLimit
{
    private ?Plan $resolved = null;

    private bool $resolvedDone = false;

    public function __construct(private readonly Tenant $tenant)
    {
    }

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /** The tenant's Plan model (or the default plan). Null only if no plans exist. */
    public function plan(): ?Plan
    {
        if (! $this->resolvedDone) {
            $this->resolved = Plan::byKey($this->tenant->plan) ?? Plan::defaultPlan();
            $this->resolvedDone = true;
        }

        return $this->resolved;
    }

    public function planKey(): string
    {
        return $this->tenant->plan ?: (optional($this->plan())->key ?? config('plans.default', 'free'));
    }

    public function planName(): string
    {
        return optional($this->plan())->name ?? ucfirst($this->planKey());
    }

    public function limit(string $key): int
    {
        // A workspace-specific device cap (set by the admin) overrides the plan default.
        if ($key === 'devices' && (int) $this->tenant->max_devices > 0) {
            return (int) $this->tenant->max_devices;
        }

        $plan = $this->plan();

        if ($plan) {
            return $plan->limit($key);
        }

        // Fallback: plans table empty — read the config tier.
        return (int) data_get(config('plans.tiers.'.$this->planKey()), "limits.$key", 0);
    }

    public function usage(string $key): int
    {
        return match ($key) {
            'devices'          => WhatsappInstance::count(),
            'contacts'         => Contact::count(),
            'monthly_messages' => Message::where('direction', 'out')
                ->where('created_at', '>=', now()->startOfMonth())->count(),
            default            => 0,
        };
    }

    /**
     * True when the tenant is already at/over the limit for $key (0 = unlimited).
     */
    public function reached(string $key): bool
    {
        $limit = $this->limit($key);

        return $limit > 0 && $this->usage($key) >= $limit;
    }

    public function remaining(string $key): ?int
    {
        $limit = $this->limit($key);

        return $limit === 0 ? null : max(0, $limit - $this->usage($key));
    }

    public function percent(string $key): int
    {
        $limit = $this->limit($key);

        return $limit > 0 ? min(100, (int) round($this->usage($key) / $limit * 100)) : 0;
    }
}
