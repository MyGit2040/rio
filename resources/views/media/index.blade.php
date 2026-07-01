<x-app-layout>
    <x-slot name="header">Media library</x-slot>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <div class="lg:col-span-1">
            <x-card title="Upload">
                <form method="POST" action="{{ route('media.store') }}" enctype="multipart/form-data" class="space-y-3">
                    @csrf
                    <input type="file" name="file" required
                           class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-brand file:text-white file:text-sm">
                    <p class="text-xs text-gray-500">Images, video, audio or documents up to 20&nbsp;MB.</p>
                    <x-btn type="submit" variant="primary">Upload</x-btn>
                </form>
            </x-card>
        </div>

        <div class="lg:col-span-3">
            <x-card flush>
                <div class="px-5 py-4 border-b border-gray-100"><h2 class="font-semibold text-gray-800">Your files ({{ $assets->total() }})</h2></div>
                <div class="p-5">
                    @if ($assets->count())
                        <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-4" x-data>
                            @foreach ($assets as $asset)
                                <div class="rounded-xl border border-gray-200 overflow-hidden group">
                                    <div class="h-28 bg-gray-50 grid place-items-center overflow-hidden">
                                        @if ($asset->is_image)
                                            <img src="{{ $asset->url }}" alt="{{ $asset->name }}" class="w-full h-full object-cover">
                                        @else
                                            <svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        @endif
                                    </div>
                                    <div class="p-2">
                                        <p class="text-xs font-medium text-gray-700 truncate" title="{{ $asset->name }}">{{ $asset->name }}</p>
                                        <div class="flex items-center gap-2 mt-1">
                                            <button type="button" @click="navigator.clipboard.writeText('{{ $asset->url }}'); $el.textContent='Copied'"
                                                    class="text-[11px] text-green-600 hover:text-green-700">Copy URL</button>
                                            <form method="POST" action="{{ route('media.destroy', $asset) }}" class="ml-auto" onsubmit="return confirm('Delete this file?')">
                                                @csrf @method('DELETE')
                                                <button class="text-[11px] text-red-500 hover:text-red-600">Delete</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-5">{{ $assets->links() }}</div>
                    @else
                        <p class="text-center text-gray-500 py-10">No files yet. Upload one, or attachments you add to templates land here automatically.</p>
                    @endif
                </div>
            </x-card>
        </div>
    </div>
</x-app-layout>
