<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'token', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];

    /**
     * Create a token and return [model, plaintext]. The plaintext is shown once.
     */
    public static function generate(string $name): array
    {
        $plain = 'eag_'.Str::random(48);

        $model = static::create([
            'name'  => $name,
            'token' => hash('sha256', $plain),
        ]);

        return [$model, $plain];
    }
}
