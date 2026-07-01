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

    /** The tenant's Plan model (or a safe default). Null only if no plans exist at all. */
    public function plan(): ?Plan
    {
        if (! $this->resolvedDone) {
            $key = $this->tenant->plan;

            if ($key) {
                // A set-but-unknown key (e.g. a plan that was removed) fails CLOSED to the
                // config-safe default — never the admin's default plan, which could be unlimited.
                $this->resolved = Plan::byKey($key)
                    ?? Plan::byKey(config('plans.default', 'free'))
                    ?? Plan::defaultPlan();
            } else {
                // No plan assigned yet: the admin's chosen default plan.
                $this->resolved = Plan::defaultPlan()
                    ?? Plan::byKey(config('plans.default', 'free'));
            }

            $this->resolvedDone = true;
        }

        return $this->resolved;
    }

    public function planKey(): string
    {
        // Always the RESOLVED plan's key (an invalid tenant.plan resolves to the safe default).
        return optional($this->plan())->key ?? config('plans.default', 'free');
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
