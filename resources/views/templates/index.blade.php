<x-app-layout>
    <x-slot name="header">Templates</x-slot>

    <x-card flush x-data="bulkSelect(@js($templates->pluck('id')->values()->all()))">
        <div class="flex items-center gap-3 px-5 py-4 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800">Message templates</h2>
            <x-btn :href="route('templates.create')" variant="primary" class="ml-auto">New template</x-btn>
        </div>
        <div class="px-5 py-3 border-b border-gray-100 bg-gray-50/50">
            <x-filter-bar search="Search templates…" :filters="[
                'type' => ['all' => 'All Types', 'options' => ['text' => 'Text', 'media' => 'Media', 'poll' => 'Poll', 'buttons' => 'Buttons', 'carousel' => 'Carousel']],
            ]" />
        </div>

        <x-bulk-bar :action="route('templates.bulk')">
            <button type="button" @click="run('delete', { confirm: 'Delete %d template(s)? This cannot be undone.' })" class="px-3 py-1.5 rounded-lg bg-red-600 text-white text-sm hover:bg-red-700">Delete</button>
        </x-bulk-bar>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-left">
                    <tr>
                        <th class="px-5 py-3 w-10"><x-bulk-check /></th>
                        <th class="px-5 py-3 font-medium">Name</th>
                        <th class="px-5 py-3 font-medium">Type</th>
                        <th class="px-5 py-3 font-medium">Preview</th>
                        <th class="px-5 py-3 font-medium text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($templates as $template)
                        <tr class="hover:bg-gray-50" :class="selected.includes({{ $template->id }}) && 'bg-brand/5'">
                            <td class="px-5 py-3"><x-bulk-check :id="$template->id" /></td>
                            <td class="px-5 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $template->name }}</td>
                            <td class="px-5 py-3">
                                @php $tc = ['text' => 'gray', 'media' => 'blue', 'poll' => 'purple', 'buttons' => 'green', 'carousel' => 'yellow'][$template->type] ?? 'gray'; @endphp
                                <x-badge :color="$tc">{{ ucfirst($template->type) }}</x-badge>
                            </td>
                            <td class="px-5 py-3 text-gray-600 max-w-md truncate">
                                @if ($template->type === 'poll')
                                    {{ data_get($template->poll, 'question') }} ({{ count(data_get($template->poll, 'options', [])) }} options)
                                @elseif ($template->type === 'carousel')
                                    {{ count($template->cards ?? []) }} card(s)
                                @elseif ($template->type === 'buttons')
                                    {{ Str::limit($template->body, 40) }} · {{ count(data_get($template->buttons, 'items', [])) }} button(s)
                                @else
                                    {{ Str::limit($template->body, 60) ?: '—' }}
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-1.5 justify-end">
                                    {{-- Preview --}}
                                    <a href="{{ route('templates.preview', $template) }}" title="Preview"
                                       class="grid place-items-center w-8 h-8 rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-700">
                                        <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    </a>
                                    {{-- Edit --}}
                                    <a href="{{ route('templates.edit', $template) }}" title="Edit"
                                       class="grid place-items-center w-8 h-8 rounded-lg text-green-600 hover:bg-green-50">
                                        <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    </a>
                                    {{-- Clone --}}
                                    <form method="POST" action="{{ route('templates.clone', $template) }}">
                                        @csrf
                                        <button type="submit" title="Duplicate" class="grid place-items-center w-8 h-8 rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-700">
                                            <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                        </button>
                                    </form>
                                    {{-- Delete --}}
                                    <form method="POST" action="{{ route('templates.destroy', $template) }}" onsubmit="return confirm('Delete this template?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" title="Delete" class="grid place-items-center w-8 h-8 rounded-lg text-red-600 hover:bg-red-50">
                                            <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-10 text-center text-gray-500">No templates yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($templates->hasPages())
            <div class="px-5 py-3 border-t border-gray-100">{{ $templates->links() }}</div>
        @endif
    </x-card>
</x-app-layout>
