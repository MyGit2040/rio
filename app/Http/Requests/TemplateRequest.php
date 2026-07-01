<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:255'],
            'type'          => ['required', 'in:text,media,poll,buttons,carousel'],
            'cards'         => ['array', 'max:10'],
            'cards.*.image' => ['nullable', 'url', 'max:2048'],
            'cards.*.title' => ['nullable', 'string', 'max:255'],
            'cards.*.body'  => ['nullable', 'string', 'max:1024'],
            'cards.*.buttons' => ['array', 'max:2'],
            'cards.*.buttons.*.type'  => ['nullable', 'in:reply,url'],
            'cards.*.buttons.*.text'  => ['nullable', 'string', 'max:60'],
            'cards.*.buttons.*.value' => ['nullable', 'string', 'max:512'],
            'buttons_title' => ['nullable', 'string', 'max:255', 'required_if:type,buttons'],
            'buttons_footer' => ['nullable', 'string', 'max:255'],
            'buttons'       => ['array', 'max:3'],
            'buttons.*.type' => ['nullable', 'in:reply,url,call'],
            'buttons.*.text' => ['nullable', 'string', 'max:60'],
            'buttons.*.value' => ['nullable', 'string', 'max:512'],
            'body'          => ['nullable', 'string', 'max:4096', 'required_if:type,text'],
            'footer'        => ['nullable', 'string', 'max:1000'],
            'variants'      => ['array'],
            'variants.*'    => ['nullable', 'string', 'max:4096'],
            'media_url'     => ['nullable', 'url', 'max:2048', 'required_if:type,media'],
            'media_type'    => ['nullable', 'in:image,video,document,audio', 'required_if:type,media'],
            'poll_question' => ['nullable', 'string', 'max:255', 'required_if:type,poll'],
            'poll_options'  => ['array'],
            'poll_options.*' => ['nullable', 'string', 'max:100'],
            'poll_multiple' => ['sometimes', 'boolean'],
            'poll_media_url' => ['nullable', 'url', 'max:2048'],
            'poll_media_type' => ['nullable', 'in:image,video,document,audio'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Drop blank poll option rows before validation.
        if ($this->has('poll_options')) {
            $options = array_values(array_filter(
                array_map('trim', (array) $this->input('poll_options')),
                fn ($o) => $o !== ''
            ));
            $this->merge(['poll_options' => $options]);
        }

        // Clean carousel cards: drop empty cards + empty per-card buttons.
        if ($this->has('cards')) {
            $cards = array_values(array_filter(array_map(function ($card) {
                if (! is_array($card)) {
                    return null;
                }
                $card['buttons'] = array_values(array_filter(
                    (array) ($card['buttons'] ?? []),
                    fn ($b) => is_array($b) && trim((string) ($b['text'] ?? '')) !== ''
                ));

                return $card;
            }, (array) $this->input('cards')), fn ($c) => $c && (
                trim((string) ($c['image'] ?? '')) !== '' ||
                trim((string) ($c['title'] ?? '')) !== '' ||
                trim((string) ($c['body'] ?? '')) !== ''
            )));
            $this->merge(['cards' => $cards]);
        }

        // Drop blank button rows before validation.
        if ($this->has('buttons')) {
            $buttons = array_values(array_filter(
                (array) $this->input('buttons'),
                fn ($b) => is_array($b) && trim((string) ($b['text'] ?? '')) !== ''
            ));
            $this->merge(['buttons' => $buttons]);
        }

        // Drop blank message-variant rows before validation.
        if ($this->has('variants')) {
            $variants = array_values(array_filter(
                array_map('trim', (array) $this->input('variants')),
                fn ($v) => $v !== ''
            ));
            $this->merge(['variants' => $variants]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->input('type') === 'poll' && count($this->input('poll_options', [])) < 2) {
                $v->errors()->add('poll_options', 'A poll needs at least two options.');
            }

            if ($this->input('type') === 'buttons' && count($this->input('buttons', [])) < 1) {
                $v->errors()->add('buttons', 'Add at least one button.');
            }

            if ($this->input('type') === 'carousel' && count($this->input('cards', [])) < 1) {
                $v->errors()->add('cards', 'Add at least one card.');
            }
        });
    }

    /**
     * Build the persistable attributes for a Template from this request.
     */
    public function toTemplate(): array
    {
        $type = $this->input('type');

        $mediaUrl = match ($type) {
            'media' => $this->input('media_url'),
            'poll'  => $this->input('poll_media_url'),
            default => null,
        };
        $mediaType = match ($type) {
            'media' => $this->input('media_type'),
            'poll'  => $this->input('poll_media_url') ? ($this->input('poll_media_type') ?: 'image') : null,
            default => null,
        };

        return [
            'name'       => $this->input('name'),
            'type'       => $type,
            'body'       => $this->input('body'),
            'footer'     => $this->input('footer') ?: null,
            'variants'   => $this->input('variants', []) ?: null,
            'media_url'  => $mediaUrl,
            'media_type' => $mediaType,
            'poll'       => $type === 'poll' ? [
                'question' => $this->input('poll_question'),
                'options'  => $this->input('poll_options', []),
                'multiple' => $this->boolean('poll_multiple'),
            ] : null,
            'buttons'    => $type === 'buttons' ? [
                'title'  => $this->input('buttons_title'),
                'footer' => $this->input('buttons_footer'),
                'items'  => array_map(fn ($b) => [
                    'type'  => $b['type'] ?? 'reply',
                    'text'  => $b['text'] ?? '',
                    'value' => $b['value'] ?? null,
                ], $this->input('buttons', [])),
            ] : null,
            'cards'      => $type === 'carousel' ? array_map(fn ($c) => [
                'image'   => $c['image'] ?? null,
                'title'   => $c['title'] ?? null,
                'body'    => $c['body'] ?? null,
                'buttons' => array_map(fn ($b) => [
                    'type'  => $b['type'] ?? 'reply',
                    'text'  => $b['text'] ?? '',
                    'value' => $b['value'] ?? null,
                ], (array) ($c['buttons'] ?? [])),
            ], $this->input('cards', [])) : null,
        ];
    }
}
