<?php

namespace Tests\Feature;

use App\Services\BaileysGatewayDriver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The gateway rejects any request whose signature does not cover the exact
 * bytes it received. A driver that signs one thing and sends another fails
 * with a bare 401 that looks like a wrong key — which is precisely how this
 * bug presented in production: every call carrying a payload worked, while
 * the argument-less ones (/start, /logout) failed, so "adding a device"
 * broke while a connection check passed.
 *
 * These tests recompute the HMAC over the body actually transmitted, so they
 * fail if the two ever diverge again.
 */
class BaileysGatewaySigningTest extends TestCase
{
    private const SECRET = 'test-signing-secret';

    private function driver(): BaileysGatewayDriver
    {
        return new BaileysGatewayDriver('http://gateway.test', 'test-api-key', self::SECRET);
    }

    /** Recompute the signature the gateway would compute from what it received. */
    private function assertSignatureCoversSentBody(Request $request, string $method, string $path): void
    {
        $expected = hash_hmac('sha256', implode("\n", [
            $request->header('X-Eagleto-Timestamp')[0],
            $request->header('X-Eagleto-Nonce')[0],
            $method,
            $path,
            $request->body(),
        ]), self::SECRET);

        $this->assertSame(
            $expected,
            $request->header('X-Eagleto-Signature')[0],
            "The signature does not cover the body actually sent for {$method} {$path}.",
        );
    }

    public function test_post_without_a_payload_signs_the_body_it_sends(): void
    {
        Http::fake(['*' => Http::response(['status' => 'STARTING'], 202)]);

        // connect() issues POST /start with no payload — the exact shape that
        // was signing '' while sending '{}'.
        $this->driver()->connect('device-1');

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), '/start')) {
                return true;
            }

            $this->assertSame('{}', $request->body(), 'An empty POST must transmit {} so it can be signed.');
            $this->assertSignatureCoversSentBody($request, 'POST', '/v1/instances/device-1/start');

            return true;
        });
    }

    public function test_post_with_a_payload_signs_the_body_it_sends(): void
    {
        Http::fake(['*' => Http::response(['gateway_message_id' => 'gw_1'], 202)]);

        $this->driver()->sendText('device-1', '971500000000', 'Hello');

        Http::assertSent(function (Request $request) {
            $this->assertNotSame('', $request->body());
            $this->assertSignatureCoversSentBody($request, 'POST', '/v1/messages/text');

            return true;
        });
    }

    public function test_get_signs_an_empty_body(): void
    {
        Http::fake(['*' => Http::response(['state' => 'READY'], 200)]);

        $this->driver()->connectionState('device-1');

        Http::assertSent(function (Request $request) {
            $this->assertSame('', $request->body(), 'A GET must not transmit a body.');
            $this->assertSignatureCoversSentBody($request, 'GET', '/v1/instances/device-1/status');

            return true;
        });
    }

    public function test_delete_signs_an_empty_body(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->driver()->deleteInstance('device-1');

        Http::assertSent(function (Request $request) {
            $this->assertSame('', $request->body());
            $this->assertSignatureCoversSentBody($request, 'DELETE', '/v1/instances/device-1');

            return true;
        });
    }

    public function test_every_request_carries_the_four_auth_headers(): void
    {
        Http::fake(['*' => Http::response(['state' => 'READY'], 200)]);

        $this->driver()->connectionState('device-1');

        Http::assertSent(function (Request $request) {
            foreach (['X-Eagleto-Key', 'X-Eagleto-Timestamp', 'X-Eagleto-Nonce', 'X-Eagleto-Signature'] as $header) {
                $this->assertTrue($request->hasHeader($header), "Missing {$header}.");
            }

            return true;
        });
    }

    public function test_nonce_is_not_reused_between_requests(): void
    {
        Http::fake(['*' => Http::response(['state' => 'READY'], 200)]);

        $driver = $this->driver();
        $driver->connectionState('device-1');
        $driver->connectionState('device-1');

        $nonces = [];

        Http::assertSent(function (Request $request) use (&$nonces) {
            $nonces[] = $request->header('X-Eagleto-Nonce')[0];

            return true;
        });

        // A replayed nonce is rejected by the gateway as a replay attack.
        $this->assertCount(2, array_unique($nonces), 'Each request must carry a fresh nonce.');
    }
}
