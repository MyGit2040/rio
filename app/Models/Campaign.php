<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'whatsapp_instance_id', 'device_ids', 'template_id', 'name', 'type',
        'body', 'variants', 'media_url', 'media_type', 'poll', 'buttons', 'cards', 'status',
        'min_delay', 'max_delay', 'max_retries', 'scheduled_at', 'started_at', 'completed_at',
        'total', 'sent', 'failed',
    ];

    protected $casts = [
        'poll'         => 'array',
        'variants'     => 'array',
        'buttons'      => 'array',
        'cards'        => 'array',
        'device_ids'   => 'array',
        'scheduled_at' => 'datetime',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WhatsappInstance::class, 'whatsapp_instance_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function progressPercent(): int
    {
        if ($this->total === 0) {
            return 0;
        }

        return (int) round((($this->sent + $this->failed) / $this->total) * 100);
    }
}
