<x-app-layout>
    <x-slot name="header">{{ $contact->name ?? '+'.$contact->phone }}</x-slot>

    <div class="mb-4 flex items-center gap-3 flex-wrap">
        <a href="{{ route('inbox.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Inbox</a>
        <a href="{{ route('contacts.show', $contact) }}" class="text-sm text-green-600 hover:text-green-700">View profile</a>
        <span class="ml-auto text-sm text-gray-500">+{{ $contact->phone }}</span>
    </div>

    <x-card flush>
        <div class="p-5 space-y-3 max-h-[60vh] overflow-y-auto bg-gray-50">
            @forelse ($messages as $msg)
                <div class="flex {{ $msg->direction === 'out' ? 'justify-end' : 'justify-start' }}">
                    <div class="max-w-md rounded-2xl px-4 py-2 text-sm shadow-sm
                        {{ $msg->direction === 'out' ? 'bg-green-600 text-white rounded-br-sm' : 'bg-white text-gray-800 rounded-bl-sm border border-gray-100' }}">
                        <p class="whitespace-pre-line break-words">{{ $msg->body ?: '['.$msg->type.']' }}</p>
                        <p class="text-[10px] mt-1 {{ $msg->direction === 'out' ? 'text-green-100' : 'text-gray-400' }}">
                            {{ $msg->created_at?->format('M j, g:i A') }}
                        </p>
                    </div>
                </div>
            @empty
                <p class="text-center text-gray-500 py-8">No messages in this conversation yet.</p>
            @endforelse
        </div>

        <div class="p-4 border-t border-gray-100">
            @if ($device)
                <form method="POST" action="{{ route('inbox.reply', $contact) }}" class="flex items-end gap-2">
                    @csrf
                    <textarea name="body" rows="1" required placeholder="Type a reply…"
                              class="flex-1 rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500"></textarea>
                    <x-btn type="submit" variant="primary">Send</x-btn>
                </form>
                <p class="text-xs text-gray-400 mt-1">Sending from {{ $device->name }}.</p>
            @else
                <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                    Connect a WhatsApp device to reply.
                </p>
            @endif
        </div>
    </x-card>
</x-app-layout>
