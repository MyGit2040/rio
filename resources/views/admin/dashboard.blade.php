<x-admin-layout header="Admin dashboard">
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
        @foreach ([
            ['Workspaces', $stats['workspaces'], 'text-gray-800'],
            ['Active', $stats['active'], 'text-green-600'],
            ['Blocked', $stats['blocked'], 'text-red-500'],
            ['Devices', $stats['devices'], 'text-brand'],
            ['Users', $stats['users'], 'text-gray-800'],
        ] as [$label, $value, $color])
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                <p class="text-2xl font-bold {{ $color }}">{{ number_format($value) }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ $label }}</p>
            </div>
        @endforeach
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="flex items-center gap-3 px-5 py-4 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800">Recent workspaces</h2>
            <a href="{{ route('admin.workspaces.create') }}" class="ml-auto inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-brand text-white text-sm font-medium">+ New workspace</a>
        </div>
        <div class="divide-y divide-gray-100">
            @forelse ($recent as $t)
                <a href="{{ route('admin.workspaces.edit', $t) }}" class="flex items-center gap-3 px-5 py-3 hover:bg-gray-50">
                    <span class="font-medium text-gray-800">{{ $t->name }}</span>
                    <span class="text-xs text-gray-400">{{ $t->users_count }} user(s)</span>
                    <span class="ml-auto">
                        @if ($t->isBlocked())
                            <span class="px-2 py-0.5 rounded-full text-xs bg-red-100 text-red-700">{{ $t->status === 'suspended' ? 'Suspended' : 'Expired' }}</span>
                        @else
                            <span class="px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-700">Active</span>
                        @endif
                    </span>
                </a>
            @empty
                <p class="px-5 py-8 text-center text-gray-500">No workspaces yet. <a href="{{ route('admin.workspaces.create') }}" class="text-brand">Create the first one</a>.</p>
            @endforelse
        </div>
    </div>
</x-admin-layout>
