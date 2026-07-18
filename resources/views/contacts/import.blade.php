<x-app-layout>
    <x-slot name="header">Import contacts</x-slot>

    <div class="max-w-2xl">
        <x-card title="Import from CSV">
            <form method="POST" action="{{ route('contacts.import.store') }}" enctype="multipart/form-data" class="space-y-5">
                @csrf

                <div class="rounded-lg bg-gray-50 border border-gray-200 p-4 text-sm text-gray-600">
                    <div class="flex items-center gap-3 flex-wrap mb-2">
                        <p class="font-medium text-gray-800">File format</p>
                        <a href="{{ route('contacts.import.sample') }}" class="ml-auto inline-flex items-center gap-1.5 text-brand font-medium hover:underline">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
                            Download sample file
                        </a>
                    </div>
                    <p>Two columns: <code class="text-brand">name</code> and <code class="text-brand">number</code> (first row is the header).</p>
                    <p class="mt-1"><strong>Include the country code</strong> in the number — digits only, no <code>+</code> or spaces. Example: a UAE number is <code class="text-brand">971501234567</code> (971 = UAE), a UK number <code class="text-brand">447911123456</code> (44 = UK).</p>
                    <p class="mt-1 text-gray-400">Duplicate numbers (already in your contacts) are skipped automatically.</p>
                </div>

                <div>
                    <x-input-label for="file" value="CSV / Excel-saved-as-CSV file" />
                    <input id="file" name="file" type="file" accept=".csv,.txt" required
                           class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-brand file:text-white">
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

                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <p class="text-sm font-medium text-amber-900">Permission declaration</p>
                    <p class="mt-1 text-xs text-amber-800">Import only people who gave permission for marketing messages. Imported contacts are eligible for campaigns only after this declaration.</p>
                    <div class="mt-3">
                        <x-input-label for="marketing_consent_source" value="Permission source" />
                        <x-text-input id="marketing_consent_source" name="marketing_consent_source" class="block mt-1 w-full" placeholder="e.g. website signup form" :value="old('marketing_consent_source')" required />
                    </div>
                    <label class="mt-3 flex items-start gap-2 text-sm text-amber-950">
                        <input type="checkbox" name="marketing_consent_confirmed" value="1" class="mt-0.5 rounded border-amber-300 text-brand focus:ring-brand" required>
                        <span>I confirm every imported contact gave permission to receive marketing messages and can opt out at any time.</span>
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <x-btn type="submit" variant="primary">Import contacts</x-btn>
                    <x-btn :href="route('contacts.index')" variant="ghost">Cancel</x-btn>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
