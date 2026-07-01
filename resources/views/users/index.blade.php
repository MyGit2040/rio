<x-app-layout>
    <x-slot name="header">Team</x-slot>

    <a href="{{ route('settings.edit') }}" class="text-sm text-gray-500 hover:text-gray-700 inline-block mb-4">&larr; Settings</a>

    <x-card flush>
        <div class="flex items-center gap-3 px-5 py-4 border-b border-gray-100">
            <div>
                <h2 class="font-semibold text-gray-800">Team members</h2>
                <p class="text-sm text-gray-500">Everyone here shares your workspace data.</p>
            </div>
            <x-btn :href="route('users.create')" variant="primary" class="ml-auto">Add member</x-btn>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-left">
                    <tr>
                        <th class="px-5 py-3 font-medium">Name</th>
                        <th class="px-5 py-3 font-medium">Email</th>
                        <th class="px-5 py-3 font-medium">Role</th>
                        <th class="px-5 py-3 font-medium text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($users as $user)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-3 font-medium text-gray-800 whitespace-nowrap">
                                {{ $user->name }}
                                @if ($user->id === auth()->id())<span class="text-xs text-gray-400">(you)</span>@endif
                            </td>
                            <td class="px-5 py-3 text-gray-600 whitespace-nowrap">{{ $user->email }}</td>
                            <td class="px-5 py-3">
                                <x-badge :color="$user->isOwner() ? 'purple' : 'gray'">{{ ucfirst($user->role) }}</x-badge>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3 justify-end">
                                    <a href="{{ route('users.edit', $user) }}" class="text-brand hover:opacity-80">Edit</a>
                                    @if ($user->id !== auth()->id())
                                        <form method="POST" action="{{ route('users.destroy', $user) }}" onsubmit="return confirm('Remove this team member?')">
                                            @csrf @method('DELETE')
                                            <button class="text-red-600 hover:text-red-700">Remove</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>
</x-app-layout>
