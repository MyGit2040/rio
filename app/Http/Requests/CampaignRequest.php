<?php

namespace App\Http\Requests;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = Tenancy::id();

        return [
            'name'          => ['required', 'string', 'max:255'],
            'device_ids'    => ['required', 'array', 'min:1'],
            'device_ids.*'  => [Rule::exists('whatsapp_instances', 'id')->where('tenant_id', $tenantId)],
            'template_id' => [
                'nullable',
                Rule::exists('templates', 'id')->where('tenant_id', $tenantId),
            ],
            'body'        => ['nullable', 'string', 'max:4096', 'required_without:template_id'],
            'audience'    => ['required', 'in:all,groups'],
            'group_ids'   => ['array', 'required_if:audience,groups'],
            'group_ids.*' => [Rule::exists('contact_groups', 'id')->where('tenant_id', $tenantId)],
            'min_delay'   => ['required', 'integer', 'min:1', 'max:600'],
            'max_delay'   => ['required', 'integer', 'gte:min_delay', 'max:600'],
            'max_retries' => ['nullable', 'integer', 'min:0', 'max:10'],
            'schedule'    => ['required', 'in:now,later'],
            'scheduled_at' => ['nullable', 'date', 'after:now', 'required_if:schedule,later'],
        ];
    }
}
