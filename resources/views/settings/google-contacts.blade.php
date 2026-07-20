<x-app-layout>
    <x-slot name="header">Google Contacts & backups</x-slot>

    <div class="max-w-5xl space-y-6">
        <x-card title="What this page does" subtitle="Keep a clear record of the dedicated Gmail used by each WhatsApp phone.">
            <div class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                WhatsApp chat backups and Android contact sync are controlled on the phone by Google and WhatsApp. Eagle does not collect Gmail passwords, cannot access your backups, and will never claim that a phone is synced when it is not.
            </div>
        </x-card>

        <x-card flush>
            <div class="px-5 py-4 border-b border-gray-100"><h2 class="font-semibold text-gray-800">Your WhatsApp numbers</h2><p class="text-xs text-gray-500 mt-1">Enter the dedicated Gmail used on each physical phone. This is a secure reference for your team.</p></div>
            <div class="divide-y divide-gray-100">
                @forelse ($devices as $device)
                    <form method="POST" action="{{ route('settings.google-contacts.update', $device) }}" class="px-5 py-4 grid grid-cols-1 md:grid-cols-[1fr_1.4fr_auto] gap-3 items-end">
                        @csrf @method('PATCH')
                        <div><p class="font-medium text-gray-800">{{ $device->name }}</p><p class="text-xs text-gray-500">{{ $device->phone_number ? '+'.$device->phone_number : $device->instance_name }}</p></div>
                        <div><x-input-label :for="'google-'.$device->id" value="Dedicated Gmail on this phone" /><x-text-input :id="'google-'.$device->id" name="google_contacts_email" type="email" class="block mt-1 w-full" placeholder="number1@yourcompany.com" :value="$device->google_contacts_email" /></div>
                        <x-btn type="submit" variant="secondary">Save</x-btn>
                    </form>
                @empty
                    <p class="px-5 py-10 text-center text-gray-500">Add a WhatsApp number first.</p>
                @endforelse
            </div>
        </x-card>

        <x-card title="Set up every phone" subtitle="Do this directly on each Android phone. It takes about two minutes per number.">
            <ol class="list-decimal ml-5 space-y-2 text-sm text-gray-700">
                <li>Open <strong>Settings → Passwords & accounts → Add account → Google</strong>.</li>
                <li>Sign in with the dedicated Gmail shown above for that WhatsApp number.</li>
                <li>Open the account’s <strong>Account sync</strong> and turn on <strong>Contacts</strong>.</li>
                <li>Open WhatsApp and allow the <strong>Contacts</strong> permission.</li>
                <li>In WhatsApp, open <strong>Settings → Chats → Chat backup</strong>, select the same Gmail, then run <strong>Back up</strong>.</li>
            </ol>
        </x-card>

        <x-card title="Future CRM contact sync" subtitle="Optional — this is separate from the phone’s native WhatsApp backup.">
            <p class="text-sm text-gray-600">When you create Google OAuth credentials, Eagle can be connected to Google People API to import and sync contacts directly. The required callback URL will be:</p>
            <code class="mt-3 block rounded-lg bg-gray-900 text-gray-100 p-3 text-xs break-all">{{ $callbackUrl }}</code>
            <p class="mt-3 text-xs text-gray-500">Do not enter Gmail passwords into Eagle. Google OAuth is the safe connection method and will be added only after you provide a Google Client ID and Client Secret.</p>
        </x-card>
    </div>
</x-app-layout>
