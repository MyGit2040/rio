<x-app-layout>
    <x-slot name="header">Outbound webhooks</x-slot>

    <div class="mb-4">
        <a href="{{ route('settings.edit') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Settings</a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <x-card flush>
                <div class="px-5 py-4 border-b border-gray-100"><h2 class="font-semibold text-gray-800">Endpoints</h2></div>
                <ul class="divide-y divide-gray-100">
                    @forelse ($endpoints as $endpoint)
                        <li class="px-5 py-4">
                            <div class="flex items-center gap-3">
                                <div class="min-w-0">
                                    <p class="font-medium text-gray-800 truncate">{{ $endpoint->url }}</p>
                                    <div class="flex flex-wrap gap-1 mt-1">
                                        @foreach ($endpoint->events as $ev)<x-badge color="blue">{{ $ev }}</x-badge>@endforeach
                                    </div>
                                </div>
                                <span class="ml-auto"><x-badge :color="$endpoint->is_active ? 'green' : 'gray'">{{ $endpoint->is_active ? 'Active' : 'Off' }}</x-badge></span>
                                <form method="POST" action="{{ route('webhook-endpoints.destroy', $endpoint) }}" onsubmit="return confirm('Remove this endpoint?')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 hover:text-red-700 text-sm">Remove</button>
                                </form>
                            </div>
                            <p class="text-xs text-gray-400 mt-2">Secret: <code>{{ $endpoint->secret }}</code> · signed as <code>X-Eagle-Signature</code> (HMAC-SHA256)</p>
                        </li>
                    @empty
                        <li class="px-5 py-10 text-center text-gray-500">No endpoints yet. Add one to receive real-time events.</li>
                    @endforelse
                </ul>
            </x-card>
        </div>

        <div>
            <x-card title="Add endpoint">
                <form method="POST" action="{{ route('webhook-endpoints.store') }}" class="space-y-3">
                    @csrf
                    <div>
                        <x-input-label for="url" value="Payload URL" />
                        <x-text-input id="url" name="url" class="block mt-1 w-full" placeholder="https://example.com/hook" required />
                    </div>
                    <div>
                        <x-input-label value="Events" />
                        <div class="mt-2 space-y-2">
                            @foreach ($events as $event)
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" name="events[]" value="{{ $event }}" checked
                                           class="rounded border-gray-300 text-green-600 focus:ring-green-500">
                                    <span class="text-sm text-gray-700">{{ $event }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <x-btn type="submit" variant="primary">Add endpoint</x-btn>
                </form>
            </x-card>
        </div>
    </div>
</x-app-layout>
