<?php

namespace Tests;

use App\Models\WhatsappInstance;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /** Base URL every faked gateway call is made against. */
    protected string $gatewayBaseUrl = 'http://gateway.test/api';

    /**
     * Point the app at a fake OpenWA gateway and stub its HTTP surface.
     *
     * The driver resolves a session *name* (the device's instance_name) to the
     * gateway's immutable UUID via `GET /sessions` before every call, so that
     * lookup is always answered from the devices currently in the database.
     * Anything else falls through to $handler, then to a generic success.
     *
     * @param  null|callable(\Illuminate\Http\Client\Request): (\Illuminate\Http\Client\Response|null)  $handler
     */
    protected function fakeGateway(?callable $handler = null): void
    {
        config([
            'whatsapp.base_url'       => $this->gatewayBaseUrl,
            'whatsapp.api_key'        => 'k',
            'whatsapp.session_id'     => 'default',
            'whatsapp.webhook_secret' => null,
        ]);

        Http::fake(function ($request) use ($handler) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            // Session directory: name -> UUID. Never let a test's own stub
            // shadow this, or the driver cannot resolve any device.
            if ($request->method() === 'GET' && str_ends_with($path, '/sessions')) {
                return Http::response($this->gatewaySessions(), 200);
            }

            if ($handler !== null && ($response = $handler($request)) !== null) {
                return $response;
            }

            return Http::response(['messageId' => 'MSG-1', 'status' => 'ready'], 200);
        });
    }

    /** Every device in the database, as the gateway's session list. */
    protected function gatewaySessions(): array
    {
        return WhatsappInstance::withoutGlobalScopes()
            ->pluck('instance_name')
            ->filter()
            ->map(fn (string $name) => [
                'id'     => $this->gatewaySessionId($name),
                'name'   => $name,
                'status' => 'CONNECTED',
            ])
            ->values()
            ->all();
    }

    /** The gateway UUID this suite hands out for a session name. */
    protected function gatewaySessionId(string $instanceName): string
    {
        return 'sess-'.$instanceName;
    }

    /** Path of a send endpoint for a device, as the driver builds it. */
    protected function gatewaySendUrl(string $instanceName, string $endpoint): string
    {
        return '/sessions/'.$this->gatewaySessionId($instanceName).'/messages/'.$endpoint;
    }
}
