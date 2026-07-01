<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    /**
     * Shown when a workspace is suspended or its subscription has expired.
     */
    public function inactive(): View|RedirectResponse
    {
        $tenant = auth()->user()->tenant;

        // Access restored → send them back into the app.
        if (! $tenant || ! $tenant->isBlocked()) {
            return redirect()->route('dashboard');
        }

        return view('subscription.inactive', [
            'reason' => $tenant->status === 'suspended' ? 'suspended' : 'expired',
            'tenant' => $tenant,
        ]);
    }
}
