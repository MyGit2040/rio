<?php

namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off cleanup for the historical duplicate inbound/outbound rows created
 * before the webhook became idempotent (the "same reply every minute" bug).
 *
 * Keeps the earliest row of each (whatsapp_instance_id, message_id) group and
 * deletes the rest. Rows without a message_id are left untouched (can't be
 * safely matched). Run with --dry-run first to preview.
 */
class DedupMessages extends Command
{
    protected $signature = 'messages:dedup {--dry-run : Show what would be removed without deleting}';

    protected $description = 'Remove duplicate message rows (same WhatsApp message id), keeping the earliest copy';

    public function handle(): int
    {
        $groups = Message::withoutGlobalScopes()
            ->whereNotNull('message_id')
            ->where('message_id', '!=', '')
            ->select('whatsapp_instance_id', 'message_id', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as c'))
            ->groupBy('whatsapp_instance_id', 'message_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No duplicate messages found — nothing to do.');

            return self::SUCCESS;
        }

        $toDelete = (int) $groups->sum(fn ($g) => (int) $g->c - 1);

        $this->info("Found {$groups->count()} message(s) with duplicates — {$toDelete} extra row(s) to remove.");

        if ($this->option('dry-run')) {
            $this->line('Dry run: nothing deleted. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        $deleted = 0;
        foreach ($groups as $g) {
            $deleted += Message::withoutGlobalScopes()
                ->where('whatsapp_instance_id', $g->whatsapp_instance_id)
                ->where('message_id', $g->message_id)
                ->where('id', '!=', $g->keep_id)
                ->delete();
        }

        $this->info("Done. Removed {$deleted} duplicate row(s); kept the earliest copy of each.");

        return self::SUCCESS;
    }
}
