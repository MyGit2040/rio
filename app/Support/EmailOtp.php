<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Email one-time-passcode as a second factor. The code (hashed) + expiry live in
 * the session, so no DB column is needed.
 */
class EmailOtp
{
    public static function issue(User $user): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        session([
            '2fa:code'    => Hash::make($code),
            '2fa:expires' => now()->addMinutes(10)->timestamp,
        ]);

        try {
            MailConfig::applyTenant($user->tenant);
            Mail::raw("Your {$user->tenant?->name} login code is: {$code}\n\nIt expires in 10 minutes.",
                fn ($m) => $m->to($user->email)->subject('Your login code'));
        } catch (\Throwable $e) {
            Log::error('Email OTP send failed', ['error' => $e->getMessage()]);
        }
    }

    public static function check(string $input): bool
    {
        $hash = session('2fa:code');
        $expires = session('2fa:expires');

        return $hash
            && $expires
            && now()->timestamp <= $expires
            && Hash::check(preg_replace('/\D/', '', $input), $hash);
    }
}
