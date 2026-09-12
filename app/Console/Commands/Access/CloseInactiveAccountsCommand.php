<?php

namespace App\Console\Commands\Access;

use App\Models\User;
use App\Notifications\InactivityClosedNotification;
use App\Notifications\InactivityNoticeNotification;
use App\Services\Access\AccessControlService;
use App\Services\Settings\AppSettings;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Warns and then retires accounts inactive past the admin-editable inactivity_closure policy, scheduled daily.
 *
 * Two phases, both measuring inactivity from the durable last-login summary (created_at for accounts that never signed in):
 *  - notice: accounts inactive for at least inactive_days minus notice_days receive the pre-closure warning once,
 *    stamped in inactivity_notice_sent_at; a sign-in clears the stamp and withdraws the closure.
 *  - closure: accounts whose stamp has aged past notice_days and whose inactivity has reached inactive_days are
 *    retired through the guarded access transaction, the shared AccountRetirementService path.
 * The stamp ages the full notice window even for an account long past inactive_days (the bulk case when the policy is first enabled),
 * so no account is closed with less warning than the notice promised.
 *
 * Skipped: deactivated and banned accounts, whose owners cannot sign in to stop the clock and whose fate is the administrator's,
 * and the last active holder of a lockout permission, held back from both phases and reported, since closing them would
 * leave nobody able to administer that capability.
 * Both phases decide on the row as it is at the moment of the write, not as the batch loaded it: an account that signed in,
 * was deactivated or was banned since the batch was planned is withdrawn from the run and reported.
 */
#[Signature('access:close-inactive-accounts
    {--dry-run : Report how many accounts would be closed or noticed without touching anything}')]
#[Description('Warn and then retire accounts inactive past the configured closure policy')]
class CloseInactiveAccountsCommand extends Command
{
    /**
     * Execute the console command.
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

        [$closed, $withdrawn, $heldBack] = $this->closeNoticedAccounts($inactiveDays, $noticeDays, $lastHolderIds,
            $accessControl);
        [$noticed, $withdrawnFromNotice, $heldBackFromNotice] = $this->sendClosureNotices($inactiveDays, $noticeDays,
            $lastHolderIds);

        $this->info("Closed {$closed} accounts; sent {$noticed} closure notices.");
        $this->reportWithdrawn($withdrawn + $withdrawnFromNotice);
        $this->reportHeldBack($heldBack + $heldBackFromNotice);

        return self::SUCCESS;
    }

    private function reportWithdrawn(int $count): void
    {
        if ($count > 0) {
            $this->info("Withdrawn {$count} accounts: signed in, deactivated or banned since the run was planned.");
        }
    }

    private function reportHeldBack(int $count): void
    {
        if ($count > 0) {
            $this->warn("Held back {$count} accounts: the last active holder of a protected access permission.");
        }
    }

    /**
     * Retire every closure candidate and mail the confirmation. Runs before the notice phase, so one run never
     * closes an account off a stamp it wrote moments earlier.
     *
     * Each closure re-selects the row under the lock through the same criteria; one no longer matching is withdrawn,
     * and one that became a last holder since the plan is refused there and counted as held back like the ones planned around.
     * Email and locale are snapshot first, since the retirement tombstones the address.
     *
     * @param  list<int>  $lastHolderIds
     * @return array{0: int, 1: int, 2: int} closed, withdrawn and held back
     */
    private function closeNoticedAccounts(
        int $inactiveDays,
        int $noticeDays,
        array $lastHolderIds,
        AccessControlService $accessControl
    ): array {
        $closed = 0;
        $withdrawn = 0;
        $heldBack = $this->closureCandidates($inactiveDays, $noticeDays)->whereIn('id', $lastHolderIds)->count();
        $criteria = fn(Builder $query): Builder => $this->closureCriteria($query, $inactiveDays, $noticeDays);

        $this->closureCandidates($inactiveDays, $noticeDays)
            ->whereNotIn('id', $lastHolderIds)
            ->chunkById(100,
                function ($users) use ($accessControl, $criteria, &$closed, &$withdrawn, &$heldBack): void {
                    foreach ($users as $user) {
                        $email = $user->email;
                        $locale = $user->preferredLocale();

                        try {
                            $retired = $accessControl->closeInactiveAccount($user, $criteria);
                        } catch (ValidationException) {
                            $heldBack++;

                            continue;
                        }

                        if (!$retired) {
                            $withdrawn++;

                            continue;
                        }

                        Notification::route('mail', $email)
                            ->notify(new InactivityClosedNotification()->locale($locale));

                        $closed++;
                    }
                });

        return [$closed, $withdrawn, $heldBack];
    }

