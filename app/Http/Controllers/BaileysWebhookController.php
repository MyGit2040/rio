<?php

namespace App\Http\Controllers;

use App\Models\CampaignRecipient;
use App\Models\Message;
use App\Models\WhatsappInstance;
use App\Services\InboundMessageRecorder;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Inbound events from the eagleto-baileys-gateway.
 *
 * Trust model differs from the older OpenWA endpoint, which authenticated with
 * a shared secret in the URL path. Here every delivery is HMAC-signed over the
 * timestamp and the exact body bytes, so a captured URL is not by itself enough
 * to inject events.
 *
 * Three guards, in order: signature, freshness, then event-id uniqueness. The
 * gateway guarantees at-least-once delivery, so a repeat is expected and must
 * be a no-op rather than a second status change or a duplicate reply.
 */
class BaileysWebhookController extends Controller
{
    public function __construct(private readonly InboundMessageRecorder $recorder)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $timestamp = (string) $request->header('X-Eagleto-Timestamp', '');
        $signature = (string) $request->header('X-Eagleto-Signature', '');
        $eventId = (string) $request->header('X-Eagleto-Event-ID', '');

        if (! $this->verify($raw, $timestamp, $signature)) {
            Log::warning('Rejected a Baileys webhook with an invalid signature', [
                'event_id' => $eventId ?: null,
                'ip' => $request->ip(),
            ]);

            return response()->json(['ok' => false, 'error' => 'invalid_signature'], 401);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return response()->json(['ok' => false, 'error' => 'invalid_payload'], 422);
        }

        $eventId = $eventId ?: (string) ($payload['event_id'] ?? '');
        $eventType = (string) ($payload['event_type'] ?? '');

        if ($eventId === '' || $eventType === '') {
            return response()->json(['ok' => false, 'error' => 'missing_event_identity'], 422);
        }

        // Claim the event id. A unique constraint decides the race, so two
        // concurrent redeliveries cannot both proceed.
        if (! $this->claim($eventId, $eventType)) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        $instance = $this->resolveInstance($payload);

        if (! $instance) {
            // Acknowledged deliberately: retrying would not help, and leaving it
            // unacknowledged would stall the gateway's queue behind an event
            // this app can never place.
            Log::info('Baileys webhook for an unknown device', ['event_id' => $eventId, 'event_type' => $eventType]);

            return response()->json(['ok' => true, 'ignored' => 'unknown_instance']);
        }

        Tenancy::run($instance->tenant_id, function () use ($eventType, $instance, $payload) {
            $data = (array) ($payload['data'] ?? []);

            match (true) {
                str_starts_with($eventType, 'instance.') => $this->onInstanceEvent($instance, $eventType, $data),
                str_starts_with($eventType, 'message.') => $this->onMessageEvent($instance, $eventType, $data),
                default => null,
            };
        });

        return response()->json(['ok' => true]);
    }

    private function verify(string $raw, string $timestamp, string $signature): bool
    {
        $secret = (string) config('baileys.webhook_secret');

        // An unconfigured secret must fail closed. The older endpoint treated an
        // empty secret as "no verification", which silently left it open.
        if ($secret === '' || $timestamp === '' || $signature === '') {
            return false;
        }

        if (abs(time() - (int) $timestamp) > (int) config('baileys.webhook_max_skew', 300)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp.'.'.$raw, $secret), $signature);
    }

    private function claim(string $eventId, string $eventType): bool
    {
        try {
            DB::table('baileys_webhook_events')->insert([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return false;
        } catch (\Throwable $e) {
            // Any other failure is a real problem: log it loudly (production
            // runs at error level) and refuse the event so the gateway retries
            // rather than dropping it.
            Log::error('Could not record a Baileys webhook event id', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function resolveInstance(array $payload): ?WhatsappInstance
    {
        $external = $payload['instance_id'] ?? null;

        if (! is_string($external) || $external === '') {
            return null;
        }

        return WhatsappInstance::withoutGlobalScopes()
            ->where('instance_name', $external)
            ->first();
    }

    private function onInstanceEvent(WhatsappInstance $instance, string $eventType, array $data): void
    {
        // The gateway's lifecycle is richer than this app's three states, so it
        // is collapsed the same way BaileysGatewayDriver::stateFor does. Only
        // 'ready' means sendable.
        $status = match ($eventType) {
            'instance.ready' => 'open',
            'instance.logged_out', 'instance.replaced', 'instance.restricted', 'instance.error' => 'close',
            default => 'connecting',
        };

        $attributes = ['status' => $status];

        if ($status === 'open') {
            $attributes['qr_code'] = null;
            $attributes['pairing_code'] = null;
            $attributes['connected_at'] = $instance->connected_at ?? now();
        }

        $instance->update($attributes);
    }

    private function onMessageEvent(WhatsappInstance $instance, string $eventType, array $data): void
    {
        $status = match ($eventType) {
            'message.delivered' => 'delivered',
            'message.read', 'message.played' => 'read',
            'message.failed' => 'failed',
            default => null,
        };

        $messageId = $data['gateway_message_id'] ?? null;

        if ($status && is_string($messageId)) {
            // Scoped to this device: message ids are only unique per gateway,
            // and an unscoped update could cross-write another workspace's row.
            CampaignRecipient::where('message_id', $messageId)
                ->whereIn('status', ['sent', 'delivered'])
                ->update(['status' => $status]);

            // Mirror the tick onto the chat thread's own row (sent → delivered →
            // read; a late 'delivered' must never downgrade an already-read row).
            Message::where('whatsapp_instance_id', $instance->id)
                ->where('direction', 'out')
                ->where('message_id', $messageId)
                ->whereIn('status', ['sent', 'delivered'])
                ->update(['status' => $status]);
        }

        if ($eventType === 'message.received') {
            $this->onInboundMessage($instance, $data);
        }
    }

    private function onInboundMessage(WhatsappInstance $instance, array $data): void
    {
        $phone = (string) ($data['phone'] ?? '');

        if ($phone === '') {
            return;
        }

        // The whole inbound pipeline (idempotency, contact upsert, the Message
        // row, campaign attribution, opt-out keywords, chatbot replies, hook
        // forwarding, outbound webhook) is shared with the OpenWA engine —
        // one copy, so the two endpoints can never drift. Before this, the
        // Baileys endpoint only called the chatbot: inbound replies were never
        // stored, so the Inbox, campaign Responses, contact-graph protection
        // and STOP opt-outs were all silently dead on Baileys devices.
        $kind = (string) ($data['kind'] ?? 'text');

        $this->recorder->record($instance, [
            'message_id' => $data['whatsapp_message_id'] ?? null,
            'remote_jid' => $data['chat_jid'] ?? null,
            'phone'      => preg_replace('/\D+/', '', $phone),
            'from_me'    => false, // the gateway deliberately skips own echoes
            'kind'       => $kind !== '' ? $kind : 'text',
            'detail'     => (string) ($data['text'] ?? ''),
            'push_name'  => $data['push_name'] ?? null,
        ]);
    }
}
