<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Message;
use App\Models\WhatsappInstance;
use App\Services\PlanLimit;
use App\Support\LocalTime;
use App\Support\Whatsapp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * The multi-account chat workspace: one browser-style tab per connected
 * WhatsApp number, each with its own conversation list and live thread —
 * "all my WhatsApp accounts in one page".
 *
 * Reading is pure database (no gateway calls), so browsing chats puts zero
 * load on the WhatsApp sessions. Sending goes through the SAME engine path
 * the Inbox/Single-message pages already use, and the stored outbound row
 * counts toward the device's daily cap exactly like every other manual send —
 * campaign pacing, warm-up and quiet hours are untouched.
 */
class ChatController extends Controller
{
    public function index(): View
    {
        $devices = WhatsappInstance::orderBy('name')->get();

        return view('chats.index', [
            'devices' => $devices,
            // Ready-made tab payload: @json() cannot take an expression with
            // top-level commas (it splits its argument on them), so the array
            // is built here and the blade passes a single variable.
            'deviceTabs' => $devices->map(fn (WhatsappInstance $d) => [
                'id'     => $d->id,
                'name'   => $d->name,
                'phone'  => $d->phone_number,
                'status' => $d->status,
            ])->values(),
        ]);
    }

    /**
     * Conversation list for ONE device — the latest message per phone number.
     */
    public function conversations(Request $request, WhatsappInstance $device): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $digits = preg_replace('/\D+/', '', $q);

        $latestIds = Message::query()
            ->where('whatsapp_instance_id', $device->id)
            ->when($q !== '', function ($query) use ($q, $digits) {
                $query->where(function ($w) use ($q, $digits) {
                    if ($digits !== '') {
                        $w->orWhere('phone', 'like', '%'.$digits.'%');
                    }
                    $w->orWhereIn('contact_id', Contact::where('name', 'like', '%'.$q.'%')->pluck('id'));
                });
            })
            ->selectRaw('MAX(id) as id')
            ->groupBy('phone')
            ->pluck('id');

        $rows = Message::with('contact')
            ->whereIn('id', $latestIds)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'device' => [
                'id'         => $device->id,
                'status'     => $device->status,
                'sent_today' => $device->sentToday(),
                'daily_cap'  => $device->effectiveDailyCap(),
            ],
            'conversations' => $rows->map(fn (Message $m) => [
                'phone'          => $m->phone,
                'name'           => $m->contact?->name,
                'contact_id'     => $m->contact_id,
                'last_id'        => $m->id,
                'last_direction' => $m->direction,
                'last_type'      => $m->type,
                'last_body'      => (string) ($m->body ?: ''),
                'last_at'        => LocalTime::format($m->created_at, 'M j, g:i A'),
            ])->values(),
        ]);
    }

    /**
     * One conversation on one device. Pass after_id to fetch only what's new
     * (the polling path); without it the latest 300 messages are returned.
     */
    public function thread(Request $request, WhatsappInstance $device): JsonResponse
    {
        $data = $request->validate([
            'phone'    => ['required', 'string', 'max:32'],
            'after_id' => ['nullable', 'integer', 'min:0'],
        ]);

        $phone = preg_replace('/\D+/', '', $data['phone']);
        $after = (int) ($data['after_id'] ?? 0);

        $query = Message::where('whatsapp_instance_id', $device->id)->where('phone', $phone);

        $messages = $after > 0
            ? $query->where('id', '>', $after)->orderBy('id')->limit(300)->get()
            : $query->orderByDesc('id')->limit(300)->get()->reverse()->values();

        $contact = Contact::where('phone', $phone)->first();

        return response()->json([
            'contact'  => $contact ? ['id' => $contact->id, 'name' => $contact->name] : null,
            'messages' => $messages->map(fn (Message $m) => $this->presentMessage($m))->values(),
        ]);
    }

    /**
     * Send a message from THIS device (the active tab) — text, or media that was
     * first uploaded via the shared /uploads endpoint.
     */
    public function send(Request $request, WhatsappInstance $device): JsonResponse
    {
        $data = $request->validate([
            'phone'      => ['required', 'string', 'max:32'],
            'body'       => ['nullable', 'string', 'max:4096', 'required_without:media_url'],
            'media_url'  => ['nullable', 'url', 'max:2048'],
            'media_type' => ['nullable', 'in:image,video,audio,document'],
            'media_name' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $device->isConnected()) {
            return response()->json(['ok' => false, 'error' => 'This WhatsApp number is not connected. Reconnect it on the Devices page.'], 422);
        }

        if (PlanLimit::for(auth()->user()->tenant)->reached('monthly_messages')) {
            return response()->json(['ok' => false, 'error' => "You've reached your plan's monthly message limit. Upgrade on the Billing page to send more."], 422);
        }

        $phone = preg_replace('/\D+/', '', $data['phone']);

        if ($phone === '') {
            return response()->json(['ok' => false, 'error' => 'Enter a valid phone number.'], 422);
        }

        $body = trim((string) ($data['body'] ?? ''));
        $mediaUrl = $data['media_url'] ?? null;
        $engine = Whatsapp::forInstance($device);

        $result = $mediaUrl
            ? $engine->sendMedia(
                $device->instance_name,
                $phone,
                $data['media_type'] ?? 'image',
                $mediaUrl,
                $body !== '' ? $body : null,
                $data['media_name'] ?? null,
            )
            : $engine->sendText($device->instance_name, $phone, $body);

        if (! $result['ok']) {
            Log::error('Chat send failed', [
                'instance_id' => $device->id,
                'phone'       => $phone,
                'error'       => $result['error'] ?? 'unknown error',
            ]);

            return response()->json(['ok' => false, 'error' => 'Could not send: '.($result['error'] ?? 'unknown error')], 502);
        }

        // A brand-new number becomes a contact so the thread carries a name later.
        $contact = Contact::firstOrCreate(['phone' => $phone], ['name' => null]);

        $message = Message::create([
            'whatsapp_instance_id' => $device->id,
            'contact_id'           => $contact->id,
            'direction'            => 'out',
            'phone'                => $phone,
            'type'                 => $mediaUrl ? ($data['media_type'] ?? 'image') : 'text',
            'body'                 => $body !== '' ? $body : ($data['media_name'] ?? null),
            'status'               => 'sent',
            'message_id'           => $result['message_id'],
        ]);

        return response()->json(['ok' => true, 'message' => $this->presentMessage($message)]);
    }

    /** One thread bubble, times already in the workspace timezone. */
    private function presentMessage(Message $m): array
    {
        return [
            'id'        => $m->id,
            'direction' => $m->direction,
            'type'      => $m->type,
            'body'      => (string) ($m->body ?: ''),
            'status'    => $m->status,
            'at'        => LocalTime::format($m->created_at, 'M j, g:i A'),
        ];
    }
}
