<?php

namespace App\Http\Controllers;

use App\Models\WebhookEndpoint;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WebhookEndpointController extends Controller
{
    /** Events a workspace can subscribe to. */
    public const EVENTS = ['message.received', 'campaign.completed', 'contact.opted_out'];

    public function index(): View
    {
        abort_unless(auth()->user()->isOwner(), 403);

        return view('webhooks.index', [
            'endpoints' => WebhookEndpoint::latest()->get(),
            'events'    => self::EVENTS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()->isOwner(), 403);

        $data = $request->validate([
            'url'      => ['required', 'url', 'max:255'],
            'events'   => ['required', 'array', 'min:1'],
            'events.*' => ['in:'.implode(',', self::EVENTS)],
        ]);

        $endpoint = WebhookEndpoint::create([
            'url'       => $data['url'],
            'events'    => array_values($data['events']),
            'secret'    => 'whsec_'.Str::random(32),
            'is_active' => true,
        ]);

        Audit::log('webhook.created', $endpoint, $endpoint->url);

        return back()->with('success', 'Webhook endpoint added.');
    }

    public function destroy(WebhookEndpoint $webhook): RedirectResponse
    {
        abort_unless(auth()->user()->isOwner(), 403);

        $webhook->delete();
        Audit::log('webhook.deleted', $webhook, $webhook->url);

        return back()->with('success', 'Webhook endpoint removed.');
    }
}
