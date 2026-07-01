<?php

namespace App\Http\Requests;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $contactId = $this->route('contact')?->id;

        return [
            'name'     => ['nullable', 'string', 'max:255'],
            'phone'    => [
                'required', 'string', 'max:32',
                Rule::unique('contacts', 'phone')
                    ->where('tenant_id', Tenancy::id())
                    ->ignore($contactId),
            ],
            'email'    => ['nullable', 'email', 'max:255'],
            'country'  => ['nullable', 'string', 'max:64'],
            'opted_out' => ['sometimes', 'boolean'],
            'groups'   => ['array'],
            'groups.*' => ['integer', 'exists:contact_groups,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => preg_replace('/\D+/', '', (string) $this->input('phone'))]);
        }
    }
}
