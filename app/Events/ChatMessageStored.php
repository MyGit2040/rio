<?php

namespace App\Events;

use App\Models\Message;
use App\Support\LocalTime;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A chat message (inbound or outbound) was stored — pushed to the workspace's
 * private channel so every open Chats tab updates the same second.
 *
 * ShouldBroadcastNow on purpose: the push must not depend on a queue worker
 * being alive, and the whole dispatch is wrapped by ChatRealtime (a dead
 * socket server degrades to the page's polling, never breaks message flow).
 */
class ChatMessageStored implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $deviceId,
        public readonly string $phone,
        public readonly ?string $contactName,
        public readonly array $message,
    ) {
    }

    public static function fromMessage(Message $message, int $tenantId, ?string $contactName = null): self
    {
        return new self(
            tenantId: $tenantId,
            deviceId: (int) $message->whatsapp_instance_id,
            phone: (string) $message->phone,
            contactName: $contactName,
            message: [
                'id'        => $message->id,
                'direction' => $message->direction,
                'type'      => $message->type,
                'body'      => (string) ($message->body ?: ''),
                'status'    => $message->status,
                'at'        => LocalTime::format($message->created_at, 'M j, g:i A'),
            ],
        );
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('chat.'.$this->tenantId);
    }

    public function broadcastAs(): string
    {
        return 'chat.message';
    }

    public function broadcastWith(): array
    {
        return [
            'device_id'    => $this->deviceId,
            'phone'        => $this->phone,
            'contact_name' => $this->contactName,
            'message'      => $this->message,
        ];
    }
}
