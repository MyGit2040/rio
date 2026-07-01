<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Contact extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'phone', 'email', 'country', 'attributes', 'opted_out',
        'wa_status', 'verified_at',
    ];

    protected $casts = [
        'attributes'  => 'array',
        'opted_out'   => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(ContactGroup::class);
    }

    /**
     * Store phone numbers as digits only (no +, spaces or dashes) — the format Evolution expects.
     */
    public function setPhoneAttribute($value): void
    {
        $this->attributes['phone'] = preg_replace('/\D+/', '', (string) $value);
    }

    /**
     * Consent flag (inverse of opted_out). Only opted-in contacts receive campaigns.
     */
    public function getIsOptedInAttribute(): bool
    {
        return ! $this->opted_out;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
              ->orWhere('phone', 'like', "%{$term}%")
              ->orWhere('email', 'like', "%{$term}%");
        });
    }

    public function scopeReachable(Builder $query): Builder
    {
        return $query->where('opted_out', false);
    }

    /**
     * Limit to numbers confirmed to exist on WhatsApp.
     */
    public function scopeWhatsappValid(Builder $query): Builder
    {
        return $query->where('wa_status', 'valid');
    }
}
