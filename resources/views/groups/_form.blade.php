<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <x-input-label for="name" value="Group name" />
        <x-text-input id="name" name="name" class="block mt-1 w-full" :value="old('name', $group->name ?? '')" required />
    </div>
    <div>
        <x-input-label for="color" value="Colour" />
        <input id="color" name="color" type="color" value="{{ old('color', $group->color ?? '#16a34a') }}"
               class="mt-1 block h-10 w-20 rounded-lg border border-gray-300 cursor-pointer">
    </div>
</div>
