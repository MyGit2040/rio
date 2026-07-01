<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\WhatsappInstance;

/**
 * Resolves the current workspace's plan and enforces its usage limits.
 */
class PlanLimit
{
    public function __construct(private readonly Tenant $tenant)
    {
    }

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    /** @return array<string, mixed> */
    public function plan(): array
    {
        $tiers = config('plans.tiers');
        $key = $this->tenant->plan ?: config('plans.default');

        return $tiers[$key] ?? $tiers[config('plans.default')];
    }

    public function planKey(): string
    {
        return $this->tenant->plan ?: config('plans.default', 'free');
    }

    public function limit(string $key): int
    {
        // A workspace-specific device cap (set by the admin) overrides the plan default.
        if ($key === 'devices' && (int) $this->tenant->max_devices > 0) {
            return (int) $this->tenant->max_devices;
        }

        return (int) data_get($this->plan(), "limits.$key", 0);
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
