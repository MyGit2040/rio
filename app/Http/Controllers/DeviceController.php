<?php

namespace App\Http\Controllers;

use App\Models\WhatsappInstance;
use App\Services\EvolutionApiService;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function index(): View
    {
        $devices = WhatsappInstance::latest()->get();
        $engine = EvolutionApiService::forTenant(auth()->user()->tenant);

        return view('devices.index', [
            'devices'        => $devices,
            'engineReady'    => $engine->configured(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'daily_limit' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $tenant = auth()->user()->tenant;
        $engine = EvolutionApiService::forTenant($tenant);

        if (! $engine->configured()) {
            return back()->with('error', 'Connect your Evolution engine in Settings before adding a device.');
        }

        $instanceName = Str::lower($tenant->slug.'-'.Str::random(8));

        $device = WhatsappInstance::create([
            'name'          => $data['name'],
            'instance_name' => $instanceName,
            'status'        => 'connecting',
            'daily_limit'   => (int) ($data['daily_limit'] ?? 0),
        ]);

        try {
            $response = $engine->createInstance($instanceName, $this->webhookUrl());

            $device->update([
                'token'   => data_get($response, 'hash.apikey') ?? data_get($response, 'hash') ?? null,
                'qr_code' => $this->extractQr($response),
                'status'  => 'connecting',
            ]);
        } catch (\Throwable $e) {
            Log::error('Evolution createInstance failed', ['error' => $e->getMessage()]);
            $device->delete();

            return back()->with('error', 'Could not reach the Evolution engine: '.$e->getMessage());
        }

        return redirect()->route('devices.index')
            ->with('success', 'Device created. Scan the QR code with WhatsApp to link it.');
    }

    /**
     * Request a fresh QR for an existing device.
     */
    public function connect(WhatsappInstance $device): JsonResponse
    {
        $engine = EvolutionApiService::forInstance($device);

        try {
            $response = $engine->connect($device->instance_name);
            $qr = $this->extractQr($response);
            $device->update(['qr_code' => $qr, 'status' => 'connecting']);

            return response()->json(['ok' => true, 'qr' => $qr]);
        } catch (\Throwable $e) {
            Log::error('Evolution connect failed', ['error' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Poll the engine for connection status (used by the QR screen).
     */
    public function state(WhatsappInstance $device): JsonResponse
    {
        $engine = EvolutionApiService::forInstance($device);
        $response = $engine->connectionState($device->instance_name);
        $state = data_get($response, 'instance.state', $device->status);

        $device->update([
            'status'       => $state,
            'qr_code'      => $state === 'open' ? null : $device->qr_code,
            'connected_at' => $state === 'open' ? ($device->connected_at ?? now()) : $device->connected_at,
        ]);

        return response()->json([
            'ok'     => true,
            'status' => $state,
        ]);
    }

    public function show(WhatsappInstance $device): View
    {
        $engine = EvolutionApiService::forInstance($device);
        $privacy = [];

        if ($engine->configured() && $device->isConnected()) {
            try {
                $privacy = $engine->fetchPrivacy($device->instance_name);
            } catch (\Throwable $e) {
                Log::warning('fetchPrivacy failed', ['error' => $e->getMessage()]);
            }
        }

        return view('devices.show', compact('device', 'privacy'));
    }

    public function update(Request $request, WhatsappInstance $device): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'daily_limit' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $device->update(['name' => $data['name'], 'daily_limit' => (int) ($data['daily_limit'] ?? 0)]);

        return back()->with('success', 'Device settings saved.');
    }

    public function updatePrivacy(Request $request, WhatsappInstance $device): RedirectResponse
    {
        $data = $request->validate([
            'last'         => ['required', 'in:all,contacts,none'],
            'profile'      => ['required', 'in:all,contacts,none'],
            'status'       => ['required', 'in:all,contacts,none'],
            'readreceipts' => ['required', 'in:all,none'],
            'online'       => ['required', 'in:all,match_last_seen'],
        ]);

        $engine = EvolutionApiService::forInstance($device);

        if (! $engine->configured() || ! $device->isConnected()) {
            return back()->with('error', 'The device must be connected to change privacy.');
        }

        $result = $engine->updatePrivacy($device->instance_name, $data);

        return $result['ok']
            ? back()->with('success', 'Privacy settings updated.')
            : back()->with('error', 'Could not update privacy: '.($result['error'] ?? 'unknown error'));
    }

    public function destroy(WhatsappInstance $device): RedirectResponse
    {
        $engine = EvolutionApiService::forInstance($device);

        try {
            $engine->logout($device->instance_name);
            $engine->deleteInstance($device->instance_name);
        } catch (\Throwable $e) {
            Log::warning('Evolution delete failed (continuing)', ['error' => $e->getMessage()]);
        }

        $device->delete();

        return redirect()->route('devices.index')->with('success', 'Device removed.');
    }

    private function webhookUrl(): ?string
    {
        $secret = config('evolution.webhook_secret');

        return $secret
            ? route('webhooks.evolution', ['secret' => $secret])
            : route('webhooks.evolution');
    }

    private function extractQr(array $response): ?string
    {
        $base64 = data_get($response, 'qrcode.base64')
            ?? data_get($response, 'base64')
            ?? null;

        if (! $base64) {
            return null;
        }

        return Str::startsWith($base64, 'data:') ? $base64 : 'data:image/png;base64,'.$base64;
    }
}
