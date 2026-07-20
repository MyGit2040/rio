<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class GoogleContactLink extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'whatsapp_instance_id', 'contact_id', 'resource_name'];
}
