@props(['contact', 'group' => null])

{{-- Single ⋮ action button → View / Edit / (Remove from group) / Delete.
     Teleported to <body> so the menu never gets clipped by the table's scroll area. --}}
<div x-data="{ open: false, style: '' }" class="inline-block text-left">
    <button type="button" x-ref="btn" title="Actions"
            @click="const r = $refs.btn.getBoundingClientRect(); style = `top:${r.bottom + 4}px; left:${Math.max(8, r.right - 176)}px`; open = true"
            class="grid place-items-center w-8 h-8 rounded-lg text-gray-500 hover:bg-gray-100">
        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 5a2 2 0 110-4 2 2 0 010 4zm0 9a2 2 0 110-4 2 2 0 010 4zm0 9a2 2 0 110-4 2 2 0 010 4z"/></svg>
    </button>

    <template x-teleport="body">
        <div x-show="open" x-cloak>
            <div class="fixed inset-0 z-40" @click="open = false"></div>
            <div class="fixed z-50 w-44 bg-white rounded-lg shadow-lg border border-gray-100 py-1 text-sm" :style="style">
                <a href="{{ route('contacts.show', $contact) }}" class="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 text-gray-700">
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    View
                </a>
                <a href="{{ route('contacts.edit', $contact) }}" class="flex items-center gap-2 px-3 py-2 hover:bg-gray-50 text-gray-700">
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Edit
                </a>
                @if ($group)
                    <form method="POST" action="{{ route('groups.remove-contact', [$group, $contact]) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="flex items-center gap-2 w-full text-left px-3 py-2 hover:bg-gray-50 text-gray-700">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 12H6"/></svg>
                            Remove from group
                        </button>
                    </form>
                @endif
                <form method="POST" action="{{ route('contacts.destroy', $contact) }}" onsubmit="return confirm('Delete this contact permanently?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="flex items-center gap-2 w-full text-left px-3 py-2 hover:bg-red-50 text-red-600 border-t border-gray-100 mt-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        Delete
                    </button>
                </form>
            </div>
        </div>
    </template>
</div>
