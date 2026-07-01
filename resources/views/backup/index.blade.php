<x-app-layout>
    <x-slot name="header">Backup &amp; Restore</x-slot>

    <div class="max-w-2xl space-y-6">
        <x-card title="Create backup" subtitle="Download your workspace data as an unencrypted ZIP.">
            <p class="text-sm text-gray-600 mb-4">Includes your contacts, groups, templates and chatbot rules. WhatsApp sessions are not included (they live on the engine).</p>
            <form method="POST" action="{{ route('backup.create') }}">
                @csrf
                <x-btn type="submit" variant="primary">Create backup now</x-btn>
            </form>
        </x-card>

        <x-card title="Restore backup" subtitle="Upload a backup ZIP to merge it into this workspace.">
            <form method="POST" action="{{ route('backup.restore') }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <div>
                    <x-input-label for="file" value="Backup file (.zip or .json)" />
                    <input id="file" name="file" type="file" accept=".zip,.json" required
                           class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-brand file:text-white">
                </div>
                <p class="text-xs text-gray-500">Restore only adds/updates — it never deletes existing data.</p>
                <x-btn type="submit" variant="secondary">Restore backup</x-btn>
            </form>
        </x-card>
    </div>
</x-app-layout>
