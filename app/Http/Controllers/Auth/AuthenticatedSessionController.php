<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\EmailOtp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $this->verifyRecaptcha($request);

        $request->authenticate();

        $user = Auth::user();

        // Second factor: hold the login, send to the challenge screen.
        if ($user->two_factor_enabled) {
            Auth::logout();
            $request->session()->put('2fa:user', $user->id);
            $request->session()->put('2fa:remember', $request->boolean('remember'));

            if ($user->two_factor_type === 'email') {
                EmailOtp::issue($user);
            }

            return redirect()->route('two-factor.show');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Verify reCAPTCHA v3 — only when keys are configured (otherwise a no-op).
     */
    private function verifyRecaptcha(Request $request): void
    {
        $secret = config('services.recaptcha.secret');
        if (! $secret) {
            return;
        }

        $result = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret'   => $secret,
            'response' => $request->input('g-recaptcha-response'),
            'remoteip' => $request->ip(),
        ])->json();

        if (! ($result['success'] ?? false) || ($result['score'] ?? 0) < config('services.recaptcha.min_score')) {
            throw ValidationException::withMessages(['email' => 'Failed the anti-bot check. Please try again.']);
        }
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
