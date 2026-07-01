<x-app-layout>
    <x-slot name="header">{{ $sequence->name }}</x-slot>

    <div class="mb-4 flex items-center gap-3 flex-wrap">
        <a href="{{ route('sequences.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Sequences</a>
        <x-badge :color="$sequence->is_active ? 'green' : 'gray'">{{ $sequence->is_active ? 'Active' : 'Paused' }}</x-badge>
        <div class="ml-auto"><x-btn :href="route('sequences.edit', $sequence)" variant="secondary">Edit</x-btn></div>
    </div>

    <div class="grid grid-cols-3 gap-4 mb-6">
        <x-card><p class="text-2xl font-bold text-green-600">{{ $stats['active'] }}</p><p class="text-xs text-gray-500 mt-1">Active</p></x-card>
        <x-card><p class="text-2xl font-bold text-gray-800">{{ $stats['completed'] }}</p><p class="text-xs text-gray-500 mt-1">Completed</p></x-card>
        <x-card><p class="text-2xl font-bold text-gray-400">{{ $stats['stopped'] }}</p><p class="text-xs text-gray-500 mt-1">Stopped</p></x-card>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <x-card title="Steps ({{ $sequence->steps->count() }})">
                <ol class="space-y-3">
                    @foreach ($sequence->steps as $step)
                        <li class="flex items-start gap-3">
                            <span class="grid place-items-center w-7 h-7 rounded-full bg-brand text-white text-xs font-bold shrink-0">{{ $loop->iteration }}</span>
                            <div class="min-w-0">
                                <p class="text-xs text-gray-500">
                                    {{ $loop->first ? 'Send after' : 'Wait' }} {{ $step->delay_minutes }} min
                                    @if ($step->template) · template: {{ $step->template->name }}@endif
                                </p>
                                <p class="text-sm text-gray-700 whitespace-pre-line break-words">{{ $step->template->body ?? $step->body }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </x-card>

            <x-card title="Enrollments" flush>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-gray-500 text-left">
                            <tr>
                                <th class="px-5 py-3 font-medium">Contact</th>
                                <th class="px-5 py-3 font-medium">Step</th>
                                <th class="px-5 py-3 font-medium">Next run</th>
                                <th class="px-5 py-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($enrollments as $e)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-5 py-3 text-gray-800 whitespace-nowrap">{{ $e->contact->name ?? '+'.($e->contact->phone ?? '') }}</td>
                                    <td class="px-5 py-3 text-gray-600">{{ $e->current_step + 1 }}</td>
                                    <td class="px-5 py-3 text-gray-500 whitespace-nowrap">{{ $e->next_run_at?->format('M j, g:i A') ?? '—' }}</td>
                                    <td class="px-5 py-3"><x-badge :color="$e->status === 'active' ? 'green' : ($e->status === 'completed' ? 'blue' : 'gray')">{{ ucfirst($e->status) }}</x-badge></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-5 py-8 text-center text-gray-500">No one enrolled yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($enrollments->hasPages())<div class="px-5 py-3 border-t border-gray-100">{{ $enrollments->links() }}</div>@endif
            </x-card>
        </div>

        <div>
            <x-card title="Enroll contacts">
                <form method="POST" action="{{ route('sequences.enroll', $sequence) }}" class="space-y-3">
                    @csrf
                    <div>
                        <x-input-label for="group_id" value="Audience" />
                        <select id="group_id" name="group_id"
                                class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                            <option value="">All opted-in contacts</option>
                            @foreach ($groups as $group)
                                <option value="{{ $group->id }}">{{ $group->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <p class="text-xs text-gray-500">Already-enrolled, opted-out and blocked contacts are skipped automatically.</p>
                    <x-btn type="submit" variant="primary">Enroll</x-btn>
                </form>
            </x-card>
        </div>
    </div>
</x-app-layout>
