<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\RetailerUser;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Forgot / reset password for staff and retailers (kaarigars use OTP, no password).
 * Tokens are per workspace and login type, single-use, valid for 60 minutes.
 */
class PasswordResetController extends Controller
{
    private const TTL_MINUTES = 60;

    public function __construct(private TenantContext $context) {}

    public function forgot(Request $request): JsonResponse
    {
        $email = strtolower($request->validate(['email' => ['required', 'email']])['email']);

        [$type, $user] = $this->find($email);

        if ($user) {
            $token = Str::random(64);

            DB::table('auth_reset_tokens')->where(['tenant_id' => $this->context->id(), 'email' => $email])->delete();
            DB::table('auth_reset_tokens')->insert([
                'tenant_id' => $this->context->id(),
                'user_type' => $type,
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
                'created_at' => now(),
            ]);

            $url = sprintf('%s/reset?token=%s&email=%s', $request->getSchemeAndHttpHost(), $token, urlencode($email));
            Notification::route('mail', $email)->notify(new ResetPasswordLink($url, $this->context->get()->name, self::TTL_MINUTES));
        }

        // Same answer either way, so emails can't be probed.
        return response()->json(['message' => 'If this email has an account, a reset link has been sent.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $email = strtolower($data['email']);

        $row = DB::table('auth_reset_tokens')
            ->where(['tenant_id' => $this->context->id(), 'email' => $email])
            ->where('expires_at', '>', now())
            ->first();

        if (! $row || ! hash_equals($row->token_hash, hash('sha256', $data['token']))) {
            throw ValidationException::withMessages(['token' => 'This reset link is invalid or has expired.']);
        }

        $model = $row->user_type === 'staff' ? User::class : RetailerUser::class;
        $user = $model::where('email', $email)->firstOrFail();

        DB::transaction(function () use ($user, $data, $email) {
            $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
            DB::table('auth_reset_tokens')->where(['tenant_id' => $this->context->id(), 'email' => $email])->delete();
        });

        return response()->json(['message' => 'Password changed. You can sign in now.']);
    }

    /** @return array{0: ?string, 1: User|RetailerUser|null} */
    private function find(string $email): array
    {
        if ($user = User::where('email', $email)->where('is_active', true)->first()) {
            return ['staff', $user];
        }

        if ($user = RetailerUser::where('email', $email)->where('is_active', true)->first()) {
            return ['retailer', $user];
        }

        return [null, null];
    }
}
