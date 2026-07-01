<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class MediaController extends Controller
{
    public function index(): View
    {
        $assets = MediaAsset::latest()->paginate(24);

        return view('media.index', compact('assets'));
    }

    public function store(Request $request): RedirectResponse
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

        return back()->with('success', 'File added to your media library.');
    }

    public function destroy(MediaAsset $asset): RedirectResponse
    {
        Storage::disk('public')->delete($asset->path);
        $asset->delete();

        return back()->with('success', 'File deleted.');
    }
}
