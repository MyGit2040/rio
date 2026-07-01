<x-app-layout>
    <x-slot name="header">Import contacts</x-slot>

    <div class="max-w-2xl">
        <x-card title="Import from CSV">
            <form method="POST" action="{{ route('contacts.import.store') }}" enctype="multipart/form-data" class="space-y-5">
                @csrf

                <div class="rounded-lg bg-gray-50 border border-gray-200 p-4 text-sm text-gray-600">
                    <p class="font-medium text-gray-800 mb-1">CSV format</p>
                    <p>First row is the header. Recognised columns: <code class="text-green-700">name, phone, email, country</code>.</p>
                    <p class="mt-1"><code class="text-green-700">phone</code> is required (digits with country code).</p>
                </div>

                <div>
                    <x-input-label for="file" value="CSV file" />
                    <input id="file" name="file" type="file" accept=".csv,.txt" required
                           class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-green-600 file:text-white hover:file:bg-green-700">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="group_id" value="Add to existing group (optional)" />
                        <select id="group_id" name="group_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                            <option value="">— None —</option>
                            @foreach ($groups as $g)
                                <option value="{{ $g->id }}">{{ $g->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="new_group" value="…or create a new group" />
                        <x-text-input id="new_group" name="new_group" class="block mt-1 w-full" placeholder="e.g. Newsletter Jan" :value="old('new_group')" />
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <x-btn type="submit" variant="primary">Import contacts</x-btn>
                    <x-btn :href="route('contacts.index')" variant="ghost">Cancel</x-btn>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
