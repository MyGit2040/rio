<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\EmailOtp;
use App\Support\Totp;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TwoFactorController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('2fa:user')) {
            return redirect()->route('login');
        }

        $user = User::find($request->session()->get('2fa:user'));

        return view('auth.two-factor', ['method' => $user?->two_factor_type ?? 'totp']);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $user = User::find($request->session()->get('2fa:user'));
        if (! $user) {
            return redirect()->route('login');
        }

        $ok = $user->two_factor_type === 'email'
            ? EmailOtp::check($request->input('code'))
            : Totp::verify(decrypt($user->two_factor_secret), $request->input('code'));

        if (! $ok) {
            return back()->with('error', 'That code is invalid or has expired.');
        }

        Auth::login($user, (bool) $request->session()->pull('2fa:remember', false));
        $request->session()->forget(['2fa:user', '2fa:code', '2fa:expires']);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = User::find($request->session()->get('2fa:user'));

        if ($user && $user->two_factor_type === 'email') {
            EmailOtp::issue($user);

            return back()->with('success', 'A new code has been emailed to you.');
        }

        return back();
    }
}
