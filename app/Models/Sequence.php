<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sequence extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'whatsapp_instance_id', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WhatsappInstance::class, 'whatsapp_instance_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(SequenceStep::class)->orderBy('position');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(SequenceEnrollment::class);
    }
}
