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
        return static::forTenant($instance->tenant);
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

        $this->http()->post('/sessions', ['name' => $instanceName])->throw();

        if ($webhookUrl) {
            $this->setWebhook($instanceName, $webhookUrl);
        }

        return $this->connect($instanceName, $number);
    }

    public function setWebhook(string $instanceName, string $webhookUrl): array
    {
        $this->assertSession($instanceName);

        return $this->http()->post("/sessions/{$instanceName}/webhooks", [
            'url' => $webhookUrl,
            'events' => ['message.received', 'message.status', 'session.status'],
        ])->throw()->json() ?? [];
    }

    public function connect(string $instanceName, ?string $number = null): array
    {
        $this->assertSession($instanceName);
        $this->http()->post("/sessions/{$instanceName}/start")->throw();
        $qr = $this->http()->get("/sessions/{$instanceName}/qr")->throw()->json() ?? [];
        $state = $this->connectionState($instanceName);

        return [
            'qrcode' => ['base64' => data_get($qr, 'qr') ?? data_get($qr, 'qrCode') ?? data_get($qr, 'data.qr'), 'pairingCode' => null],
            'instance' => ['state' => data_get($state, 'instance.state', 'connecting')],
        ];
    }

    public function connectionState(string $instanceName): array
    {
        $this->assertSession($instanceName);
        $session = $this->http()->get("/sessions/{$instanceName}")->json() ?? [];
        $status = strtoupper((string) (data_get($session, 'status') ?? data_get($session, 'data.status') ?? ''));

        return ['instance' => ['state' => in_array($status, ['CONNECTED', 'READY', 'OPEN'], true) ? 'open' : 'connecting']];
    }

    public function logout(string $instanceName): array
    {
        $this->assertSession($instanceName);

        return $this->http()->post("/sessions/{$instanceName}/stop")->throw()->json() ?? [];
    }

    public function deleteInstance(string $instanceName): array
    {
        $this->assertSession($instanceName);

        return $this->http()->delete("/sessions/{$instanceName}")->throw()->json() ?? [];
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

        return collect($numbers)->map(function (string $number) {
            $jid = $this->jid($number);
            $json = $this->http()->get("/sessions/{$this->sessionId}/contacts/{$jid}")->throw()->json() ?? [];
            $data = $json['data'] ?? $json;

            return ['number' => $number, 'jid' => $jid, 'exists' => (bool) ($data['canReceiveMessage'] ?? $data['exists'] ?? false)];
        })->all();
    }

    public function sendText(string $instanceName, string $number, string $text, int $delay = 0): array
    {
        $this->assertSession($instanceName);

        return $this->result($this->http()->post("/sessions/{$instanceName}/messages/send-text", [
            'chatId' => $this->jid($number),
            'text' => $text,
        ]));
    }

    public function sendMedia(string $instanceName, string $number, string $mediaType, string $media, ?string $caption = null, ?string $fileName = null, int $delay = 0): array
    {
        $this->assertSession($instanceName);

        return $this->result($this->http()->post("/sessions/{$instanceName}/messages/send-media", array_filter([
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
