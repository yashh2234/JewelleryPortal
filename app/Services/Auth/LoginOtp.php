<?php

namespace App\Services\Auth;

use App\Models\KaarigarUser;
use App\Models\RetailerUser;
use App\Models\User;
use App\Services\Sms\SmsSender;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Mobile + OTP sign-in for everyone in the workspace. The number decides who you
 * are: a staff member, a retailer's user or a kaarigar. If the same number is on
 * more than one account, staff wins, then retailer, then kaarigar.
 */
class LoginOtp
{
    public const TTL_MINUTES = 5;

    public const MAX_ATTEMPTS = 5;

    public function __construct(private SmsSender $sms, private TenantContext $context) {}

    /** "+91 98765-43210", "098765 43210" → "9876543210" (null if not an Indian mobile). */
    public static function normalise(string $input): ?string
    {
        $digits = preg_replace('/\D/', '', $input);
        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }

        return preg_match('/^[6-9]\d{9}$/', $digits) ? $digits : null;
    }

    /**
     * Who this number belongs to, in priority order.
     *
     * @return array{0: string, 1: Authenticatable}|null  [guard, user]
     */
    public function accountFor(string $phone): ?array
    {
        // Numbers are stored however people typed them (+91, spaces, dashes), so compare
        // normalised digits. These tables are small per workspace.
        $match = fn ($query) => $query->where('is_active', true)->whereNotNull('phone')
            ->where('phone', 'like', '%'.substr($phone, -4).'%')
            ->get()
            ->first(fn ($u) => self::normalise((string) $u->phone) === $phone);

        if ($user = $match(User::query())) {
            return ['staff', $user];
        }
        $retailerUser = $match(RetailerUser::query()->with('retailer'));
        if ($retailerUser && $retailerUser->retailer?->isActive()) {
            return ['retailer', $retailerUser];
        }
        if ($user = $match(KaarigarUser::query())) {
            return ['kaarigar', $user];
        }

        return null;
    }

    /** Returns the code (only for the local debug response); null if no account has this number. */
    public function send(string $phone): ?string
    {
        if (! $this->accountFor($phone)) {
            return null;
        }

        $code = (string) random_int(100000, 999999);

        DB::table('login_otps')->updateOrInsert(
            ['tenant_id' => $this->context->id(), 'phone' => $phone],
            ['code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(self::TTL_MINUTES), 'attempts' => 0, 'updated_at' => now(), 'created_at' => now()],
        );

        $this->sms->sendOtp($phone, $code);

        return $code;
    }

    /** @return array{0: string, 1: Authenticatable}|null  [guard, user] when the code is right */
    public function verify(string $phone, string $code): ?array
    {
        $scope = ['tenant_id' => $this->context->id(), 'phone' => $phone];
        $row = DB::table('login_otps')->where($scope)->first();

        if (! $row || now()->greaterThan($row->expires_at) || $row->attempts >= self::MAX_ATTEMPTS) {
            return null;
        }

        if (! Hash::check($code, $row->code_hash)) {
            DB::table('login_otps')->where($scope)->increment('attempts');

            return null;
        }

        DB::table('login_otps')->where($scope)->delete();   // single use

        $account = $this->accountFor($phone);
        if ($account && $account[1] instanceof KaarigarUser) {
            $account[1]->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $account;
    }
}
