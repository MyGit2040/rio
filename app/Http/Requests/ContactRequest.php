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
            'tags'       => ['array'],
            'tags.*'     => ['string', 'max:64'],
            'attributes' => ['array'],
            'groups'   => ['array'],
            'groups.*' => ['integer', 'exists:contact_groups,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => preg_replace('/\D+/', '', (string) $this->input('phone'))]);
        }

        // Tags arrive as a comma-separated string; normalise to a unique array.
        if ($this->has('tags') && is_string($this->input('tags'))) {
            $tags = collect(explode(',', $this->input('tags')))
                ->map(fn ($t) => trim($t))->filter()->unique()->values()->all();
            $this->merge(['tags' => $tags]);
        }

        // Custom fields arrive as parallel key[]/value[] arrays — zip into an assoc map.
        if ($this->has('attr_keys')) {
            $keys = (array) $this->input('attr_keys', []);
            $values = (array) $this->input('attr_values', []);
            $attributes = [];

            foreach ($keys as $i => $key) {
                $key = trim((string) $key);
                if ($key !== '') {
                    $attributes[$key] = (string) ($values[$i] ?? '');
                }
            }

            $this->merge(['attributes' => $attributes]);
        }

    }
}
