<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrackedLink extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'campaign_id', 'token', 'url', 'clicks'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function clickEvents(): HasMany
    {
        return $this->hasMany(LinkClick::class);
    }
}
