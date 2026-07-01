@props(['action'])

{{-- Bulk action bar. Shows when rows are ticked; slot holds the action buttons. --}}
<div x-show="selected.length" x-cloak class="flex items-center gap-3 px-5 py-3 border-b border-gray-100 bg-brand/5 flex-wrap">
    <span class="text-sm font-medium text-gray-700"><span x-text="selected.length"></span> selected</span>
    <form method="POST" action="{{ $action }}" x-ref="bulkForm" class="flex items-center gap-2 flex-wrap ml-auto">
        @csrf
        <input type="hidden" name="action" x-ref="bulkAction">
        <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
        {{ $slot }}
        <button type="button" @click="clear()" class="text-sm text-gray-500 hover:text-gray-700 px-2">Clear</button>
    </form>
</div>