    /**
     * Send the pre-closure warning to every notice candidate and stamp it sent.
     *
     * The stamp is one conditional update through the same criteria, and its success is the notice decision: a row
     * no longer matching is withdrawn and gets no mail. Written without timestamps, like the last-login summary.
     * The announced date is the earliest the closure phase can act on the stamp, so the mail's promise holds exactly;
     * last holders get no notice, since it would promise a closure the command will refuse.
     *
     * @param  list<int>  $lastHolderIds
     * @return array{0: int, 1: int, 2: int} noticed, withdrawn and held back
     */
    private function sendClosureNotices(int $inactiveDays, int $noticeDays, array $lastHolderIds): array
    {
        $closureDate = now()->addDays($noticeDays);
        $noticed = 0;
        $withdrawn = 0;
        $heldBack = $this->noticeCandidates($inactiveDays, $noticeDays)->whereIn('id', $lastHolderIds)->count();

        $this->noticeCandidates($inactiveDays, $noticeDays)
            ->whereNotIn('id', $lastHolderIds)
            ->chunkById(100,
                function ($users) use ($inactiveDays, $noticeDays, $closureDate, &$noticed, &$withdrawn): void {
                    foreach ($users as $user) {
                        $stamped = User::withoutTimestamps(fn(): int => $this
                            ->noticeCriteria(User::query()->whereKey($user->getKey()), $inactiveDays, $noticeDays)
                            ->update(['inactivity_notice_sent_at' => now()]));

                        if ($stamped !== 1) {
                            $withdrawn++;

                            continue;
                        }

                        $user->notify(new InactivityNoticeNotification($closureDate));

                        $noticed++;
                    }
                });

        return [$noticed, $withdrawn, $heldBack];
    }

    /**
     * The accounts the closure phase would retire on this run.
     * Shared by the live phase and the dry run, so the report can never drift from what a real run would do.
     *
     * @return Builder<User>
     */
    private function closureCandidates(int $inactiveDays, int $noticeDays): Builder
    {
        return $this->closureCriteria(User::query(), $inactiveDays, $noticeDays);
    }

    /**
     * The accounts the notice phase would warn on this run.
     *
     * @return Builder<User>
     */
    private function noticeCandidates(int $inactiveDays, int $noticeDays): Builder
    {
        return $this->noticeCriteria(User::query(), $inactiveDays, $noticeDays);
    }

    /**
     * The closure conditions - notice aged past the promised window, inactivity at the full period - applied to a
     * user query, so the batch selection and the re-check under the lock share one definition.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function closureCriteria(Builder $query, int $inactiveDays, int $noticeDays): Builder
    {
        return $this->closable($query)
            ->where('inactivity_notice_sent_at', '<=', now()->subDays($noticeDays))
            ->where($this->inactiveSince(now()->subDays($inactiveDays)));
    }

    /**
     * The notice conditions - unnoticed, inactive for at least the period minus the notice window - applied to a
     * user query, so the batch selection and the conditional stamp share one definition.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function noticeCriteria(Builder $query, int $inactiveDays, int $noticeDays): Builder
    {
        return $this->closable($query)
            ->whereNull('inactivity_notice_sent_at')
            ->where($this->inactiveSince(now()->subDays($inactiveDays - $noticeDays)));
    }

    /**
     * Narrow a user query to the accounts the policy may touch: live rows, neither deactivated nor banned.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function closable(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereNull('banned_at');
    }

    /**
     * Inactive since the cutoff, by the last-login summary, or by created_at for accounts that never signed in.
     *
     * @return Closure(Builder<User>): void
     */
    private function inactiveSince(CarbonInterface $cutoff): Closure
    {
        return static function (Builder $query) use ($cutoff): void {
            $query->where('last_login_at', '<=', $cutoff)
                ->orWhere(static function (Builder $query) use ($cutoff): void {
                    $query->whereNull('last_login_at')->where('created_at', '<=', $cutoff);
                });
        };
    }
}
