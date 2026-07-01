<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Tiny audit-trail helper. Records who did what, to which record, and from where.
 * Tenant + user are resolved from the current request context.
 */
class Audit
{
    public static function log(string $action, ?Model $subject = null, ?string $description = null): void
    {
        $tenantId = Tenancy::id();

        if (! $tenantId) {
            return; // no tenant context (e.g. anonymous) — nothing to attribute
        }

        ActivityLog::create([
            'tenant_id'    => $tenantId,
            'user_id'      => auth()->id(),
            'action'       => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id'   => $subject?->getKey(),
            'description'  => $description,
            'ip'           => request()->ip(),
            'created_at'   => now(),
        ]);
    }
}
