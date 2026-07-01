<x-admin-layout header="New workspace">
    <div class="mb-4"><a href="{{ route('admin.workspaces.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Workspaces</a></div>

    <form method="POST" action="{{ route('admin.workspaces.store') }}" class="max-w-4xl">
        @csrf
        @include('admin.workspaces._form', ['workspace' => null])
        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-brand text-white text-sm font-medium">⚡ Create workspace</button>
            <a href="{{ route('admin.workspaces.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
        </div>
    </form>
</x-admin-layout>
