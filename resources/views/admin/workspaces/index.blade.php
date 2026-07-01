<x-admin-layout header="Workspaces">
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
        <div class="flex items-center gap-3 px-5 py-4 border-b border-gray-100 flex-wrap">
            <h2 class="font-semibold text-gray-800">Client workspaces</h2>
            <div class="ml-auto">
                <x-filter-bar :action="route('admin.workspaces.index')" search="Search name…" :filters="[
                    'status' => ['all' => 'All Statuses', 'options' => ['active' => 'Active', 'suspended' => 'Suspended', 'expired' => 'Expired']],
                ]" />
            </div>
            <a href="{{ route('admin.workspaces.create') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-brand text-white text-sm font-medium">+ New workspace</a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-left">
                    <tr>
                        <th class="px-5 py-3 font-medium">Workspace</th>
                        <th class="px-5 py-3 font-medium">Owner login</th>
                        <th class="px-5 py-3 font-medium">Devices</th>
                        <th class="px-5 py-3 font-medium">Modules</th>
                        <th class="px-5 py-3 font-medium">Expires</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 font-medium text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($tenants as $t)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3 font-medium text-gray-800 whitespace-nowrap">{{ $t->name }}</td>
                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ $owners[$t->id]->email ?? '—' }}</td>
                            <td class="px-5 py-3 text-gray-600">{{ $deviceCounts[$t->id] ?? 0 }}{{ $t->max_devices > 0 ? ' / '.$t->max_devices : '' }}</td>
                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">
                                @php($total = count(config('modules')))
                                {{ empty($t->enabled_modules) ? 'All ('.$total.')' : count($t->enabled_modules).' / '.$total }}
                            </td>
                            <td class="px-5 py-3 text-gray-500 whitespace-nowrap">{{ $t->expires_at?->format('M j, Y') ?? 'Never' }}</td>
                            <td class="px-5 py-3">
                                @if ($t->isBlocked())
                                    <span class="px-2 py-0.5 rounded-full text-xs bg-red-100 text-red-700">{{ $t->status === 'suspended' ? 'Suspended' : 'Expired' }}</span>
                                @else
                                    <span class="px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-700">Active</span>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3 justify-end">
                                    <a href="{{ route('admin.workspaces.edit', $t) }}" class="text-brand hover:underline">Edit</a>
                                    <form method="POST" action="{{ route('admin.workspaces.status', $t) }}">
                                        @csrf
                                        <button class="text-gray-500 hover:text-gray-700">{{ $t->status === 'suspended' ? 'Activate' : 'Suspend' }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.workspaces.destroy', $t) }}" onsubmit="return confirm('Delete {{ $t->name }} and ALL its data? This cannot be undone.')">
                                        @csrf @method('DELETE')
                                        <button class="text-red-600 hover:text-red-700">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-10 text-center text-gray-500">No workspaces yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($tenants->hasPages())
            <div class="px-5 py-3 border-t border-gray-100">{{ $tenants->links() }}</div>
        @endif
    </div>
</x-admin-layout>
