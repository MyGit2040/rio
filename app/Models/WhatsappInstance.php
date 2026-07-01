<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappInstance extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'instance_name', 'token', 'status', 'daily_limit',
        'phone_number', 'profile_name', 'qr_code', 'pairing_code', 'connected_at',
    ];

    protected $casts = [
        'connected_at' => 'datetime',
    ];

    public function sentToday(): int
    {
        return Message::where('whatsapp_instance_id', $this->id)
            ->where('direction', 'out')
            ->whereDate('created_at', today())
            ->count();
    }

    public function atDailyCap(): bool
    {
        return $this->daily_limit > 0 && $this->sentToday() >= $this->daily_limit;
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
