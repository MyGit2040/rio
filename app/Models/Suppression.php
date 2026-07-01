<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Suppression extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'phone', 'reason', 'source'];

    public function setPhoneAttribute($value): void
    {
        $this->attributes['phone'] = preg_replace('/\D+/', '', (string) $value);
    }

    /**
     * Is this number on the current tenant's do-not-contact list?
     */
    public static function has(string $phone): bool
    {
        return static::where('phone', preg_replace('/\D+/', '', $phone))->exists();
    }
}
