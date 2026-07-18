<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Message;
use App\Models\Template;
use App\Models\WhatsappInstance;
use App\Services\PlanLimit;
use App\Support\Whatsapp;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SingleMessageController extends Controller
{
    public function create(): View
    {
        return view('single-message.create', [
            'devices'   => WhatsappInstance::orderBy('name')->get(),
            'templates' => Template::orderBy('name')->get(),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $tenantId = Tenancy::id();

        $data = $request->validate([
            'whatsapp_instance_id' => ['required', Rule::exists('whatsapp_instances', 'id')->where('tenant_id', $tenantId)],
            'phone'                => ['required', 'string', 'max:32'],
            'template_id'          => ['nullable', Rule::exists('templates', 'id')->where('tenant_id', $tenantId)],
            'body'                 => ['nullable', 'string', 'max:4096', 'required_without:template_id'],
        ]);

        $device = WhatsappInstance::findOrFail($data['whatsapp_instance_id']);
        if (! $device->isConnected()) {
            return back()->withInput()->with('error', 'That WhatsApp number is not connected.');
        }

        if (PlanLimit::for(auth()->user()->tenant)->reached('monthly_messages')) {
            return back()->withInput()->with('error', "You've reached your plan's monthly message limit. Upgrade on the Billing page to send more.");
        }

        $phone = preg_replace('/\D+/', '', $data['phone']);
        $template = ! empty($data['template_id']) ? Template::find($data['template_id']) : null;

        $type = $template->type ?? 'text';
        $reference = trim((string) data_get(auth()->user()?->tenant?->settings, 'bulk_random_prefix', '')).random_int(100000, 999999);
        $body = str_ireplace(['{{name}}', '{{phone}}', '{{variant_ref_id}}', '{{ref_id}}', '{{reference_id}}', '{{random}}', '[random]', '[ref_id]', '[reference_id]'], ['there', $phone, $reference, $reference, $reference, $reference, $reference, $reference, $reference], (string) ($template->body ?? $data['body'] ?? ''));

        $engine = Whatsapp::forInstance($device);
        $name = $device->instance_name;

        // A poll can't carry text/media — send the message/image first, then the poll.
        if ($type === 'poll') {
            if ($template?->media_url) {
                $prelude = $engine->sendMedia($name, $phone, $template->media_type ?: 'image', $template->media_url, $body !== '' ? $body : null);
            } elseif (trim($body) !== '') {
                $prelude = $engine->sendText($name, $phone, $body);
            } else {
                $prelude = ['ok' => true, 'error' => null];
            }

            if (! $prelude['ok']) {
                return back()->withInput()->with('error', 'Poll prelude failed: '.($prelude['error'] ?? 'unknown error'));
            }
        }

        $result = match ($type) {
            'media'   => $engine->sendMedia($name, $phone, $template->media_type ?: 'image', $template->media_url, $body),
            'poll'    => $engine->sendPoll($name, $phone, data_get($template->poll, 'question', 'Poll'), data_get($template->poll, 'options', [])),
            'buttons' => $engine->sendButtons($name, $phone, data_get($template->buttons, 'title', 'Menu'), $body, data_get($template->buttons, 'footer'), collect(data_get($template->buttons, 'items', []))->map(fn ($b) => ['type' => $b['type'] ?? 'reply', 'displayText' => $b['text'] ?? ''])->all()),
            default   => $engine->sendText($name, $phone, $body ?: '—'),
        };

        if (! $result['ok']) {
            return back()->withInput()->with('error', 'Send failed: '.($result['error'] ?? 'unknown error'));
        }

        Message::create([
            'whatsapp_instance_id' => $device->id,
            'contact_id'           => Contact::where('phone', $phone)->value('id'),
            'direction'            => 'out',
            'phone'                => $phone,
            'type'                 => $type,
            'body'                 => $body,
            'status'               => 'sent',
            'message_id'           => $result['message_id'],
        ]);

        return back()->with('success', 'Message sent to +'.$phone.'.');
    }
}
