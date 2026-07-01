<?php

namespace App\Http\Controllers;

use App\Models\TrackedLink;
use App\Services\LinkTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LinkController extends Controller
{
    /**
     * Public tracked-link redirect: record the click, then forward to the real URL.
     * No auth — the token itself is the lookup key (tenant scope is null for guests).
     */
    public function click(Request $request, string $token, LinkTracker $tracker): RedirectResponse
    {
        $link = TrackedLink::withoutGlobalScopes()->where('token', $token)->first();

        if (! $link) {
            abort(404);
        }

        $tracker->record($link, $request);

        return redirect()->away($link->url);
    }
}
