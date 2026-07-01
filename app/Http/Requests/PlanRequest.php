<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $planId = $this->route('plan')?->id;

        return [
            'key'            => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/', Rule::unique('plans', 'key')->ignore($planId)],
            'name'           => ['required', 'string', 'max:255'],
            'description'    => ['nullable', 'string', 'max:400'],
            'price'          => ['required', 'numeric', 'min:0'],
            'annual_price'   => ['nullable', 'numeric', 'min:0'],
            'billing_period' => ['required', Rule::in(['monthly', 'yearly', 'one_time'])],
            'limit_devices'          => ['required', 'integer', 'min:0'],
            'limit_contacts'         => ['required', 'integer', 'min:0'],
            'limit_monthly_messages' => ['required', 'integer', 'min:0'],
            'features'       => ['nullable', 'string', 'max:2000'],
            'is_popular'     => ['boolean'],
            'is_default'     => ['boolean'],
            'is_active'      => ['boolean'],
            'sort_order'     => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'key.regex' => 'The key may only contain lowercase letters, numbers, dashes and underscores.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPlan(): array
    {
        return [
            'key'            => $this->input('key'),
            'name'           => $this->input('name'),
            'description'    => $this->input('description'),
            'price'          => $this->input('price'),
            'annual_price'   => $this->input('annual_price') ?: null,
            'billing_period' => $this->input('billing_period'),
            'limits'         => [
                'devices'          => (int) $this->input('limit_devices'),
                'contacts'         => (int) $this->input('limit_contacts'),
                'monthly_messages' => (int) $this->input('limit_monthly_messages'),
            ],
            'features'   => $this->input('features'),
            'is_popular' => $this->boolean('is_popular'),
            'is_default' => $this->boolean('is_default'),
            'is_active'  => $this->boolean('is_active'),
            'sort_order' => (int) $this->input('sort_order', 0),
        ];
    }
}
