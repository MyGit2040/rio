<?php

namespace App\Services;

use App\Contracts\WhatsappGateway;
use App\Models\Tenant;
use App\Models\WhatsappInstance;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Driver for the modern OpenWA gateway API.
 */
class OpenWaService implements WhatsappGateway
{
    public function __construct(
        protected string $baseUrl,
        protected string $apiKey,
        protected string $sessionId,
    ) {
    }

    public static function forTenant(?Tenant $tenant): static
    {
        return new static(
            rtrim((string) ($tenant?->openwa_base_url ?: config('openwa.base_url')), '/'),
            (string) ($tenant?->openwa_api_key ?: config('openwa.api_key')),
            (string) ($tenant?->openwa_session_id ?: config('openwa.session_id')),
        );
    }

    public static function forInstance(WhatsappInstance $instance): static
    {
        $tenantService = static::forTenant($instance->tenant);

        // The gateway can host more than one WhatsApp session. A linked device
        // must resolve its own session name, not the tenant's default session.
        return new static(
            $tenantService->baseUrl,
            $tenantService->apiKey,
            $instance->instance_name,
        );
    }

    public function configured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '' && $this->sessionId !== '';
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['X-API-Key' => $this->apiKey])
            ->acceptJson()
            ->timeout(60)
            ->connectTimeout(10);
    }

    public function createInstance(string $instanceName, ?string $webhookUrl = null, ?string $number = null): array
    {
        $this->assertSession($instanceName);

        $created = $this->http()->post('/sessions', ['name' => $instanceName]);
        if (! $created->successful() && $created->status() !== 409) {
            $created->throw();
        }

        return $this->connect($instanceName, $number);
    }

    public function setWebhook(string $instanceName, string $webhookUrl): array
    {
        // This OpenWA deployment configures webhooks when its runtime starts.
        // A guessed per-session request is rejected with HTTP 400.
        return [];
    }

    public function connect(string $instanceName, ?string $number = null): array
    {
        $this->assertSession($instanceName);
        $sessionId = $this->sessionId($instanceName);
        $started = $this->http()->post("/sessions/{$sessionId}/start");
        if (! $started->successful() && ! ($started->status() === 400 && str_contains($started->body(), 'already started'))) {
            $started->throw();
        }
        $state = $this->connectionState($instanceName);
        $isConnected = data_get($state, 'instance.state') === 'open';
        $qr = $isConnected ? [] : ($this->http()->get("/sessions/{$sessionId}/qr")->throw()->json() ?? []);

        return [
            'qrcode' => ['base64' => data_get($qr, 'qr') ?? data_get($qr, 'qrCode') ?? data_get($qr, 'data.qr'), 'pairingCode' => null],
            'instance' => ['state' => data_get($state, 'instance.state', 'connecting')],
        ];
    }

    public function connectionState(string $instanceName): array
    {
        $this->assertSession($instanceName);
        $session = $this->http()->get('/sessions/'.$this->sessionId($instanceName))->json() ?? [];
        $status = strtoupper((string) (data_get($session, 'status') ?? data_get($session, 'data.status') ?? ''));

        return ['instance' => [
            'state' => in_array($status, ['CONNECTED', 'READY', 'OPEN'], true) ? 'open' : 'connecting',
            'phone' => data_get($session, 'phone') ?? data_get($session, 'data.phone'),
            'profile_name' => data_get($session, 'pushName') ?? data_get($session, 'data.pushName'),
        ]];
    }

    public function logout(string $instanceName): array
    {
        $this->assertSession($instanceName);

        return $this->http()->post('/sessions/'.$this->sessionId($instanceName).'/stop')->throw()->json() ?? [];
    }

    public function deleteInstance(string $instanceName): array
    {
        $this->assertSession($instanceName);

        return $this->http()->delete('/sessions/'.$this->sessionId($instanceName))->throw()->json() ?? [];
    }

    public function fetchPrivacy(string $instanceName): array
    {
        return [];
    }

    public function updatePrivacy(string $instanceName, array $settings): array
    {
        return ['ok' => false, 'error' => 'OpenWA Easy API does not expose privacy settings.'];
    }

    public function checkNumbers(string $instanceName, array $numbers): array
    {
        $this->assertSession($instanceName);
        $sessionId = $this->sessionId($instanceName);

        return collect($numbers)->map(function (string $number) use ($sessionId) {
            $clean = preg_replace('/\D+/', '', $number);
            $json = $this->http()->get("/sessions/{$sessionId}/contacts/check/{$clean}")->throw()->json() ?? [];

            return [
                'number' => $number,
                'jid' => data_get($json, 'whatsappId') ?: $this->jid($clean),
                'exists' => (bool) data_get($json, 'exists', false),
            ];
        })->all();
    }

    public function sendText(string $instanceName, string $number, string $text, int $delay = 0): array
    {
        $this->assertSession($instanceName);
        $chatId = $this->jid($number);
        $sessionId = $this->sessionId($instanceName);

        $response = $this->http()->post('/sessions/'.$sessionId.'/messages/send-text', [
            'chatId' => $chatId,
            'text' => $text,
        ]);

        // Some WhatsApp-Web builds persist a self-chat message successfully but
        // return 500 while trying to read its optional message id. Do not mark a
        // real delivery as failed: confirm the exact text exists in OpenWA first.
        if ($response->status() === 500 && $this->persistedTextMessage($sessionId, $text)) {
            return ['ok' => true, 'message_id' => null, 'error' => null, 'raw' => $response->json()];
        }

        return $this->result($response);
    }

    public function sendMedia(string $instanceName, string $number, string $mediaType, string $media, ?string $caption = null, ?string $fileName = null, int $delay = 0): array
    {
        $this->assertSession($instanceName);

        $endpoint = match (strtolower($mediaType)) {
            'audio', 'voice' => 'send-audio',
            'video' => 'send-video',
            'document', 'file' => 'send-document',
            default => 'send-image',
        };

        return $this->result($this->http()->post('/sessions/'.$this->sessionId($instanceName).'/messages/'.$endpoint, array_filter([
            'chatId' => $this->jid($number),
            'url' => $media,
            'filename' => $fileName ?: 'attachment',
            'caption' => $caption,
        ], fn ($value) => $value !== null && $value !== '')));
    }

    public function sendPoll(string $instanceName, string $number, string $question, array $values, int $selectableCount = 1, int $delay = 0): array
    {
        $this->assertSession($instanceName);

        $options = collect($values)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->take(12)
            ->all();

        if (count($options) < 2) {
            return ['ok' => false, 'message_id' => null, 'error' => 'A poll needs at least two options.', 'raw' => null];
        }

        $sessionId = $this->sessionId($instanceName);
        $response = $this->http()->post('/sessions/'.$sessionId.'/messages/send-poll', [
            'chatId' => $this->jid($number),
            'name' => mb_substr($question, 0, 255),
            'options' => $options,
            'allowMultipleAnswers' => $selectableCount > 1,
        ]);

        // The installed WhatsApp-Web adapter can persist a native poll then
        // throw while reading its optional message id. Verify persistence first.
        if ($response->status() === 500 && $this->persistedPollMessage($sessionId, $question)) {
            return ['ok' => true, 'message_id' => null, 'error' => null, 'raw' => $response->json()];
        }

        return $this->result($response);
    }

    public function sendButtons(string $instanceName, string $number, string $title, ?string $description, ?string $footer, array $buttons, int $delay = 0): array
    {
        $lines = array_map(fn (array $button, int $index) => ($index + 1).'. '.($button['displayText'] ?? ''), $buttons, array_keys($buttons));

        return $this->sendText($instanceName, $number, trim(implode("\n", array_filter([$title, $description, implode("\n", $lines), $footer]))), $delay);
    }

    private function assertSession(string $instanceName): void
    {
        if ($instanceName !== $this->sessionId) {
            throw new \RuntimeException('This device does not match the configured OpenWA session ID.');
        }
    }

    private function jid(string $number): string
    {
        return str_contains($number, '@') ? $number : preg_replace('/\D+/', '', $number).'@c.us';
    }

    private function sessionId(string $sessionName): string
    {
        $sessions = $this->http()->get('/sessions')->throw()->json() ?? [];
        $session = collect($sessions)->first(fn (array $item) => ($item['name'] ?? null) === $sessionName || ($item['id'] ?? null) === $sessionName);

        if (! $session || empty($session['id'])) {
            throw new \RuntimeException("OpenWA session '{$sessionName}' was not found.");
        }

        return (string) $session['id'];
    }

    private function persistedTextMessage(string $sessionId, string $text): bool
    {
        $response = $this->http()->get("/sessions/{$sessionId}/messages", ['limit' => 20]);

        if (! $response->successful()) {
            return false;
        }

        return collect(data_get($response->json(), 'messages', []))->contains(
            // OpenWA may resolve a phone's @c.us id to its internal @lid id.
            // The message body is the stable value after that conversion.
            fn (array $message) => ($message['body'] ?? null) === $text
                && ($message['direction'] ?? null) === 'outgoing',
        );
    }

    private function persistedPollMessage(string $sessionId, string $question): bool
    {
        $response = $this->http()->get("/sessions/{$sessionId}/messages", ['limit' => 20]);

        if (! $response->successful()) {
            return false;
        }

        return collect(data_get($response->json(), 'messages', []))->contains(
            fn (array $message) => ($message['type'] ?? null) === 'poll'
                // The gateway persists native poll text as "📊 <question>".
                && str_contains((string) ($message['body'] ?? ''), $question)
                && ($message['direction'] ?? null) === 'outgoing',
        );
    }

    private function result(Response $response): array
    {
        $json = $response->json();
        $data = $json['data'] ?? $json;

        if ($response->successful() && ($json['success'] ?? true)) {
            return ['ok' => true, 'message_id' => data_get($data, 'messageId') ?? data_get($data, '_serialized') ?? data_get($data, 'id') ?? (is_string($data) ? $data : null), 'error' => null, 'raw' => $json];
        }

        $error = is_array($json) ? json_encode($json['error'] ?? $json['details'] ?? $json) : $response->body();
        Log::warning('OpenWA send failed', ['status' => $response->status(), 'error' => $error]);

        return ['ok' => false, 'message_id' => null, 'error' => mb_substr((string) $error, 0, 1000), 'raw' => $json];
    }
}
