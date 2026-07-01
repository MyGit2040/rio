<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A subscription plan / pricing tier. Global (not tenant-scoped) — defined by
 * the platform operator in Super-Admin. A tenant references one by its `key`.
 */
class Plan extends Model
{
    protected $fillable = [
        'key', 'name', 'description', 'price', 'annual_price', 'billing_period',
        'limits', 'features', 'is_popular', 'is_default', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'limits'       => 'array',
        'price'        => 'decimal:2',
        'annual_price' => 'decimal:2',
        'is_popular'   => 'boolean',
        'is_default'   => 'boolean',
        'is_active'    => 'boolean',
        'sort_order'   => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('price');
    }

    public static function byKey(?string $key): ?self
    {
        return $key ? static::where('key', $key)->first() : null;
    }

    /** The plan new/unassigned tenants fall back to. */
    public static function defaultPlan(): ?self
    {
        return static::where('is_default', true)->first() ?? static::ordered()->first();
    }

    /** A single limit value (0 = unlimited). */
    public function limit(string $key): int
    {
        return (int) data_get($this->limits, $key, 0);
    }

    /**
     * Features parsed for display: each line becomes {value, included}.
     * A leading "-" marks an excluded feature (shown struck-through).
     *
     * @return Collection<int, array{value: string, included: bool}>
     */
    public function getFeatureListAttribute(): Collection
    {
        return collect(preg_split('/\r\n|\r|\n/', (string) $this->features))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->map(fn ($line) => [
                'value'    => ltrim($line, '+- '),
                'included' => ! str_starts_with($line, '-'),
            ])
            ->values();
    }
}
