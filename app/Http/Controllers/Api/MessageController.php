<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappInstance;
use App\Services\EvolutionApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    /**
     * Send a single WhatsApp text message from one of the workspace's devices.
     */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'integer'],
            'phone'     => ['required', 'string', 'max:32'],
            'message'   => ['required', 'string', 'max:4096'],
        ]);

        $device = WhatsappInstance::find($data['device_id']); // scoped to the token's workspace

        if (! $device) {
            return response()->json(['message' => 'Device not found.'], 404);
        }
        if (! $device->isConnected()) {
            return response()->json(['message' => 'Device is not connected.'], 422);
        }

        $result = EvolutionApiService::forInstance($device)->sendText(
            $device->instance_name,
            preg_replace('/\D+/', '', $data['phone']),
            $data['message'],
        );

        return response()->json(
            $result['ok']
                ? ['ok' => true, 'message_id' => $result['message_id']]
                : ['ok' => false, 'error' => $result['error']],
            $result['ok'] ? 200 : 422,
        );
    }
}
