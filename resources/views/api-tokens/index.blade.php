<x-app-layout>
    <x-slot name="header">REST API</x-slot>

    <div class="max-w-3xl space-y-6">
        @if (session('plain_token'))
            <div class="rounded-xl bg-green-50 border border-green-200 p-4">
                <p class="text-sm font-medium text-green-800 mb-2">Your new token — copy it now, it won't be shown again:</p>
                <code class="block bg-white border border-green-200 rounded-lg px-3 py-2 text-sm break-all select-all">{{ session('plain_token') }}</code>
            </div>
        @endif

        <x-card title="API tokens" subtitle="Let other apps push contacts and send messages into this workspace.">
            <form method="POST" action="{{ route('api-tokens.store') }}" class="flex items-end gap-3 flex-wrap">
                @csrf
                <div class="flex-1 min-w-[12rem]">
                    <x-input-label for="name" value="Token name" />
                    <x-text-input id="name" name="name" class="block mt-1 w-full" placeholder="e.g. Zapier, my website" required />
                </div>
                <x-btn type="submit" variant="primary">Create token</x-btn>
            </form>

            <div class="mt-5 divide-y divide-gray-100">
                @forelse ($tokens as $token)
                    <div class="flex items-center gap-3 py-3">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-800 truncate">{{ $token->name }}</p>
                            <p class="text-xs text-gray-500">Last used {{ $token->last_used_at?->diffForHumans() ?? 'never' }} · created {{ $token->created_at->format('M j, Y') }}</p>
                        </div>
                        <form method="POST" action="{{ route('api-tokens.destroy', $token) }}" class="ml-auto" onsubmit="return confirm('Revoke this token? Apps using it will stop working.')">
                            @csrf @method('DELETE')
                            <button class="text-sm text-red-600 hover:text-red-700">Revoke</button>
                        </form>
                    </div>
                @empty
                    <p class="text-sm text-gray-500 py-4">No tokens yet.</p>
                @endforelse
            </div>
        </x-card>

        <x-card title="Quick reference">
            <p class="text-sm text-gray-600 mb-3">Base URL: <code>{{ url('/api') }}</code> — send the token as a Bearer header.</p>
            <pre class="bg-gray-900 text-gray-100 rounded-lg p-4 text-xs overflow-x-auto"># List contacts
curl {{ url('/api/contacts') }} \
  -H "Authorization: Bearer YOUR_TOKEN"

# Send a message
curl -X POST {{ url('/api/messages') }} \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"device_id":1,"phone":"971500000000","message":"Hi!"}'</pre>
            <p class="text-xs text-gray-500 mt-3">Endpoints: <code>GET /me</code>, <code>GET/POST /contacts</code>, <code>GET /devices</code>, <code>GET /campaigns</code>, <code>POST /messages</code>.</p>
        </x-card>
    </div>
</x-app-layout>
