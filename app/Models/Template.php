<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'type', 'body', 'variants', 'media_url', 'media_type', 'poll', 'buttons', 'cards',
    ];

    protected $casts = [
        'poll'     => 'array',
        'variants' => 'array',
        'buttons'  => 'array',
        'cards'    => 'array',
    ];
}
