<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinkClick extends Model
{
    public $timestamps = false;

    protected $fillable = ['tracked_link_id', 'phone', 'ip', 'user_agent', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function link(): BelongsTo
    {
        return $this->belongsTo(TrackedLink::class, 'tracked_link_id');
    }
}
