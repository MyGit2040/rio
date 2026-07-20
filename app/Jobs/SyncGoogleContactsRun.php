<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\GoogleContactSyncRun;
use App\Models\WhatsappInstance;
use App\Services\GoogleContactsService;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class SyncGoogleContactsRun implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;
    public int $tries = 1;

    public function __construct(public int $runId)
    {
    }

    public function handle(GoogleContactsService $google): void
    {
        $run = GoogleContactSyncRun::withoutGlobalScopes()->find($this->runId);
        if (! $run || ! in_array($run->status, ['queued', 'running'], true)) {
            return;
        }

        Tenancy::run($run->tenant_id, function () use ($run, $google) {
            $run->update(['status' => 'running', 'started_at' => $run->started_at ?? now(), 'error' => null]);
            $devices = WhatsappInstance::whereIn('id', $run->device_ids ?? [])
                ->whereNotNull('google_contacts_token')->get();

            if ($devices->isEmpty()) {
                $run->update(['status' => 'failed', 'error' => 'No selected Gmail account is connected.', 'completed_at' => now()]);
                return;
            }

            foreach ($devices as $device) {
                foreach (array_chunk($run->contact_ids ?? [], 50) as $contactIds) {
                    $contacts = Contact::whereIn('id', $contactIds)->get()->all();
                    if (! $contacts) {
                        continue;
                    }
                    try {
                        $result = $google->sync($device, $contacts);
                        $run->increment('created', $result['created']);
                        $run->increment('skipped', $result['skipped']);
                        $run->increment('failed', $result['failed']);
                    } catch (\Throwable $e) {
                        report($e);
                        $run->increment('failed', count($contacts));
                        $run->update(['error' => Str::limit($e->getMessage(), 500)]);
                    }
                }
            }

            $run->refresh();
            $run->update(['status' => $run->failed >= $run->total ? 'failed' : 'completed', 'completed_at' => now()]);
        });
    }
}
