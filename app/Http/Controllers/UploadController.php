<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UploadController extends Controller
{
    /**
     * Store an uploaded attachment on the public disk and return its URL.
     * The URL is what campaigns hand to OpenWA (which fetches it to send).
     * Every upload is also filed in the reusable Media Library.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:jpg,jpeg,png,webp,gif,mp4,mov,pdf,doc,docx,xls,xlsx,mp3,ogg,m4a'],
        ]);

        $tenantId = auth()->user()->tenant_id;
        $file = $request->file('file');
        $path = $file->store("uploads/{$tenantId}", 'public');

        MediaAsset::create([
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        return response()->json([
            'url'  => asset('storage/'.$path),
            'name' => $file->getClientOriginalName(),
        ]);
    }
}
