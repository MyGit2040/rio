<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class GoogleContactSyncRun extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'device_ids', 'contact_ids', 'status', 'total', 'created', 'skipped', 'failed', 'error', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'device_ids' => 'array', 'contact_ids' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public function progressPercent(): int
    {
        $done = $this->created + $this->skipped + $this->failed;
        return $this->total > 0 ? min(100, (int) round($done / $this->total * 100)) : 0;
    }
}
