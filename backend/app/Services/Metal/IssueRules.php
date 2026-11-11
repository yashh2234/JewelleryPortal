<?php

namespace App\Services\Metal;

use App\Models\JobCard;
use App\Models\Kaarigar;
use App\Models\KaarigarUser;
use App\Models\MetalTransaction;
use App\Support\Metal\Weight;
use App\Support\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;

/**
 * Spec §6D / 16.3: a kaarigar never receives more gold until the previous issue is
 * recorded and reconciled. Before every issue we check:
 *
 *   disputed       an earlier issue was disputed and not sorted out (reversed)
 *   unacknowledged an issue older than 24 h was never accepted (app) or signed for (staff)
 *   excess         a closed job card has wastage above the limit nobody has resolved
 *   limit          the kaarigar would hold more fine gold than their limit
 *   overdue        open job cards past their due date (off unless the workspace turns it on)
 *
 * Someone with metal.approve_loss may override with a reason; the override is audited.
 */
class IssueRules
{
    public const DEFAULTS = ['disputed' => true, 'unacknowledged' => true, 'excess' => true, 'limit' => true, 'overdue' => false];

    public function __construct(private Ledger $ledger, private TenantContext $context) {}

    /** @return array<string, bool> */
    public function settings(): array
    {
        $saved = $this->context->check() ? ($this->context->get()->settings['issue_rules'] ?? []) : [];

        return array_merge(self::DEFAULTS, array_intersect_key((array) $saved, self::DEFAULTS));
    }

    /**
     * Reasons this kaarigar may not take more gold right now.
     *
     * @return list<string>
     */
    public function blockers(Kaarigar $kaarigar, string $incomingFine = '0'): array
    {
        $on = $this->settings();
        $reasons = [];

        // Factory issues and handoffs from other kaarigars both need his acknowledgement.
        $issues = MetalTransaction::whereIn('type', [MetalTransaction::ISSUE, MetalTransaction::HANDOFF])->where('kaarigar_id', $kaarigar->id)->whereNull('reversed_by_id');

        if ($on['disputed'] && ($n = (clone $issues)->where('kaarigar_ack', 'disputed')->pluck('voucher_no'))->isNotEmpty()) {
            $reasons[] = 'Disputed issue not resolved: '.$n->implode(', ').'.';
        }

        if ($on['unacknowledged']) {
            $n = (clone $issues)->whereNull('kaarigar_ack')->where('created_at', '<', now()->subDay())->pluck('voucher_no');
            if ($n->isNotEmpty()) {
                $how = KaarigarUser::where('kaarigar_id', $kaarigar->id)->where('is_active', true)->exists()
                    ? 'accept them in the kaarigar app, or mark the signed slip'
                    : 'mark the signed slip as received';
                $reasons[] = "Previous issue not acknowledged for over 24 h ({$n->implode(', ')}): {$how}.";
            }
        }

        if ($on['excess']) {
            $n = JobCard::where('kaarigar_id', $kaarigar->id)->where('status', JobCard::CLOSED)
                ->where('loss_status', JobCard::LOSS_PENDING)->where('excess_loss_fine', '>', 0)->pluck('card_no');
            if ($n->isNotEmpty()) {
                $reasons[] = 'Gold difference above the limit not approved on '.$n->implode(', ').'.';
            }
        }

        if ($on['overdue']) {
            $n = JobCard::where('kaarigar_id', $kaarigar->id)->where('status', JobCard::OPEN)
                ->whereNotNull('due_date')->where('due_date', '<', today()->toDateString())->pluck('card_no');
            if ($n->isNotEmpty()) {
                $reasons[] = 'Overdue job cards still open: '.$n->implode(', ').'.';
            }
        }

        if ($on['limit'] && $kaarigar->max_fine_balance !== null) {
            $held = $this->ledger->balance($this->ledger->kaarigar($kaarigar))['fine'];
            $after = Weight::add($held, $incomingFine);
            if (Weight::compare($after, (string) $kaarigar->max_fine_balance) > 0) {
                $reasons[] = "{$kaarigar->name} would hold {$after} g fine, above the limit of {$kaarigar->max_fine_balance} g (now {$held} g).";
            }
        }

        return $reasons;
    }

    public function assert(Kaarigar $kaarigar, string $incomingFine, ?string $override): void
    {
        $reasons = $this->blockers($kaarigar, $incomingFine);
        if ($reasons === []) {
            return;
        }

        $user = Auth::guard('staff')->user();
        if ($override && $user?->can(Permissions::METAL_APPROVE_LOSS)) {
            activity('gold-issue-override')->causedBy($user)->performedOn($kaarigar)
                ->withProperties(['reasons' => $reasons, 'override_reason' => $override])
                ->log("Issue rules overridden for {$kaarigar->name}: {$override}");

            return;
        }

        throw new LedgerException('Cannot issue more gold to '.$kaarigar->name.'. '.implode(' ', $reasons)
            .($user?->can(Permissions::METAL_APPROVE_LOSS) ? ' (You can override with a reason.)' : ''));
    }
}
