<x-app-layout>
    <x-slot name="header">Templates</x-slot>

    <x-card flush>
        <div class="flex items-center gap-3 px-5 py-4 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800">Message templates</h2>
            <x-btn :href="route('templates.create')" variant="primary" class="ml-auto">New template</x-btn>
        </div>
        <div class="px-5 py-3 border-b border-gray-100 bg-gray-50/50">
            <x-filter-bar search="Search templates…" :filters="[
                'type' => ['all' => 'All Types', 'options' => ['text' => 'Text', 'media' => 'Media', 'poll' => 'Poll', 'buttons' => 'Buttons', 'carousel' => 'Carousel']],
            ]" />
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-left">
                    <tr>
                        <th class="px-5 py-3 font-medium">Name</th>
                        <th class="px-5 py-3 font-medium">Type</th>
                        <th class="px-5 py-3 font-medium">Preview</th>
                        <th class="px-5 py-3 font-medium text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($templates as $template)
                        <tr class="hover:bg-gray-50">
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
                                <div class="flex items-center gap-3 justify-end">
                                    <a href="{{ route('templates.edit', $template) }}" class="text-green-600 hover:text-green-700">Edit</a>
                                    <form method="POST" action="{{ route('templates.clone', $template) }}">
                                        @csrf
                                        <button class="text-gray-500 hover:text-gray-700">Clone</button>
                                    </form>
                                    <form method="POST" action="{{ route('templates.destroy', $template) }}" onsubmit="return confirm('Delete this template?')">
                                        @csrf @method('DELETE')
                                        <button class="text-red-600 hover:text-red-700">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-5 py-10 text-center text-gray-500">No templates yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($templates->hasPages())
            <div class="px-5 py-3 border-t border-gray-100">{{ $templates->links() }}</div>
        @endif
    </x-card>
</x-app-layout>
