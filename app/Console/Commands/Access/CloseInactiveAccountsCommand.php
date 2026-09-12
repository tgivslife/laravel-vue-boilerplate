<?php

namespace App\Console\Commands\Access;

use App\Models\User;
use App\Notifications\InactivityClosedNotification;
use App\Notifications\InactivityNoticeNotification;
use App\Services\Access\AccessControlService;
use App\Services\Settings\AppSettings;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

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
     *   retired through the guarded access transaction, which runs the shared AccountRetirementService path.
     *
     * The stamp ages the full notice window even when an account is already long past inactive_days
     * (the bulk case when the policy is first enabled), so no account is ever closed with less warning than the notice promised.
     *
     * Administratively frozen accounts (deactivated or banned) are skipped: their owners cannot sign in to stop the clock,
     * and their fate is the administrator's decision. So is the last active holder of a lockout permission, held back
     * from both phases and reported: closing them would leave nobody able to administer that capability.
     */
    public function handle(AppSettings $settings, AccessControlService $accessControl): int
    {
        $policy = (array) $settings->get('inactivity_closure');

        if (!(bool) ($policy['enabled'] ?? false)) {
            $this->info('Inactivity closure is disabled; nothing to do.');

            return self::SUCCESS;
        }

        $inactiveDays = (int) $policy['inactive_days'];
        $noticeDays = (int) $policy['notice_days'];
        $lastHolderIds = $accessControl->lastActiveHolderIds();

        if ((bool) $this->option('dry-run')) {
            $closable = $this->closureCandidates($inactiveDays, $noticeDays)->whereNotIn('id', $lastHolderIds)->count();
            $noticeable = $this->noticeCandidates($inactiveDays, $noticeDays)->whereNotIn('id',
                $lastHolderIds)->count();
            $heldBack = $this->closureCandidates($inactiveDays, $noticeDays)->whereIn('id', $lastHolderIds)->count()
                + $this->noticeCandidates($inactiveDays, $noticeDays)->whereIn('id', $lastHolderIds)->count();

            $this->info("[Dry run] Would close {$closable} accounts and send {$noticeable} closure notices.");
            $this->reportHeldBack($heldBack);

            return self::SUCCESS;
        }

        [$closed, $heldBack] = $this->closeNoticedAccounts($inactiveDays, $noticeDays, $lastHolderIds, $accessControl);
        [$noticed, $heldBackFromNotice] = $this->sendClosureNotices($inactiveDays, $noticeDays, $lastHolderIds);

        $this->info("Closed {$closed} accounts; sent {$noticed} closure notices.");
        $this->reportHeldBack($heldBack + $heldBackFromNotice);

        return self::SUCCESS;
    }

    private function reportHeldBack(int $count): void
    {
        if ($count > 0) {
            $this->warn("Held back {$count} accounts: the last active holder of a protected access permission.");
        }
    }

    /**
     * Retire every account whose notice has aged past the promised window and whose inactivity has reached the full period, then mail the confirmation.
     *
     * Runs before the notice phase so a single run never closes an account off a stamp it wrote moments earlier.
     *
     * Email and locale are snapshot before retirement (the row's email is tombstoned by it), and the confirmation is routed on demand to that snapshot.
     * The closure runs through the guarded access transaction (AccessControlService::closeInactiveAccount), audited as
     * user.inactivity_closed with the account itself as actor. The last holders planned around are excluded up front;
     * one that became the last holder since the plan is refused under the lock and counted as held back the same way.
     *
     * @param  list<int>  $lastHolderIds
     * @return array{0: int, 1: int} closed and held back
     */
    private function closeNoticedAccounts(
        int $inactiveDays,
        int $noticeDays,
        array $lastHolderIds,
        AccessControlService $accessControl,
    ): array {
        $closed = 0;
        $heldBack = $this->closureCandidates($inactiveDays, $noticeDays)->whereIn('id', $lastHolderIds)->count();

        $this->closureCandidates($inactiveDays, $noticeDays)
            ->whereNotIn('id', $lastHolderIds)
            ->chunkById(100, function ($users) use ($accessControl, &$closed, &$heldBack): void {
                foreach ($users as $user) {
                    $email = $user->email;
                    $locale = $user->preferredLocale();

                    try {
                        $accessControl->closeInactiveAccount($user);
                    } catch (ValidationException) {
                        $heldBack++;

                        continue;
                    }

                    Notification::route('mail', $email)
                        ->notify(new InactivityClosedNotification()->locale($locale));

                    $closed++;
                }
            });

        return [$closed, $heldBack];
    }

    /**
     * Send the pre-closure warning to every unnoticed account that has been inactive for at least the period minus the notice window, and stamp it sent.
     *
     * The stamp is saved quietly and without timestamps, like the last-login summary: policy bookkeeping is not a profile update.
     * The announced date is the earliest the closure phase can act on this stamp, so the mail's promise holds exactly.
     * Last holders get no notice either: the mail would promise a closure the command will refuse.
     *
     * @param  list<int>  $lastHolderIds
     * @return array{0: int, 1: int} noticed and held back
     */
    private function sendClosureNotices(int $inactiveDays, int $noticeDays, array $lastHolderIds): array
    {
        $closureDate = now()->addDays($noticeDays);
        $noticed = 0;
        $heldBack = $this->noticeCandidates($inactiveDays, $noticeDays)->whereIn('id', $lastHolderIds)->count();

        $this->noticeCandidates($inactiveDays, $noticeDays)
            ->whereNotIn('id', $lastHolderIds)
            ->chunkById(100, function ($users) use ($closureDate, &$noticed): void {
                foreach ($users as $user) {
                    User::withoutTimestamps(function () use ($user): void {
                        $user->forceFill(['inactivity_notice_sent_at' => now()])->saveQuietly();
                    });

                    $user->notify(new InactivityNoticeNotification($closureDate));

                    $noticed++;
                }
            });

        return [$noticed, $heldBack];
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
