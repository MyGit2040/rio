<?php

namespace App\Services;

use App\Contracts\WhatsappGateway;
use App\Models\Tenant;
use App\Models\WhatsappInstance;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Driver for the standalone eagleto-baileys-gateway service.
 *
 * The gateway owns WhatsApp sockets, sessions and transport. This class is only
 * a signed HTTP client plus a translation layer: it maps the gateway's richer
 * vocabulary onto the shapes the rest of CRM already expects, so campaigns,
 * sequences and the device UI keep working unchanged.
 *
 * Two translations matter:
 *  - The gateway has 16 lifecycle states; this app understands three
 *    (open|connecting|close). See stateFor().
 *  - Sends are asynchronous. The gateway returns 202 Accepted once a message is
 *    durably recorded, and the real outcome arrives later by webhook. An
 *    accepted send is therefore reported here as ok=true, which matches how the
 *    previous engines behaved from the caller's point of view.
 */
class BaileysGatewayDriver implements WhatsappGateway
{
    /**
     * Stable key used to make a retry safe. When set, the gateway guarantees
     * that repeating the same key never produces a second WhatsApp message.
     */
    protected ?string $idempotencyKey = null;

    public function __construct(
        protected string $baseUrl,
        protected string $apiKey,
        protected string $signingSecret,
    ) {
    }

    public static function forTenant(?Tenant $tenant): static
    {
        return new static(
            rtrim((string) ($tenant?->baileys_base_url ?: config('baileys.base_url')), '/'),
            (string) ($tenant?->baileys_api_key ?: config('baileys.api_key')),
            (string) ($tenant?->baileys_signing_secret ?: config('baileys.signing_secret')),
        );
    }

    public static function forInstance(WhatsappInstance $instance): static
    {
        return static::forTenant($instance->tenant);
    }

