<?php

namespace App\Http\Controllers;

use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SecurityController extends Controller
{
    public function edit(): View
    {
        return view('security.edit', ['user' => auth()->user()]);
    }

    /**
     * Generate a candidate authenticator secret and show its setup key + QR.
     */
    public function setupTotp(Request $request): RedirectResponse
    {
        $secret = Totp::secret();
        $request->session()->put('2fa_setup_secret', $secret);

        return back()->with('totp_secret', $secret);
    }

    /**
     * Confirm the authenticator code, then enable TOTP.
     */
    public function enableTotp(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $secret = $request->session()->get('2fa_setup_secret');

        if (! $secret || ! Totp::verify($secret, $request->input('code'))) {
            return back()->with('error', 'That code did not match. Scan again and retry.')->with('totp_secret', $secret);
        }

        auth()->user()->update([
            'two_factor_enabled' => true,
            'two_factor_type'    => 'totp',
            'two_factor_secret'  => encrypt($secret),
        ]);
        $request->session()->forget('2fa_setup_secret');

        return back()->with('success', 'Authenticator 2FA is on.');
    }

    public function enableEmail(): RedirectResponse
    {
        auth()->user()->update([
            'two_factor_enabled' => true,
            'two_factor_type'    => 'email',
            'two_factor_secret'  => null,
        ]);

        return back()->with('success', 'Email OTP is on — you will get a code by email at login.');
    }

    public function disable(): RedirectResponse
    {
        auth()->user()->update([
            'two_factor_enabled' => false,
            'two_factor_type'    => null,
            'two_factor_secret'  => null,
        ]);

        return back()->with('success', 'Two-factor authentication turned off.');
    }
}
