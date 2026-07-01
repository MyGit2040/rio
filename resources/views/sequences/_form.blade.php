@php
    // Show the stored delay_minutes in the largest whole unit (minutes→months).
    $toValueUnit = function ($minutes) {
        $minutes = (int) $minutes;
        foreach (['months' => 43200, 'weeks' => 10080, 'days' => 1440, 'hours' => 60] as $unit => $m) {
            if ($minutes > 0 && $minutes % $m === 0) return ['value' => intdiv($minutes, $m), 'unit' => $unit];
        }
        return ['value' => $minutes, 'unit' => 'minutes'];
    };
    $initialSteps = old('steps', isset($sequence) && $sequence->steps->count()
        ? $sequence->steps->map(function ($s) use ($toValueUnit) {
            $vu = $toValueUnit($s->delay_minutes);
            return ['delay_value' => $vu['value'], 'delay_unit' => $vu['unit'], 'template_id' => $s->template_id, 'body' => $s->body];
          })->values()->all()
        : [['delay_value' => 0, 'delay_unit' => 'minutes', 'template_id' => null, 'body' => '']]);
@endphp

<div x-data="{ steps: @js(array_values($initialSteps)) }" class="space-y-5">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <x-input-label for="name" value="Sequence name" />
            <x-text-input id="name" name="name" class="block mt-1 w-full" :value="old('name', $sequence->name ?? '')" required />
        </div>
        <div>
            <x-input-label for="whatsapp_instance_id" value="Send from" />
            <select id="whatsapp_instance_id" name="whatsapp_instance_id"
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                <option value="">Any connected device</option>
                @foreach ($devices as $device)
                    <option value="{{ $device->id }}" @selected(old('whatsapp_instance_id', $sequence->whatsapp_instance_id ?? '') == $device->id)>{{ $device->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <label class="inline-flex items-center gap-2">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $sequence->is_active ?? true))
               class="rounded border-gray-300 text-green-600 focus:ring-green-500">
        <span class="text-sm text-gray-700">Active (enrolled contacts receive steps)</span>
    </label>

    <div>
        <x-input-label value="Steps" />
        <p class="text-xs text-gray-500 mb-2">Each step waits the set delay after the previous one, then sends. Use a template or type a message. Personalise with <code>@{{name}}</code>.</p>

        <div class="space-y-3">
            <template x-for="(step, i) in steps" :key="i">
                <div class="rounded-lg border border-gray-200 p-3 space-y-2">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold text-gray-500" x-text="'Step ' + (i + 1)"></span>
                        <button type="button" @click="steps.splice(i, 1)" x-show="steps.length > 1" class="ml-auto text-red-500 text-xs">Remove</button>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-sm text-gray-600" x-text="i === 0 ? 'Send after' : 'Then wait'"></span>
                        <input type="number" min="0" :name="'steps[' + i + '][delay_value]'" x-model.number="step.delay_value"
                               class="w-24 rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                        <select :name="'steps[' + i + '][delay_unit]'" x-model="step.delay_unit"
                                class="rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                            <option value="minutes">minutes</option>
                            <option value="hours">hours</option>
                            <option value="days">days</option>
                            <option value="weeks">weeks</option>
                            <option value="months">months</option>
                        </select>
                    </div>
                    <select :name="'steps[' + i + '][template_id]'" x-model="step.template_id"
                            class="block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500">
                        <option value="">— No template (use message below) —</option>
                        @foreach ($templates as $template)
                            <option value="{{ $template->id }}">{{ $template->name }} ({{ $template->type }})</option>
                        @endforeach
                    </select>
                    <textarea :name="'steps[' + i + '][body]'" x-model="step.body" rows="2" placeholder="Message (used when no template is chosen)"
                              class="block w-full rounded-lg border-gray-300 text-sm focus:ring-green-500 focus:border-green-500"></textarea>
                </div>
            </template>
        </div>

        <button type="button" @click="steps.push({ delay_value: 1, delay_unit: 'days', template_id: null, body: '' })"
                class="mt-3 text-sm text-green-600 font-medium">+ Add step</button>
    </div>
</div>
