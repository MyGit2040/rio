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
        'tenant_id', 'whatsapp_instance_id', 'device_ids', 'device_limits', 'rotate_every', 'template_id', 'name', 'type',
        'body', 'footer', 'variants', 'media_url', 'media_type', 'poll', 'buttons', 'cards', 'track_links', 'audience', 'group_ids', 'tag', 'status',
        'min_delay', 'max_delay', 'max_retries', 'scheduled_at', 'started_at', 'completed_at',
        'total', 'sent', 'failed',
    ];

    protected $casts = [
        'poll'          => 'array',
        'variants'      => 'array',
        'buttons'       => 'array',
        'cards'         => 'array',
        'device_ids'    => 'array',
        'device_limits' => 'array',
        'group_ids'     => 'array',
        'track_links'   => 'boolean',
        'scheduled_at'  => 'datetime',
        'started_at'    => 'datetime',
        'completed_at'  => 'datetime',
    ];

    /**
     * How many contacts were left out because every selected number hit its
     * per-device cap. Transient (not persisted) — set during recipient build so
     * the controller can warn. Declared so it bypasses Eloquent's attribute magic.
     */
    public int $skippedForCapacity = 0;

    /** Per-device message cap for this campaign (device id => max; 0/absent = unlimited). */
    public function deviceLimit(int $deviceId): int
    {
        return (int) ($this->device_limits[$deviceId] ?? 0);
    }

    public function trackedLinks(): HasMany
    {
        return $this->hasMany(TrackedLink::class);
    }

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

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function progressPercent(): int
    {
        if ($this->total === 0) {
            return 0;
        }

        return (int) round((($this->sent + $this->failed) / $this->total) * 100);
    }
}
