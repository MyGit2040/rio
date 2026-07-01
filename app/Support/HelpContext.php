<?php

namespace App\Support;

/**
 * Maps the current route to the most relevant Help Center article, so the "?"
 * button on each page opens that page's guide first.
 */
class HelpContext
{
    /** article key => route-name patterns it covers */
    private const MAP = [
        'devices'         => ['devices.*', 'health.*'],
        'inbox'           => ['inbox.*'],
        'templates'       => ['templates.*', 'media.*', 'spam.*'],
        'contacts'        => ['contacts.*', 'groups.*'],
        'campaigns'       => ['campaigns.*', 'single-message.*', 'reports.*'],
        'sequences'       => ['sequences.*'],
        'chatbot'         => ['chatbot.*'],
        'compliance'      => ['suppressions.*'],
        'settings'        => ['settings.*', 'billing.*', 'users.*', 'api-tokens.*', 'webhook-endpoints.*', 'audit.*', 'backup.*', 'security.*'],
        'getting-started' => ['dashboard'],
    ];

    /**
     * The help article key for the current page, or null.
     */
    public static function articleForRoute(): ?string
    {
        foreach (self::MAP as $article => $patterns) {
            foreach ($patterns as $pattern) {
                if (request()->routeIs($pattern)) {
                    return $article;
                }
            }
        }

        return null;
    }
}
