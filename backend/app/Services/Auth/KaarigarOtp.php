<?php

namespace App\Services\Auth;

use App\Models\KaarigarUser;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Hash;

/** Phone + OTP login for the kaarigar app. */
class KaarigarOtp
{
    public const TTL_MINUTES = 5;

    public const MAX_ATTEMPTS = 5;

    public function __construct(private SmsSender $sms) {}

    /** Returns the code (for the local debug response only); null if no such active user. */
    public function send(string $phone): ?string
    {
        $user = KaarigarUser::where('phone', $phone)->where('is_active', true)->first();

        if (! $user) {
            return null;
        }

        $code = (string) random_int(100000, 999999);

        $user->forceFill([
            'otp_hash' => Hash::make($code),
            'otp_expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'otp_attempts' => 0,
        ])->saveQuietly();

        $this->sms->sendOtp($phone, $code);

        return $code;
    }

    public function verify(string $phone, string $code): ?KaarigarUser
    {
        $user = KaarigarUser::where('phone', $phone)->where('is_active', true)->first();

        if (! $user || ! $user->otp_hash || $user->otp_expires_at?->isPast()
            || $user->otp_attempts >= self::MAX_ATTEMPTS) {
            return null;
        }

        if (! Hash::check($code, $user->otp_hash)) {
            $user->increment('otp_attempts');

            return null;
        }

        $user->forceFill([
            'otp_hash' => null,
            'otp_expires_at' => null,
            'otp_attempts' => 0,
            'last_seen_at' => now(),
        ])->saveQuietly();

        return $user;
    }
}
