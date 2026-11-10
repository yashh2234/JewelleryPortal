<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\KaarigarUser;
use App\Models\Retailer;
use App\Models\RetailerUser;
use App\Models\User;
use App\Services\Auth\LoginOtp;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * One login for the whole app. The server works out who is signing in and starts
 * the matching guard; the front end then shows that person's dashboard.
 *
 *   email + password  → staff (manufacturer app), else retailer (portal)
 *   mobile + OTP      → whoever owns that number: staff, retailer or kaarigar
 *
 * The three guards stay separate underneath, so each API group still only accepts
 * its own kind of user.
 */
class SessionController extends Controller
{
    public const GUARDS = ['staff', 'retailer', 'kaarigar'];

    public function __construct(private LoginOtp $otp) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ]);

        $credentials = ['email' => strtolower($data['email']), 'password' => $data['password'], 'is_active' => true];
        $remember = $data['remember'] ?? false;

        // Staff first: if the same email exists as staff and as a retailer login, staff wins.
        foreach (['staff', 'retailer'] as $guard) {
            if (! Auth::guard($guard)->attempt($credentials, $remember)) {
                continue;
            }

            $user = Auth::guard($guard)->user();

            if ($user instanceof RetailerUser && ! $user->retailer->isActive()) {
                Auth::guard($guard)->logout();

                $message = $user->retailer->status === Retailer::STATUS_PENDING
                    ? 'Your account is waiting for approval.'
                    : 'Your account is not active.';

                return response()->json(['message' => $message, 'status' => $user->retailer->status], 403);
            }

            $this->logoutOthers($guard);
            $request->session()->regenerate();

            return response()->json($this->payload($guard, $user));
        }

        throw ValidationException::withMessages(['email' => __('auth.failed')]);
    }

    public function requestOtp(Request $request): JsonResponse
    {
        $phone = $this->phone($request);

        $code = $this->otp->send($phone);

        // Same answer whether or not the number exists, so numbers can't be probed.
        $response = ['message' => 'If this number is registered, an OTP has been sent.'];

        if ($code && config('tenancy.expose_otp') && app()->isLocal()) {
            $response['debug_otp'] = $code;
        }

        return response()->json($response);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $phone = $this->phone($request);
        $otp = $request->validate(['otp' => ['required', 'digits:6']])['otp'];

        $account = $this->otp->verify($phone, $otp);

        if (! $account) {
            throw ValidationException::withMessages(['otp' => 'The OTP is wrong or has expired.']);
        }

        [$guard, $user] = $account;
        $this->logoutOthers($guard);
        Auth::guard($guard)->login($user, remember: true);
        $request->session()->regenerate();

        return response()->json($this->payload($guard, $user));
    }

    /** Accept "+91 98765 43210", "09876543210", "9876543210". */
    private function phone(Request $request): string
    {
        $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $phone = LoginOtp::normalise((string) $request->input('phone'));

        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'Enter a 10-digit mobile number.']);
        }

        return $phone;
    }

    /** Who is signed in on this device: {type, user}, or 401. */
    public function me(): JsonResponse
    {
        foreach (self::GUARDS as $guard) {
            $user = Auth::guard($guard)->user();

            if ($user && $this->stillAllowed($guard, $user)) {
                return response()->json($this->payload($guard, $user));
            }
        }

        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    public function logout(Request $request): JsonResponse
    {
        foreach (self::GUARDS as $guard) {
            if (Auth::guard($guard)->check()) {
                Auth::guard($guard)->logout();
            }
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    private function stillAllowed(string $guard, Authenticatable $user): bool
    {
        return $user->is_active
            && ($guard !== 'retailer' || $user->retailer?->isActive());
    }

    /** One identity per device: signing in as one type signs out the others. */
    private function logoutOthers(string $keep): void
    {
        foreach (self::GUARDS as $guard) {
            if ($guard !== $keep && Auth::guard($guard)->check()) {
                Auth::guard($guard)->logout();
            }
        }
    }

    private function payload(string $guard, Authenticatable $user): array
    {
        /** @var User|RetailerUser|KaarigarUser $user */
        return ['type' => $guard, 'user' => $user->toAuthPayload()];
    }
}
