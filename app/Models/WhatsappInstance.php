<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappInstance extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'instance_name', 'driver', 'token', 'status', 'daily_limit',
        'warmup_enabled', 'warmup_start', 'warmup_per_day', 'warmup_started_at',
        'phone_number', 'profile_name', 'qr_code', 'pairing_code', 'connected_at',
        'google_contacts_email',
    ];

    protected $casts = [
        'connected_at'      => 'datetime',
        'warmup_enabled'    => 'boolean',
        'warmup_started_at' => 'date',
    ];

    public function sentToday(): int
    {
        return Message::where('whatsapp_instance_id', $this->id)
            ->where('direction', 'out')
            ->whereDate('created_at', today())
            ->count();
    }

    /**
     * Today's send ceiling. Warm-up ramps a fresh number up gradually
     * (start + per_day × days elapsed), never above the hard daily_limit.
     * Returns 0 for "no cap".
     */
    public function effectiveDailyCap(): int
    {
        $hard = (int) $this->daily_limit;

        if (! $this->warmup_enabled) {
            return $hard;
        }

        $days = $this->warmup_started_at
            ? (int) $this->warmup_started_at->startOfDay()->diffInDays(today()->startOfDay())
            : 0;

        $ramp = max(1, (int) $this->warmup_start) + ($days * max(0, (int) $this->warmup_per_day));

        return $hard > 0 ? min($hard, $ramp) : $ramp;
    }

    public function atDailyCap(): bool
    {
        $cap = $this->effectiveDailyCap();

        return $cap > 0 && $this->sentToday() >= $cap;
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function isConnected(): bool
    {
        return $this->status === 'open';
    }
}
