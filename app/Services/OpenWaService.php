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
 * OpenWA Easy API driver. OpenWA owns its named session lifecycle; this driver
 * attaches Eagle to that running session rather than attempting to create it.
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

        return $this->connect($instanceName, $number);
    }

    public function setWebhook(string $instanceName, string $webhookUrl): array
    {
        $this->assertSession($instanceName);

        // OpenWA v5's CLI webhook registration is not yet restored. Inbound
        // events can be consumed from its authenticated SSE endpoint instead.
        return ['ok' => true, 'unsupported' => true];
    }

    public function connect(string $instanceName, ?string $number = null): array
    {
        $this->assertSession($instanceName);
        $health = $this->http()->get('/health')->throw()->json() ?? [];
        return [
            // OpenWA exposes QR data as a token, not a PNG/base64 image. Its
            // terminal/dashboard renders that token locally for scanning.
            'qrcode' => ['base64' => $health['qr'] ?? null, 'pairingCode' => null],
            'instance' => ['state' => ($health['connected'] ?? false) ? 'open' : 'connecting'],
        ];
    }

    public function connectionState(string $instanceName): array
    {
        $this->assertSession($instanceName);
        $health = $this->http()->get('/health')->json() ?? [];

        return ['instance' => ['state' => ($health['connected'] ?? false) ? 'open' : 'close']];
    }

    public function logout(string $instanceName): array
    {
        $this->assertSession($instanceName);

        // Easy API has no logout endpoint in v5; session removal belongs to the
        // OpenWA runtime administrator so a UI delete cannot destroy its data.
        return ['ok' => true, 'unsupported' => true];
    }

    public function deleteInstance(string $instanceName): array
    {
        $this->assertSession($instanceName);

        return ['ok' => true, 'unsupported' => true];
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
            $json = $this->http()->get('/api/contacts/checkNumberStatus', ['contactId' => $jid])->throw()->json() ?? [];
            $data = $json['data'] ?? $json;

            return ['number' => $number, 'jid' => $jid, 'exists' => (bool) ($data['canReceiveMessage'] ?? $data['exists'] ?? false)];
        })->all();
    }

    public function sendText(string $instanceName, string $number, string $text, int $delay = 0): array
    {
        $this->assertSession($instanceName);

        return $this->result($this->http()->post('/api/messages/sendText', [
            'to' => $this->jid($number),
            'content' => $text,
        ]));
    }

    public function sendMedia(string $instanceName, string $number, string $mediaType, string $media, ?string $caption = null, ?string $fileName = null, int $delay = 0): array
    {
        $this->assertSession($instanceName);

        return $this->result($this->http()->post('/api/messages/sendFileFromUrl', array_filter([
            'to' => $this->jid($number),
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
