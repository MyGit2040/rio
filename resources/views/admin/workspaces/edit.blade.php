<x-admin-layout header="Edit workspace">
    <div class="mb-4 flex items-center gap-3">
        <a href="{{ route('admin.workspaces.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Workspaces</a>
        <span class="text-sm text-gray-400">{{ $deviceCount }} device(s) connected</span>
    </div>

    <form method="POST" action="{{ route('admin.workspaces.update', $workspace) }}" class="max-w-4xl">
        @csrf @method('PUT')
        @include('admin.workspaces._form')
        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-brand text-white text-sm font-medium">Save changes</button>
            <a href="{{ route('admin.workspaces.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
        </div>
    </form>
</x-admin-layout>
