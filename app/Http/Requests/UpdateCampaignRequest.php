<?php

namespace App\Http\Requests;

use App\Models\Campaign;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Editing an existing (draft/scheduled/paused) campaign. The audience is
 * locked — recipients were built at creation — but the message snapshot,
 * devices, per-number caps, pacing and schedule all remain editable.
 */
class UpdateCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = Tenancy::id();
        /** @var Campaign $campaign */
        $campaign = $this->route('campaign');

        return [
            'name'            => ['required', 'string', 'max:255'],
            'device_ids'      => ['required', 'array', 'min:1'],
            'device_ids.*'    => [Rule::exists('whatsapp_instances', 'id')->where('tenant_id', $tenantId)],
            'rotate_every'    => ['nullable', 'integer', 'min:0', 'max:100000'],
            // Per-device cap: device_limits[<device_id>] = max messages (0/blank = unlimited).
            'device_limits'   => ['nullable', 'array'],
            'device_limits.*' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'body'            => ['nullable', 'string', 'max:4096', Rule::requiredIf($campaign->type === 'text')],
            'footer'          => ['nullable', 'string', 'max:1000'],
            'variants'        => ['array'],
            'variants.*'      => ['nullable', 'string', 'max:4096'],
            'media_url'       => ['nullable', 'url', 'max:2048'],
            'poll_question'   => ['nullable', 'string', 'max:255', Rule::requiredIf($campaign->type === 'poll')],
            'poll_options'    => ['array'],
            'poll_options.*'  => ['nullable', 'string', 'max:100'],
            'poll_multiple'   => ['sometimes', 'boolean'],
            'min_delay'       => ['required', 'integer', 'min:1', 'max:600'],
            'max_delay'       => ['required', 'integer', 'gte:min_delay', 'max:600'],
            'max_retries'     => ['nullable', 'integer', 'min:0', 'max:10'],
            'scheduled_at'    => ['nullable', 'date', 'after:now', Rule::requiredIf($campaign->status === 'scheduled')],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Drop blank poll-option / variant rows before validation.
        if ($this->has('poll_options')) {
            $this->merge(['poll_options' => array_values(array_filter(
                array_map('trim', (array) $this->input('poll_options')),
                fn ($v) => $v !== ''
            ))]);
        }

        if ($this->has('variants')) {
            $this->merge(['variants' => array_values(array_filter(
                array_map('trim', (array) $this->input('variants')),
                fn ($v) => $v !== ''
            ))]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            /** @var Campaign $campaign */
            $campaign = $this->route('campaign');

            if ($campaign->type === 'poll' && count($this->input('poll_options', [])) < 2) {
                $v->errors()->add('poll_options', 'A poll needs at least two options.');
            }
        });
    }
}
