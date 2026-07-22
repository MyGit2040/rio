<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Message;
use App\Models\WhatsappInstance;
use App\Support\Personalizer;
use App\Support\Whatsapp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InboxController extends Controller
{
    /**
     * Conversation list — the latest message per phone number.
     */
    public function index(Request $request): View
    {
        $latestIds = Message::query()
            ->selectRaw('MAX(id) as id')
            ->when($request->filled('q'), fn ($q) => $q->where('phone', 'like', '%'.preg_replace('/\D+/', '', $request->input('q')).'%'))
            ->groupBy('phone')
            ->pluck('id');

        $conversations = Message::with('contact')
            ->whereIn('id', $latestIds)
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('inbox.index', compact('conversations'));
    }

    public function show(Contact $contact): View
    {
        $messages = $contact->messages()->orderBy('id')->get();
        $device = WhatsappInstance::where('status', 'open')->first();

        return view('inbox.show', compact('contact', 'messages', 'device'));
    }

    /**
     * Send a manual one-to-one reply and store it in the thread.
     */
    public function reply(Request $request, Contact $contact): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4096']]);

        // Prefer the device that last spoke with this contact; fall back to any connected one.
        $device = $contact->messages()->latest('id')->first()?->instance;
        if (! $device || ! $device->isConnected()) {
            $device = WhatsappInstance::where('status', 'open')->first();
        }

        if (! $device) {
            return back()->with('error', 'Connect a WhatsApp device first to reply.');
        }

        // Spintax {a|b}, {{merge}} tags and the random reference ID resolve
        // here too, so a pasted template reads clean in a one-to-one reply.
        $body = Personalizer::render($data['body'], $contact, $contact->phone, (array) (auth()->user()->tenant?->settings ?? []));

        $result = Whatsapp::forInstance($device)
            ->sendText($device->instance_name, $contact->phone, $body);

        if (! $result['ok']) {
            return back()->with('error', 'Could not send: '.($result['error'] ?? 'unknown error'));
        }

        Message::create([
            'whatsapp_instance_id' => $device->id,
            'contact_id'           => $contact->id,
            'direction'            => 'out',
            'phone'                => $contact->phone,
            'type'                 => 'text',
            'body'                 => $body,
            'status'               => 'sent',
            'message_id'           => $result['message_id'],
        ]);

        return back()->with('success', 'Reply sent.');
    }
}
