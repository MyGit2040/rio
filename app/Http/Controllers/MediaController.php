<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class MediaController extends Controller
{
    public function index(Request $request): View
    {
        $assets = MediaAsset::query()
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->input('q').'%'))
            ->when($request->input('kind') === 'image', fn ($query) => $query->where('mime', 'like', 'image/%'))
            ->when($request->input('kind') === 'file', fn ($query) => $query->where('mime', 'not like', 'image/%'))
            ->latest()
            ->paginate(24)
            ->withQueryString();

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

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:delete'],
            'ids'    => ['required', 'array', 'min:1'],
            'ids.*'  => ['integer'],
        ]);

        $assets = MediaAsset::whereIn('id', $data['ids'])->get();
        foreach ($assets as $asset) {
            Storage::disk('public')->delete($asset->path);
            $asset->delete();
        }

        return back()->with('success', $assets->count().' file(s) deleted.');
    }
}
