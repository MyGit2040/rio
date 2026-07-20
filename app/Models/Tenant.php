<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'name', 'slug', 'plan', 'status', 'expires_at', 'max_devices', 'enabled_modules',
        'settings', 'whatsapp_driver',
        'openwa_base_url', 'openwa_api_key', 'openwa_session_id',
        'baileys_base_url', 'baileys_api_key', 'baileys_signing_secret',
    ];

    protected $casts = [
        'settings'        => 'array',
        'enabled_modules' => 'array',
        'expires_at'      => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * A workspace is blocked when suspended or past its subscription date.
     */
    public function isBlocked(): bool
    {
        return $this->status === 'suspended' || $this->isExpired();
    }

    /**
     * Is a feature module available to this workspace?
     * null/empty enabled_modules = everything on (back-compat for older workspaces).
     */
    public function allows(string $module): bool
    {
        $modules = $this->enabled_modules;

        return empty($modules) || in_array($module, $modules, true);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function instances(): HasMany
    {
        return $this->hasMany(WhatsappInstance::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }
}
