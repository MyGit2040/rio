<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\LinkClick;
use App\Models\TrackedLink;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Rewrites the http(s) links in a message into short tracked links so clicks
 * can be counted, then records each click before redirecting the visitor on.
 */
class LinkTracker
{
    /**
     * Replace every URL in $body with a tracked short link owned by $campaign.
     * Identical URLs collapse to one tracked link (shared click count).
     *
     * Idempotent so an EDITED body can be re-wrapped safely: our own short
     * links are left untouched (never chain-wrapped) and a URL the campaign
     * already tracks reuses its existing token instead of minting a new one.
     */
    public function wrap(?string $body, Campaign $campaign): ?string
    {
        if (! $body || ! str_contains($body, 'http')) {
            return $body;
        }

        $made = [];
        $short = url('/l/');

        return preg_replace_callback('/\bhttps?:\/\/[^\s<>()"]+/i', function ($m) use ($campaign, &$made, $short) {
            $url = rtrim($m[0], '.,);');
            $suffix = substr($m[0], strlen($url)); // keep trailing punctuation outside the link

            if (str_starts_with($url, $short)) {
                return $m[0];
            }

            if (! isset($made[$url])) {
                $link = TrackedLink::firstOrCreate(
                    ['campaign_id' => $campaign->id, 'url' => $url],
                    ['token' => $this->uniqueToken()]
                );
                $made[$url] = url('/l/'.$link->token);
            }

            return $made[$url].$suffix;
        }, $body);
    }

    public function record(TrackedLink $link, Request $request): void
    {
        $link->increment('clicks');

        LinkClick::create([
            'tracked_link_id' => $link->id,
            'phone'           => $request->query('c'),
            'ip'              => $request->ip(),
            'user_agent'      => substr((string) $request->userAgent(), 0, 500),
            'created_at'      => now(),
        ]);
    }

    private function uniqueToken(): string
    {
        do {
            $token = Str::lower(Str::random(8));
        } while (TrackedLink::withoutGlobalScopes()->where('token', $token)->exists());

        return $token;
    }
}
