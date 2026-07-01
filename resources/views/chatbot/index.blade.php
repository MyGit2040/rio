<x-app-layout>
    <x-slot name="header">Chatbot</x-slot>

    <x-card flush>
        <div class="flex items-center gap-3 px-5 py-4 border-b border-gray-100 flex-wrap">
            <div>
                <h2 class="font-semibold text-gray-800">Auto-reply rules</h2>
                <p class="text-sm text-gray-500">Replies are evaluated top to bottom by priority. First match wins.</p>
            </div>
            <x-btn :href="route('chatbot.create')" variant="primary" class="ml-auto">New rule</x-btn>
        </div>
        <div class="px-5 py-3 border-b border-gray-100 bg-gray-50/50">
            <x-filter-bar search="Search rules…" :filters="[
                'status' => ['all' => 'All', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
            ]" />
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-left">
                    <tr>
                        <th class="px-5 py-3 font-medium">Priority</th>
                        <th class="px-5 py-3 font-medium">Rule</th>
                        <th class="px-5 py-3 font-medium">Trigger</th>
                        <th class="px-5 py-3 font-medium">Reply</th>
                        <th class="px-5 py-3 font-medium">Active</th>
                        <th class="px-5 py-3 font-medium text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rules as $rule)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3 text-gray-600">{{ $rule->priority }}</td>
                            <td class="px-5 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $rule->name }}</td>
                            <td class="px-5 py-3 text-gray-600">
                                @if ($rule->match_type === 'any')
                                    <x-badge color="blue">Any message</x-badge>
                                @elseif ($rule->match_type === 'ai')
                                    <x-badge color="purple">AI</x-badge>
                                @else
                                    <span class="text-xs">{{ ucfirst(str_replace('_', ' ', $rule->match_type)) }}: <span class="text-gray-800">{{ $rule->keywords }}</span></span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-gray-600 max-w-xs truncate">
                                @if ($rule->use_ai)<x-badge color="purple">AI reply</x-badge>@else {{ Str::limit($rule->reply, 50) }} @endif
                            </td>
                            <td class="px-5 py-3">
                                @if ($rule->is_active)<x-badge color="green">On</x-badge>@else<x-badge color="gray">Off</x-badge>@endif
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3 justify-end">
                                    <a href="{{ route('chatbot.edit', $rule) }}" class="text-green-600 hover:text-green-700">Edit</a>
                                    <form method="POST" action="{{ route('chatbot.destroy', $rule) }}" onsubmit="return confirm('Delete this rule?')">
                                        @csrf @method('DELETE')
                                        <button class="text-red-600 hover:text-red-700">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-gray-500">No chatbot rules yet. Add one to auto-reply to incoming messages.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <p class="text-xs text-gray-500 mt-4">AI replies use your OpenAI key (set in <a href="{{ route('settings.edit') }}" class="text-green-600">Settings</a>). Inbound messages require the webhook to be reachable by the engine.</p>
</x-app-layout>
