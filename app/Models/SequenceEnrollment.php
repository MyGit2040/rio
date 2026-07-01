<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SequenceEnrollment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'sequence_id', 'contact_id', 'current_step', 'status', 'next_run_at',
    ];

    protected $casts = ['next_run_at' => 'datetime'];

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
