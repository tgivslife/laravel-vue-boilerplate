<?php

namespace App\Console\Commands\Access;

use App\Models\User;
use App\Notifications\InactivityClosedNotification;
use App\Notifications\InactivityNoticeNotification;
use App\Services\Access\AccessAuditor;
use App\Services\Access\AccountRetirementService;
use App\Services\Settings\AppSettings;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

#[Signature('access:close-inactive-accounts
    {--dry-run : Report how many accounts would be closed or noticed without touching anything}')]
#[Description('Warn and then retire accounts inactive past the configured closure policy')]
class CloseInactiveAccountsCommand extends Command
{
    /**
     * Execute the console command.
     *
     * Two phases against the admin-editable inactivity_closure policy, both measuring inactivity from the durable
     * last-login summary (created_at for accounts that never signed in):
     *
     * - notice: accounts inactive for at least (inactive_days - notice_days) receive the pre-closure warning once,
     *   stamped in inactivity_notice_sent_at (a sign-in clears the stamp and withdraws the closure);
     * - closure: accounts whose stamp has aged past notice_days AND whose inactivity has reached inactive_days are
     *   retired through the shared AccountRetirementService path.
     *
     * The stamp ages the full notice window even when an account is already long past inactive_days
     * (the bulk case when the policy is first enabled), so no account is ever closed with less warning than the notice promised.
     *
     * Administratively frozen accounts (deactivated or banned) are skipped: their owners cannot sign in to stop the clock,
     * and their fate is the administrator's decision.
     */
    public function handle(AppSettings $settings, AccountRetirementService $retirement, AccessAuditor $auditor): int
    {
        $policy = (array) $settings->get('inactivity_closure');

        if (!(bool) ($policy['enabled'] ?? false)) {
            $this->info('Inactivity closure is disabled; nothing to do.');

            return self::SUCCESS;
        }

        $inactiveDays = (int) $policy['inactive_days'];
        $noticeDays = (int) $policy['notice_days'];

        if ((bool) $this->option('dry-run')) {
            $closable = $this->closureCandidates($inactiveDays, $noticeDays)->count();
            $noticeable = $this->noticeCandidates($inactiveDays, $noticeDays)->count();

            $this->info("[Dry run] Would close {$closable} accounts and send {$noticeable} closure notices.");

            return self::SUCCESS;
        }

        $closed = $this->closeNoticedAccounts($inactiveDays, $noticeDays, $retirement, $auditor);
        $noticed = $this->sendClosureNotices($inactiveDays, $noticeDays);

        $this->info("Closed {$closed} accounts; sent {$noticed} closure notices.");

        return self::SUCCESS;
    }

    /**
     * Retire every account whose notice has aged past the promised window and whose inactivity has reached the full period, then mail the confirmation.
     *
     * Runs before the notice phase so a single run never closes an account off a stamp it wrote moments earlier.
     *
     * Email and locale are snapshotted before retirement (the row's email is tombstoned by it), and the confirmation is routed on demand to that snapshot.
     * The closure is audited as user.inactivity_closed with the account itself as actor - the same convention user.self_provisioned
     * uses for events without a human administrator.
     */
    private function closeNoticedAccounts(
        int $inactiveDays,
        int $noticeDays,
        AccountRetirementService $retirement,
        AccessAuditor $auditor,
    ): int {
        $closed = 0;

        $this->closureCandidates($inactiveDays, $noticeDays)
            ->chunkById(100, function ($users) use ($retirement, $auditor, &$closed): void {
                foreach ($users as $user) {
                    $email = $user->email;
                    $locale = $user->preferredLocale();
                    $before = [
                        'email' => $email,
                        'roles' => $user->roles()->pluck('name')->sort()->values()->all(),
                    ];

                    DB::transaction(function () use ($user, $before, $retirement, $auditor): void {
                        $retirement->retire($user);

                        $auditor->record($user, 'user.inactivity_closed', $user, $before, null);
                    });

                    Notification::route('mail', $email)
                        ->notify(new InactivityClosedNotification()->locale($locale));

                    $closed++;
                }
            });

        return $closed;
    }

    /**
     * Send the pre-closure warning to every unnoticed account that has been inactive for at least the period minus the notice window, and stamp it sent.
     *
     * The stamp is saved quietly and without timestamps, like the last-login summary: policy bookkeeping is not a profile update.
     * The announced date is the earliest the closure phase can act on this stamp, so the mail's promise holds exactly.
     */
    private function sendClosureNotices(int $inactiveDays, int $noticeDays): int
    {
        $closureDate = now()->addDays($noticeDays);
        $noticed = 0;

        $this->noticeCandidates($inactiveDays, $noticeDays)
            ->chunkById(100, function ($users) use ($closureDate, &$noticed): void {
                foreach ($users as $user) {
                    User::withoutTimestamps(function () use ($user): void {
                        $user->forceFill(['inactivity_notice_sent_at' => now()])->saveQuietly();
                    });

                    $user->notify(new InactivityNoticeNotification($closureDate));

                    $noticed++;
                }
            });

        return $noticed;
    }

    /**
     * The accounts the closure phase would retire on this run: notice aged past the promised window, inactivity at the full period.
     * Shared by the live phase and the dry run, so the report can never drift from what a real run would do.
     *
     * @return Builder<User>
     */
    private function closureCandidates(int $inactiveDays, int $noticeDays): Builder
    {
        return $this->closableAccounts()
            ->where('inactivity_notice_sent_at', '<=', now()->subDays($noticeDays))
            ->where($this->inactiveSince(now()->subDays($inactiveDays)));
    }

    /**
     * The accounts the notice phase would warn on this run: unnoticed, and inactive for at least the period minus the notice window.
     *
     * @return Builder<User>
     */
    private function noticeCandidates(int $inactiveDays, int $noticeDays): Builder
    {
        return $this->closableAccounts()
            ->whereNull('inactivity_notice_sent_at')
            ->where($this->inactiveSince(now()->subDays($inactiveDays - $noticeDays)));
    }

    /**
     * The accounts the closure policy may touch: live rows that are neither deactivated nor banned.
     *
     * @return Builder<User>
     */
    private function closableAccounts(): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->whereNull('banned_at');
    }

    /**
     * Inactivity constraint against the durable last-login summary, falling back to created_at for accounts that never signed in.
     *
     * @return Closure(Builder<User>): void
     */
    private function inactiveSince(Carbon $cutoff): Closure
    {
        return static function (Builder $query) use ($cutoff): void {
            $query->where('last_login_at', '<=', $cutoff)
                ->orWhere(static function (Builder $query) use ($cutoff): void {
                    $query->whereNull('last_login_at')->where('created_at', '<=', $cutoff);
                });
        };
    }
}
