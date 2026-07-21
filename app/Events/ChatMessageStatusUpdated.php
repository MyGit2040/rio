<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Delivery/read receipts landed on outbound chat rows — pushed so the ✓✓
 * ticks flip the moment WhatsApp confirms, without waiting for a poll.
 */
class ChatMessageStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    /** @param array<int, int> $messageIds */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $deviceId,
        public readonly string $phone,
        public readonly array $messageIds,
        public readonly string $status,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('chat.'.$this->tenantId);
    }

    public function broadcastAs(): string
    {
        return 'chat.status';
    }

    public function broadcastWith(): array
    {
        return [
            'device_id'   => $this->deviceId,
            'phone'       => $this->phone,
            'message_ids' => $this->messageIds,
            'status'      => $this->status,
        ];
    }
}
