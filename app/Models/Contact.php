<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'phone', 'email', 'country', 'attributes', 'tags', 'opted_out',
        'wa_status', 'verified_at',
    ];

    protected $casts = [
        'attributes'  => 'array',
        'tags'        => 'array',
        'opted_out'   => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(ContactGroup::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(SequenceEnrollment::class);
    }

    public function scopeTagged(Builder $query, ?string $tag): Builder
    {
        if (! $tag) {
            return $query;
        }

        // JSON array column — match the exact tag token inside the stored list.
        return $query->where('tags', 'like', '%"'.$tag.'"%');
    }

    /**
     * Store phone numbers as digits only (no +, spaces or dashes) — the format Evolution expects.
     */
    public function setPhoneAttribute($value): void
    {
        $this->attributes['phone'] = preg_replace('/\D+/', '', (string) $value);
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
