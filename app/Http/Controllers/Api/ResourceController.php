<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\WhatsappInstance;
use Illuminate\Http\JsonResponse;

class ResourceController extends Controller
{
    public function devices(): JsonResponse
    {
        $devices = WhatsappInstance::get(['id', 'name', 'status', 'phone_number', 'connected_at']);

        return response()->json(['data' => $devices]);
    }

    public function campaigns(): JsonResponse
    {
        $campaigns = Campaign::latest()
            ->paginate(50, ['id', 'name', 'type', 'status', 'total', 'sent', 'failed', 'created_at']);

        return response()->json($campaigns);
    }
}
