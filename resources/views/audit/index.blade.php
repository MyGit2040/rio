<x-app-layout>
    <x-slot name="header">Audit log</x-slot>

    @php($actionOptions = $actions->mapWithKeys(fn ($a) => [(string) $a => $a])->all())
    <div class="mb-4 flex items-center gap-3 flex-wrap">
        <a href="{{ route('settings.edit') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Settings</a>
        <div class="ml-auto">
            <x-filter-bar search="Search details…"
                          :filters="['action' => ['all' => 'All actions', 'options' => $actionOptions]]"
                          :dates="['created_from' => 'From', 'created_to' => 'To']" />
        </div>
    </div>

    <x-card flush>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-left">
                    <tr>
                        <th class="px-5 py-3 font-medium">When</th>
                        <th class="px-5 py-3 font-medium">User</th>
                        <th class="px-5 py-3 font-medium">Action</th>
                        <th class="px-5 py-3 font-medium">Details</th>
                        <th class="px-5 py-3 font-medium">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($logs as $log)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3 text-gray-500 whitespace-nowrap">{{ $log->created_at?->format('M j, g:i A') }}</td>
                            <td class="px-5 py-3 text-gray-700 whitespace-nowrap">{{ $log->user->name ?? 'System' }}</td>
                            <td class="px-5 py-3"><x-badge color="blue">{{ $log->action }}</x-badge></td>
                            <td class="px-5 py-3 text-gray-600">
                                {{ $log->description }}
                                @if ($log->subject_type)<span class="text-gray-400">({{ $log->subject_type }} #{{ $log->subject_id }})</span>@endif
                            </td>
                            <td class="px-5 py-3 text-gray-400 whitespace-nowrap">{{ $log->ip }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-10 text-center text-gray-500">No activity recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($logs->hasPages())
            <div class="px-5 py-3 border-t border-gray-100">{{ $logs->links() }}</div>
        @endif
    </x-card>
</x-app-layout>
