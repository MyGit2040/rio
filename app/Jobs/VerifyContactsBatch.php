<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\WhatsappInstance;
use App\Support\Tenancy;
use App\Support\Whatsapp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Verifies a group's contacts against WhatsApp in SMALL throttled batches — it
 * checks ~20, then re-queues itself after a short random delay — so a number is
 * never hammered with a huge verification burst (keeps the account safe).
 */
class VerifyContactsBatch implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $groupId)
    {
    }

    public function handle(): void
    {
        $group = ContactGroup::withoutGlobalScopes()->find($this->groupId);
        if (! $group) {
            return;
        }

        Tenancy::run($group->tenant_id, function () use ($group) {
            $device = WhatsappInstance::where('status', 'open')->first();
            $engine = $device ? Whatsapp::forInstance($device) : null;

            if (! $device || ! $engine->configured()) {
                return; // no connected device — can't verify right now
            }

            $contacts = $group->contacts()->where('wa_status', 'unverified')->limit(20)->get(['contacts.id', 'contacts.phone']);
            if ($contacts->isEmpty()) {
                return;
            }

            try {
                $result = $engine->checkNumbers($device->instance_name, $contacts->pluck('phone')->all());
            } catch (\Throwable $e) {
                Log::error('Batch verify failed', ['error' => $e->getMessage()]);

                return;
            }

            $existsByNumber = collect($result)->keyBy(fn ($row) => preg_replace('/\D+/', '', (string) ($row['number'] ?? '')));

            foreach ($contacts as $contact) {
                $exists = (bool) data_get($existsByNumber->get($contact->phone), 'exists', false);
                Contact::where('id', $contact->id)->update(['wa_status' => $exists ? 'valid' : 'invalid', 'verified_at' => now()]);
            }

            // More to check? Re-queue after a short delay so it stays gentle.
            if ($group->contacts()->where('wa_status', 'unverified')->exists()) {
                self::dispatch($group->id)->delay(now()->addSeconds(random_int(20, 45)));
            }
        });
    }
}
