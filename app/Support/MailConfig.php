<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\Config;

/**
 * Applies a workspace's SMTP settings (stored in tenant.settings) to the mailer
 * at runtime. Falls back to the platform's default mailer when not configured.
 */
class MailConfig
{
    public static function applyTenant(?Tenant $tenant): void
    {
        $s = $tenant?->settings ?? [];

        if (empty($s['smtp_host']) || empty($s['smtp_user'])) {
            return; // use the platform default mailer
        }

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp', [
            'transport'  => 'smtp',
            'host'       => $s['smtp_host'],
            'port'       => (int) ($s['smtp_port'] ?? 587),
            'encryption' => $s['smtp_encryption'] ?? 'tls',
            'username'   => $s['smtp_user'],
            'password'   => $s['smtp_pass'] ?? '',
            'timeout'    => 15,
        ]);
        Config::set('mail.from', [
            'address' => $s['smtp_from'] ?? $s['smtp_user'],
            'name'    => $tenant->name,
        ]);
    }
}
