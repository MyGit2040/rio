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
        $qr = $this->http()->get("/sessions/{$sessionId}/qr")->throw()->json() ?? [];
        $state = $this->connectionState($instanceName);

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

        return ['instance' => ['state' => in_array($status, ['CONNECTED', 'READY', 'OPEN'], true) ? 'open' : 'connecting']];
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

        return collect($numbers)->map(function (string $number) use ($instanceName) {
            $jid = $this->jid($number);
            $json = $this->http()->get('/sessions/'.$this->sessionId($instanceName)."/contacts/{$jid}")->throw()->json() ?? [];
            $data = $json['data'] ?? $json;

            return ['number' => $number, 'jid' => $jid, 'exists' => (bool) ($data['canReceiveMessage'] ?? $data['exists'] ?? false)];
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
        if ($response->status() === 500 && $this->persistedTextMessage($sessionId, $chatId, $text)) {
            return ['ok' => true, 'message_id' => null, 'error' => null, 'raw' => $response->json()];
        }

        return $this->result($response);
    }

    public function sendMedia(string $instanceName, string $number, string $mediaType, string $media, ?string $caption = null, ?string $fileName = null, int $delay = 0): array
    {
        $this->assertSession($instanceName);

        return $this->result($this->http()->post('/sessions/'.$this->sessionId($instanceName).'/messages/send-media', array_filter([
            'chatId' => $this->jid($number),
            'url' => $media,
            'filename' => $fileName ?: 'attachment',
            'caption' => $caption,
        ], fn ($value) => $value !== null && $value !== '')));
    }

    public function sendPoll(string $instanceName, string $number, string $question, array $values, int $selectableCount = 1, int $delay = 0): array
    {
        return $this->sendText($instanceName, $number, $question."\n".implode("\n", array_map(fn ($value, $index) => ($index + 1).'. '.$value, $values, array_keys($values))), $delay);
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

    private function persistedTextMessage(string $sessionId, string $chatId, string $text): bool
    {
        $response = $this->http()->get("/sessions/{$sessionId}/messages", ['chatId' => $chatId, 'limit' => 10]);

        if (! $response->successful()) {
            return false;
        }

        return collect(data_get($response->json(), 'messages', []))->contains(
            fn (array $message) => ($message['chatId'] ?? null) === $chatId && ($message['body'] ?? null) === $text,
        );
    }

    private function result(Response $response): array
    {
        $json = $response->json();
        $data = $json['data'] ?? $json;

        if ($response->successful() && ($json['success'] ?? true)) {
            return ['ok' => true, 'message_id' => data_get($data, '_serialized') ?? data_get($data, 'id') ?? (is_string($data) ? $data : null), 'error' => null, 'raw' => $json];
        }

        $error = is_array($json) ? json_encode($json['error'] ?? $json['details'] ?? $json) : $response->body();
        Log::warning('OpenWA send failed', ['status' => $response->status(), 'error' => $error]);

        return ['ok' => false, 'message_id' => null, 'error' => mb_substr((string) $error, 0, 1000), 'raw' => $json];
    }
}
