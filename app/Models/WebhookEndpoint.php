<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WebhookEndpoint extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'url', 'events', 'secret', 'is_active', 'last_fired_at'];

    protected $casts = [
        'events'        => 'array',
        'is_active'     => 'boolean',
        'last_fired_at' => 'datetime',
    ];

    public function listensFor(string $event): bool
    {
        return $this->is_active && in_array($event, $this->events ?? [], true);
    }
}
