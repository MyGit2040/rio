<?php

namespace App\Support;

use App\Events\ChatMessageStored;
use App\Events\ChatMessageStatusUpdated;
use App\Models\Message;
use App\Models\WhatsappInstance;
use Illuminate\Support\Facades\Log;

/**
 * Real-time push for the Chats workspace.
 *
 * Every broadcast is best-effort: with no broadcaster configured the null
 * driver discards silently, and a configured-but-unreachable Reverb server
 * logs ONE error and moves on — the socket layer must never break message
 * flow (the page still has its polling fallback either way).
 */
class ChatRealtime
{
    public static function messageStored(Message $message, ?string $contactName = null): void
    {
        if (! $message->tenant_id || ! $message->whatsapp_instance_id || ! $message->phone) {
            return;
        }

        self::dispatch(ChatMessageStored::fromMessage($message, (int) $message->tenant_id, $contactName));
    }

    /**
     * Mirror a delivery/read/failed receipt onto the chat thread's own rows
     * (sent → delivered → read; a late 'delivered' never downgrades an
     * already-read row) and push the tick change. Shared by BOTH webhook
     * engines so the mirror rule cannot drift between them.
     */
    public static function statusMirrored(WhatsappInstance $instance, string $messageId, string $status): void
    {
        $rows = Message::where('whatsapp_instance_id', $instance->id)
            ->where('direction', 'out')
            ->where('message_id', $messageId)
            ->whereIn('status', ['sent', 'delivered'])
            ->get(['id', 'phone']);

        if ($rows->isEmpty()) {
            return;
        }

        Message::whereIn('id', $rows->pluck('id'))->update(['status' => $status]);

        self::dispatch(new ChatMessageStatusUpdated(
            tenantId: (int) $instance->tenant_id,
            deviceId: (int) $instance->id,
            phone: (string) $rows->first()->phone,
            messageIds: $rows->pluck('id')->all(),
            status: $status,
        ));
    }

    private static function dispatch(object $event): void
    {
        try {
            // event(), not broadcast(): the dispatcher path honours Event::fake
            // in tests and still broadcasts synchronously (ShouldBroadcastNow).
            event($event);
        } catch (\Throwable $e) {
            Log::error('Chat realtime broadcast failed', [
                'event' => $event::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
