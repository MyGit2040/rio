@php
    $isEdit = $plan->exists;
    $limits = $plan->limits ?? [];
    $inp = 'block w-full rounded-lg border-gray-300 text-sm focus:ring-brand focus:border-brand';
    $lbl = 'block text-sm text-gray-600 mb-1';
@endphp

<div class="space-y-6">
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <h3 class="font-semibold text-gray-800 mb-4">Plan details</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="{{ $lbl }}">Plan name</label>
                <input name="name" value="{{ old('name', $plan->name) }}" required placeholder="Pro" class="{{ $inp }}">
            </div>
            <div>
                <label class="{{ $lbl }}">Key (slug)</label>
                <input name="key" value="{{ old('key', $plan->key) }}" required placeholder="pro"
                       {{ $isEdit ? 'readonly' : '' }}
                       class="{{ $inp }} {{ $isEdit ? 'bg-gray-50 text-gray-500' : '' }}">
                <p class="text-xs text-gray-400 mt-1">{{ $isEdit ? 'Fixed — workspaces reference this key.' : 'Lowercase id, e.g. free, pro, business. Cannot change later.' }}</p>
            </div>
            <div class="sm:col-span-2">
                <label class="{{ $lbl }}">Short description</label>
                <input name="description" value="{{ old('description', $plan->description) }}" placeholder="Best for growing teams" class="{{ $inp }}">
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <h3 class="font-semibold text-gray-800 mb-4">Pricing</h3>
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div>
                <label class="{{ $lbl }}">Monthly price</label>
                <input name="price" type="number" step="0.01" min="0" value="{{ old('price', $plan->price ?? 0) }}" required class="{{ $inp }}">
            </div>
            <div>
                <label class="{{ $lbl }}">Annual price</label>
                <input name="annual_price" type="number" step="0.01" min="0" value="{{ old('annual_price', $plan->annual_price) }}" placeholder="optional" class="{{ $inp }}">
            </div>
            <div>
                <label class="{{ $lbl }}">Billing period</label>
                <select name="billing_period" class="{{ $inp }}">
                    @foreach (['monthly' => 'Monthly', 'yearly' => 'Yearly', 'one_time' => 'One-time'] as $v => $label)
                        <option value="{{ $v }}" @selected(old('billing_period', $plan->billing_period ?? 'monthly') === $v)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="{{ $lbl }}">Sort order</label>
                <input name="sort_order" type="number" min="0" value="{{ old('sort_order', $plan->sort_order ?? 0) }}" class="{{ $inp }}">
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <h3 class="font-semibold text-gray-800 mb-1">Usage limits</h3>
        <p class="text-xs text-gray-400 mb-4">Enforced across the workspace. Set <strong>0</strong> for unlimited.</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="{{ $lbl }}">WhatsApp devices</label>
                <input name="limit_devices" type="number" min="0" value="{{ old('limit_devices', data_get($limits, 'devices', 0)) }}" required class="{{ $inp }}">
            </div>
            <div>
                <label class="{{ $lbl }}">Contacts</label>
                <input name="limit_contacts" type="number" min="0" value="{{ old('limit_contacts', data_get($limits, 'contacts', 0)) }}" required class="{{ $inp }}">
            </div>
            <div>
                <label class="{{ $lbl }}">Messages / month</label>
                <input name="limit_monthly_messages" type="number" min="0" value="{{ old('limit_monthly_messages', data_get($limits, 'monthly_messages', 0)) }}" required class="{{ $inp }}">
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <h3 class="font-semibold text-gray-800 mb-1">Features</h3>
        <p class="text-xs text-gray-400 mb-3">One per line. Start a line with <code class="px-1 bg-gray-100 rounded">-</code> to show it struck-through (not included).</p>
        <textarea name="features" rows="6" class="{{ $inp }}" placeholder="5 WhatsApp numbers&#10;Drip sequences&#10;-Priority support">{{ old('features', $plan->features) }}</textarea>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        <h3 class="font-semibold text-gray-800 mb-4">Visibility</h3>
        <div class="space-y-3">
            <label class="flex items-center gap-3 text-sm text-gray-700">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $plan->is_active ?? true)) class="rounded border-gray-300 text-brand focus:ring-brand">
                Active <span class="text-xs text-gray-400">— shown on the Billing page and selectable</span>
            </label>
            <label class="flex items-center gap-3 text-sm text-gray-700">
                <input type="checkbox" name="is_popular" value="1" @checked(old('is_popular', $plan->is_popular)) class="rounded border-gray-300 text-brand focus:ring-brand">
                Most popular <span class="text-xs text-gray-400">— highlights the plan with a badge</span>
            </label>
            <label class="flex items-center gap-3 text-sm text-gray-700">
                <input type="checkbox" name="is_default" value="1" @checked(old('is_default', $plan->is_default)) class="rounded border-gray-300 text-brand focus:ring-brand">
                Default plan <span class="text-xs text-gray-400">— new workspaces fall back to this</span>
            </label>
        </div>
    </div>
</div>
