<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UploadController extends Controller
{
    /**
     * Store an uploaded attachment on the public disk and return its URL.
     * The URL is what campaigns hand to Evolution (which fetches it to send).
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:jpg,jpeg,png,webp,gif,mp4,mov,pdf,doc,docx,xls,xlsx,mp3,ogg,m4a'],
        ]);

        $tenantId = auth()->user()->tenant_id;
        $path = $request->file('file')->store("uploads/{$tenantId}", 'public');

        return response()->json([
            'url'  => asset('storage/'.$path),
            'name' => $request->file('file')->getClientOriginalName(),
        ]);
    }
}
