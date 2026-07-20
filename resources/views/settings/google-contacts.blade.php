<x-app-layout>
    <x-slot name="header">Google Contacts & backups</x-slot>

    <div class="max-w-5xl space-y-6">
        <x-card title="What this page does" subtitle="Keep a clear record of the dedicated Gmail used by each WhatsApp phone.">
            <div class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                WhatsApp chat backups and Android contact sync are controlled on the phone by Google and WhatsApp. Eagle does not collect Gmail passwords, cannot access your backups, and will never claim that a phone is synced when it is not.
            </div>
        </x-card>

        <x-card title="Get your Google Client ID — step by step" subtitle="Complete this once. Keep this page open so you can copy the callback URL below.">
            <ol class="list-decimal ml-5 space-y-3 text-sm text-gray-700">
                <li><a class="text-brand underline" target="_blank" rel="noopener" href="https://console.cloud.google.com/">Open Google Cloud Console</a> and sign in with your business Google account.</li>
                <li>Use the project selector at the top, then click <strong>New project</strong>. Name it <strong>Eagle CRM</strong> and click <strong>Create</strong>.</li>
                <li><a class="text-brand underline" target="_blank" rel="noopener" href="https://console.cloud.google.com/apis/library/people.googleapis.com">Open Google People API</a>, select your new project, then click <strong>Enable</strong>.</li>
                <li><a class="text-brand underline" target="_blank" rel="noopener" href="https://console.cloud.google.com/auth/branding">Open Google Auth Platform</a>. Click <strong>Get started</strong>, enter <strong>Eagle CRM</strong> as the app name, choose your support email, then save. Choose <strong>External</strong> if your Gmail accounts are normal Gmail accounts.</li>
                <li>In <strong>Audience</strong>, if the app remains in testing, add every Gmail account you will connect as a <strong>Test user</strong>.</li>
                <li><a class="text-brand underline" target="_blank" rel="noopener" href="https://console.cloud.google.com/auth/clients">Open Clients</a> → <strong>Create client</strong> → choose <strong>Web application</strong>. Under <strong>Authorized redirect URIs</strong>, click Add URI and paste the exact address below.</li>
                <li>Click <strong>Create</strong>. Copy the new <strong>Client ID</strong> and <strong>Client Secret</strong> into the fields in the next section, then save.</li>
            </ol>
            <div class="mt-4"><p class="text-xs font-medium text-gray-700 mb-1">Copy this Authorized redirect URI exactly</p><code class="block rounded-lg bg-gray-900 text-gray-100 p-3 text-xs break-all">{{ $callbackUrl }}</code></div>
            <p class="mt-3 text-xs text-gray-500">Google requires OAuth because Eagle needs permission to create contacts in each Gmail account. Never share the Client Secret publicly or add it to GitHub.</p>
        </x-card>

        <x-card title="Seeing “Access blocked” or Error 403?" subtitle="This is Google’s test-user protection. It is not an Eagle error.">
            <ol class="list-decimal ml-5 space-y-2 text-sm text-gray-700">
                <li><a class="text-brand underline" target="_blank" rel="noopener" href="https://console.cloud.google.com/auth/audience">Open Google Auth Platform → Audience</a> and ensure the <strong>Eagle CRM</strong> project is selected.</li>
                <li>Under <strong>Test users</strong>, click <strong>Add users</strong>.</li>
                <li>Add the Gmail shown in the Google error message — for example, <strong>raimsdigi1@gmail.com</strong>.</li>
                <li>Add every other Gmail account you plan to connect to a WhatsApp number, then click <strong>Save</strong>.</li>
                <li>Wait one or two minutes. Return here and click <strong>Connect Google</strong> again for that number.</li>
            </ol>
            <p class="mt-3 text-xs text-green-700 bg-green-50 rounded-lg p-3">Google verification is not required while you use your own accounts as test users. Verification is only needed later if you publish the integration for broad public use.</p>
        </x-card>

        <x-card title="1. Connect Google securely" subtitle="Enter the OAuth details from Google Cloud once. They are encrypted before storage and never shown again.">
            <form method="POST" action="{{ route('settings.google-contacts.credentials') }}" class="grid grid-cols-1 md:grid-cols-[1fr_1fr_auto] gap-3 items-end">
                @csrf
                <div><x-input-label for="google_contacts_client_id" value="Google OAuth Client ID" /><x-text-input id="google_contacts_client_id" name="google_contacts_client_id" class="block mt-1 w-full" placeholder="…apps.googleusercontent.com" :value="data_get(auth()->user()->tenant->settings, 'google_contacts_client_id')" required /></div>
                <div><x-input-label for="google_contacts_client_secret" value="Google OAuth Client Secret" /><x-password-input id="google_contacts_client_secret" name="google_contacts_client_secret" placeholder="{{ $oauthReady ? 'Saved securely — leave blank to keep it' : '' }}" /></div>
                <x-btn type="submit" variant="secondary">Save Google details</x-btn>
            </form>
            <p class="mt-3 text-xs {{ $oauthReady ? 'text-green-700' : 'text-amber-700' }}">{{ $oauthReady ? 'Google OAuth is ready. Connect the Gmail account for each number below.' : 'Create a Web application OAuth client in Google Cloud, enable Google People API, then paste its details here.' }}</p>
        </x-card>

        <x-card flush>
            <div class="px-5 py-4 border-b border-gray-100"><h2 class="font-semibold text-gray-800">Your WhatsApp numbers</h2><p class="text-xs text-gray-500 mt-1">Enter the dedicated Gmail used on each physical phone. This is a secure reference for your team.</p></div>
            <div class="divide-y divide-gray-100">
                @forelse ($devices as $device)
                    <form method="POST" action="{{ route('settings.google-contacts.update', $device) }}" class="px-5 py-4 grid grid-cols-1 md:grid-cols-[1fr_1.4fr_auto] gap-3 items-end">
                        @csrf @method('PATCH')
                        <div><p class="font-medium text-gray-800">{{ $device->name }}</p><p class="text-xs text-gray-500">{{ $device->phone_number ? '+'.$device->phone_number : $device->instance_name }}</p></div>
                        <div><x-input-label :for="'google-'.$device->id" value="Dedicated Gmail on this phone" /><x-text-input :id="'google-'.$device->id" name="google_contacts_email" type="email" class="block mt-1 w-full" placeholder="number1@yourcompany.com" :value="$device->google_contacts_email" /></div>
                        <div class="flex gap-2"><x-btn type="submit" variant="secondary">Save</x-btn>@if ($oauthReady)<a href="{{ route('settings.google-contacts.connect', $device) }}" class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium {{ $device->google_contacts_connected_at ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-brand text-white' }}">{{ $device->google_contacts_connected_at ? 'Reconnect Google' : 'Connect Google' }}</a>@endif</div>
                    </form>
                @empty
                    <p class="px-5 py-10 text-center text-gray-500">Add a WhatsApp number first.</p>
                @endforelse
            </div>
        </x-card>

        <x-card title="2. One-click contact sync" subtitle="Upload your Excel (.xlsx) or CSV list, select the Gmail accounts, then sync. Existing synced contacts are skipped so repeat uploads do not duplicate them.">
            @if (! $oauthReady)
                <p class="text-sm text-amber-700 bg-amber-50 rounded-lg p-3">Save Google OAuth details and connect at least one Gmail account before syncing.</p>
            @else
                <form method="POST" action="{{ route('settings.google-contacts.sync') }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <div><x-input-label for="contacts_file" value="Contact list (.xlsx or CSV)" /><input id="contacts_file" name="contacts_file" type="file" accept=".xlsx,.csv,.txt" required class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-brand file:text-white"><p class="text-xs text-gray-500 mt-1">Columns required: <strong>name</strong> and <strong>phone</strong> (or number/mobile), including country code.</p></div>
                    <div><p class="text-sm font-medium text-gray-800 mb-2">Sync to these Gmail accounts</p><div class="grid grid-cols-1 md:grid-cols-2 gap-2">@foreach ($devices->whereNotNull('google_contacts_connected_at') as $device)<label class="flex items-center gap-2 rounded-lg border border-gray-200 p-3 text-sm"><input type="checkbox" name="device_ids[]" value="{{ $device->id }}" checked class="rounded border-gray-300 text-brand focus:ring-brand"><span>{{ $device->name }} <span class="text-gray-500">— {{ $device->google_contacts_email }}</span></span></label>@endforeach</div></div>
                    <x-btn type="submit" variant="primary">Sync contacts to selected Gmail accounts</x-btn>
                </form>
            @endif
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

        <x-card title="About this connection" subtitle="This is separate from the phone’s native WhatsApp backup.">
            <p class="text-sm text-gray-600">Google OAuth authorizes Eagle to create contacts in the Gmail accounts you choose. It does not give Eagle access to Gmail passwords, WhatsApp chats, or WhatsApp backups. The required callback URL is:</p>
            <code class="mt-3 block rounded-lg bg-gray-900 text-gray-100 p-3 text-xs break-all">{{ $callbackUrl }}</code>
            <p class="mt-3 text-xs text-gray-500">Do not enter Gmail passwords into Eagle. Google OAuth is the safe connection method used by the Connect Google button above.</p>
        </x-card>
    </div>
</x-app-layout>
