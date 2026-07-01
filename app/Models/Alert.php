<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Alert extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'level', 'title', 'body', 'context', 'read_at'];

    protected $casts = [
        'context' => 'array',
        'read_at' => 'datetime',
    ];
}
