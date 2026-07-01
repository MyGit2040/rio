<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <x-input-label for="name" value="Name" />
        <x-text-input id="name" name="name" class="block mt-1 w-full" :value="old('name', $user->name ?? '')" required />
    </div>
    <div>
        <x-input-label for="email" value="Email" />
        <x-text-input id="email" name="email" type="email" class="block mt-1 w-full" :value="old('email', $user->email ?? '')" required />
    </div>
    <div>
        <x-input-label for="role" value="Role" />
        <select id="role" name="role" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand">
            <option value="member" @selected(old('role', $user->role ?? 'member') === 'member')>Member — uses the app</option>
            <option value="owner" @selected(old('role', $user->role ?? '') === 'owner')>Owner — full control + team management</option>
        </select>
    </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
    <div>
        <x-input-label for="password" value="{{ isset($user) ? 'New password (leave blank to keep)' : 'Password' }}" />
        <x-text-input id="password" name="password" type="password" class="block mt-1 w-full" autocomplete="new-password" :required="! isset($user)" />
    </div>
    <div>
        <x-input-label for="password_confirmation" value="Confirm password" />
        <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="block mt-1 w-full" autocomplete="new-password" />
    </div>
</div>