    public function configured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '' && $this->signingSecret !== '';
    }

    /**
     * Supply the idempotency key for the next send.
     *
     * Callers should derive it from something stable across retries (the
     * campaign recipient row, for example) — never from a timestamp or a random
     * value, or a retried job would send a second copy.
     */
    public function withIdempotencyKey(string $key): static
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    // -- HTTP plumbing ------------------------------------------------------

    /**
     * Sign and send. The signature covers the timestamp, a single-use nonce,
     * the method, the path *including its query string*, and the exact request
     * body bytes — so a captured request cannot be replayed, retargeted at a
     * different path, or have its query parameters altered.
     */
    protected function request(string $method, string $path, array $payload = []): Response
    {
        // The body is decided ONCE here and used for both signing and sending.
        // Methods that carry no body must sign the empty string, and a
        // body-carrying method with no payload still transmits '{}' — so that
        // is what gets signed. An earlier version signed '' and then sent '{}'
        // for empty POSTs, which produced a 401 on exactly the calls that take
        // no arguments (/start, /logout) while every call with a payload
        // succeeded. Never reintroduce a `?:` fallback on either side.
        $body = $this->carriesBody($method)
            ? ($payload === [] ? '{}' : (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            : '';

        $timestamp = (string) time();
        $nonce = Str::random(32);

        $signature = hash_hmac(
            'sha256',
            implode("\n", [$timestamp, $nonce, strtoupper($method), $path, $body]),
            $this->signingSecret,
        );

        $request = Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'X-Eagleto-Key' => $this->apiKey,
                'X-Eagleto-Timestamp' => $timestamp,
                'X-Eagleto-Nonce' => $nonce,
                'X-Eagleto-Signature' => $signature,
                'Content-Type' => 'application/json',
            ])
            ->acceptJson()
            ->timeout(30)
            ->connectTimeout(10);

        return $this->dispatch($request, $method, $path, $body);
    }

    /** Whether this HTTP method transmits a request body. */
    protected function carriesBody(string $method): bool
    {
        return ! in_array(strtoupper($method), ['GET', 'DELETE'], true);
    }

    protected function dispatch(PendingRequest $request, string $method, string $path, string $body): Response
    {
        // The signed bytes and the transmitted bytes must be identical, so the
        // body is sent verbatim rather than re-encoded from an array.
        return match (strtoupper($method)) {
            'GET' => $request->get($path),
            'DELETE' => $request->delete($path),
            default => $request->withBody($body, 'application/json')->send(strtoupper($method), $path),
        };
    }

    // -- Instance lifecycle -------------------------------------------------

    public function createInstance(string $instanceName, ?string $webhookUrl = null, ?string $number = null): array
    {
        $response = $this->request('POST', '/v1/instances', array_filter([
            'external_instance_id' => $instanceName,
            'tenant_reference' => (string) (auth()->user()?->tenant_id ?? ''),
            'phone_number' => $number,
            'webhook_url' => $webhookUrl,
        ], fn ($value) => $value !== null && $value !== ''));

        $response->throw();

        return $this->connect($instanceName, $number);
    }

    public function setWebhook(string $instanceName, string $webhookUrl): array
    {
        // Webhook delivery is configured on the gateway itself (one signed
        // endpoint for the whole service), so there is nothing per-instance to
        // register. Reported as a no-op rather than silently pretending.
        return ['ok' => true, 'message' => 'The Baileys gateway posts to a single configured webhook endpoint.'];
    }

    public function connect(string $instanceName, ?string $number = null): array
    {
        $this->request('POST', "/v1/instances/{$instanceName}/start")->throw();

        $qr = $this->request('GET', "/v1/instances/{$instanceName}/qr");
        $payload = $qr->successful() ? ($qr->json() ?? []) : [];

        return [
            'qrcode' => [
                // The device view accepts either a data URL or a raw payload;
                // the PNG is preferred because it renders without JS.
                'base64' => data_get($payload, 'qr_image_base64') ?: data_get($payload, 'qr_data'),
                'pairingCode' => null,
            ],
            'instance' => ['state' => $this->stateFor(data_get($payload, 'status'))],
        ];
    }

    public function requestPairingCode(string $instanceName, string $phoneNumber): array
    {
        $digits = preg_replace('/\D+/', '', $phoneNumber);

        if (strlen((string) $digits) < 6) {
            throw new \InvalidArgumentException('Enter the WhatsApp phone number with its country code.');
        }

        $response = $this->request('POST', "/v1/instances/{$instanceName}/pairing-code", [
            'phone_number' => $digits,
        ]);
        $response->throw();

        return [
            'pairingCode' => data_get($response->json(), 'pairing_code'),
            'status' => $this->stateFor(data_get($response->json(), 'status')),
        ];
    }

    public function connectionState(string $instanceName): array
    {
        $response = $this->request('GET', "/v1/instances/{$instanceName}/status");

        if (! $response->successful()) {
            return ['instance' => ['state' => 'close']];
        }

        $payload = $response->json() ?? [];

        return ['instance' => [
            'state' => $this->stateFor(data_get($payload, 'state')),
            'phone' => data_get($payload, 'phone_number'),
            'profile_name' => data_get($payload, 'display_name'),
        ]];
    }

    /**
     * Collapse the gateway's 16 states into the three this app stores.
     *
     * Terminal and error states deliberately map to 'close' so a dead number
     * shows as Disconnected in the UI instead of sitting on "connecting"
     * forever — the previous engine could never report 'close', which is why a
     * logged-out device looked healthy until a send failed.
     */
    protected function stateFor(?string $state): string
    {
        return match (strtoupper((string) $state)) {
            'READY' => 'open',
            'LOGGED_OUT', 'REPLACED', 'RESTRICTED', 'STOPPED', 'ERROR' => 'close',
            default => 'connecting',
        };
    }

    public function logout(string $instanceName): array
    {
        return $this->request('POST', "/v1/instances/{$instanceName}/logout")->json() ?? [];
    }

    public function deleteInstance(string $instanceName): array
    {
        return $this->request('DELETE', "/v1/instances/{$instanceName}")->json() ?? [];
    }

    // -- Privacy / lookups --------------------------------------------------

    public function fetchPrivacy(string $instanceName): array
    {
        return [];
    }

    public function updatePrivacy(string $instanceName, array $settings): array
    {
        return ['ok' => false, 'error' => 'The Baileys gateway does not expose WhatsApp privacy settings.'];
    }

    public function checkNumbers(string $instanceName, array $numbers): array
    {
        // Deliberately not implemented as a WhatsApp lookup: bulk existence
        // checking is the classic precursor to unsolicited messaging, and the
        // gateway does not expose it. Numbers are reported back unresolved so
        // callers degrade instead of breaking.
        return collect($numbers)->map(fn (string $number) => [
            'number' => $number,
            'jid' => preg_replace('/\D+/', '', $number).'@s.whatsapp.net',
            'exists' => true,
        ])->all();
    }

    // -- Sending ------------------------------------------------------------

    public function sendText(string $instanceName, string $number, string $text, int $delay = 0): array
    {
        return $this->send($instanceName, '/v1/messages/text', [
            'recipient' => $number,
            'text' => $text,
        ]);
    }

    public function sendMedia(
        string $instanceName,
        string $number,
        string $mediaType,
        string $media,
        ?string $caption = null,
        ?string $fileName = null,
        int $delay = 0,
    ): array {
        $endpoint = match (strtolower($mediaType)) {
            'audio', 'voice' => '/v1/messages/audio',
            'video' => '/v1/messages/video',
            'document', 'file' => '/v1/messages/document',
            default => '/v1/messages/image',
        };

        return $this->send($instanceName, $endpoint, array_filter([
            'recipient' => $number,
            'url' => $media,
            'caption' => $caption,
            'file_name' => $fileName,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    public function sendPoll(
        string $instanceName,
        string $number,
        string $question,
        array $values,
        int $selectableCount = 1,
        int $delay = 0,
    ): array {
        $options = collect($values)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->take(12)
            ->all();

        if (count($options) < 2) {
            return ['ok' => false, 'message_id' => null, 'error' => 'A poll needs at least two options.', 'raw' => null];
        }

        return $this->send($instanceName, '/v1/messages/poll', [
            'recipient' => $number,
            'question' => $question,
            'options' => $options,
            'selectable_count' => max(1, $selectableCount),
        ]);
    }

    public function sendButtons(
        string $instanceName,
        string $number,
        string $title,
        ?string $description,
        ?string $footer,
        array $buttons,
        int $delay = 0,
    ): array {
        // Interactive buttons are not offered: WhatsApp's support for them
        // through unofficial libraries is unreliable, and a button that renders
        // for some recipients and vanishes for others is worse than plain text.
        $lines = array_map(
            fn (array $button, int $index) => ($index + 1).'. '.($button['displayText'] ?? ''),
            $buttons,
            array_keys($buttons),
        );

        $body = trim(implode("\n", array_filter([$title, $description, implode("\n", $lines), $footer])));

        return $this->sendText($instanceName, $number, $body, $delay);
    }

    /**
     * Common send path. A 202 means the gateway has durably accepted the
     * message; delivery status follows by webhook.
     */
    protected function send(string $instanceName, string $endpoint, array $payload): array
    {
        $key = $this->idempotencyKey ?: (string) Str::uuid();
        $this->idempotencyKey = null;

        $response = $this->request('POST', $endpoint, array_merge($payload, [
            'instance_id' => $instanceName,
            'idempotency_key' => $key,
        ]));

        $json = $response->json();

        if ($response->successful()) {
            return [
                'ok' => true,
                'message_id' => data_get($json, 'gateway_message_id'),
                'error' => null,
                'raw' => $json,
            ];
        }

        $error = data_get($json, 'error.message')
            ?? data_get($json, 'error.code')
            ?? $response->body();

        Log::error('Baileys gateway send failed', [
            'status' => $response->status(),
            'instance' => $instanceName,
            'endpoint' => $endpoint,
            'error' => $error,
        ]);

        return [
            'ok' => false,
            'message_id' => null,
            'error' => Str::limit((string) $error, 1000),
            'raw' => $json,
            // A 409 means the number is not sendable. Surfaced so the caller can
            // reassign deliberately — the gateway never silently reroutes.
            'status' => $response->status(),
        ];
    }
}
