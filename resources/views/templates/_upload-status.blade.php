{{-- Shared upload status (uses the templateEditor Alpine state): progress bar while
     uploading, the file name on success, the real error on failure. --}}
<div x-show="uploading || uploadName || uploadError" x-cloak class="mt-1.5 text-[11px]">
    <div x-show="uploading" class="flex items-center gap-2">
        <div class="flex-1 h-1.5 rounded-full bg-gray-200 overflow-hidden">
            <div class="h-full bg-brand transition-all" :style="`width:${uploadProgress}%`"></div>
        </div>
        <span class="text-gray-500 shrink-0" x-text="'Uploading… ' + uploadProgress + '%'"></span>
    </div>
    <p x-show="!uploading && uploadName && !uploadError" class="text-green-600 flex items-center gap-1">
        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        <span class="truncate" x-text="uploadName"></span>
    </p>
    <p x-show="uploadError" x-cloak class="text-red-600" x-text="uploadError"></p>
</div>
