<?php

namespace Tests\Feature\Settings;

use App\Services\Auth\SessionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\ObservableSessionHandler;
use Tests\TestCase;

class SessionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The password-confirm limiter keys by user id, which RefreshDatabase repeats across tests.
        $this->app['cache']->flush();
    }

    public function test_sessions_list_flags_the_current_session(): void
    {
        $user = $this->createUser();

        $this->loginAndCarrySession($user);
        $response = $this->getJson('/api/sessions');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.sessions')
            ->assertJsonPath('data.sessions.0.is_current', true)
            ->assertJsonPath('data.total', 1);
    }

    public function test_the_list_is_soft_capped_but_reports_the_total(): void
    {
        config(['security.session_registry.display_limit' => 2]);
        $user = $this->createUser();
        $this->createOtherSessionFor($user);
        $this->createOtherSessionFor($user);
        $this->createOtherSessionFor($user);

        $this->loginAndCarrySession($user);

        $this->getJson('/api/sessions')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.sessions')
            ->assertJsonPath('data.total', 4);
    }

    public function test_other_sessions_are_listed_with_device_details(): void
    {
        $user = $this->createUser();
        $this->createOtherSessionFor($user);

        $this->loginAndCarrySession($user);
        $response = $this->getJson('/api/sessions');

        $response->assertStatus(200)->assertJsonCount(2, 'data.sessions');

        $other = collect($response->json('data.sessions'))->firstWhere('is_current', false);
        $this->assertSame('198.51.100.7', $other['ip_address']);
        $this->assertStringContainsString('Windows', $other['device_name']);
        // Sessions are addressed by digest; a raw session id is 40 chars.
        $this->assertSame(64, strlen($other['id']));
    }

    public function test_a_single_session_can_be_revoked(): void
    {
        $user = $this->createUser();
        $rawId = $this->createOtherSessionFor($user);

        $this->loginAndCarrySession($user);
        $other = collect($this->getJson('/api/sessions')->json('data.sessions'))->firstWhere('is_current', false);

        $this->deleteJson('/api/sessions/'.$other['id'])->assertStatus(204);

        // The underlying session is destroyed in the driver, not just unlisted.
        $this->assertSessionRevoked($rawId);
        $this->getJson('/api/sessions')->assertJsonCount(1, 'data.sessions');
    }

    /**
     * Browser A's request is running when browser B revokes A's session; A's request then saves the session back
     * into the store as it finishes. The registry's tombstone is what stops that copy from serving A's next request.
     */
    public function test_a_session_saved_back_after_its_revocation_is_signed_out_not_resurrected(): void
    {
        $user = $this->createUser();
        $this->loginAndCarrySession($user);
        $sessionId = $this->app['session']->driver()->getId();
        $handler = app('session')->driver()->getHandler();

        // What A's running request holds and will write back.
        $inFlight = $handler->read($sessionId);

        // B revokes it.
        app(SessionRegistry::class)->destroy($user, $this->registryRowId($sessionId));
        $this->assertFalse($this->sessionExists($sessionId));

        // A's request finishes: the store takes the write.
        $handler->write($sessionId, $inFlight);
        $this->assertTrue($this->sessionExists($sessionId));

        // A's next request on the same cookie is signed out, and the leftover copy destroyed for good.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
        $this->assertFalse($this->sessionExists($sessionId));
        $this->assertSame(0, $this->liveSessionCount($user));
    }

    /**
     * A remembered browser would sign itself straight back in with its cookie, so revoking its session must rotate
     * the remember token; a session that was never remembered must leave the token, and every other remembered
     * browser, alone.
     */
    public function test_revoking_a_remembered_session_rotates_the_remember_token(): void
    {
        $user = $this->createUser(['remember_token' => 'initial-token']);
        $this->createOtherSessionFor($user, remembered: true);
        $this->createOtherSessionFor($user);

        $this->loginAndCarrySession($user);
        $others = collect($this->getJson('/api/sessions')->json('data.sessions'))->where('is_current', false);
        $plain = $others->firstWhere('remembered', false);
        $remembered = $others->firstWhere('remembered', true);

        $this->deleteJson('/api/sessions/'.$plain['id'])->assertStatus(204);
        $this->assertSame('initial-token', $user->refresh()->remember_token);

        $this->deleteJson('/api/sessions/'.$remembered['id'])->assertStatus(204);
        $this->assertNotSame('initial-token', $user->refresh()->remember_token);
    }

    /**
     * A recaller arriving between the two steps of a revocation must find the token already rotated: otherwise it
     * mints a fresh session that the rotation afterwards never touches. Observed at the moment the session store
     * destroys the revoked id, on both revocation endpoints.
     */
    public function test_the_remember_token_is_rotated_before_the_session_is_destroyed(): void
    {
        $user = $this->createUser(['remember_token' => 'initial-token']);
        $tokenAtDestroy = [];
        ObservableSessionHandler::install(function (string $id) use ($user, &$tokenAtDestroy): void {
            $tokenAtDestroy[$id] = DB::table('users')->where('id', $user->getKey())->value('remember_token');
        });
        $single = $this->createOtherSessionFor($user, remembered: true);
        $bulk = $this->createOtherSessionFor($user, remembered: true);

        $this->loginAndCarrySession($user);
        $others = collect($this->getJson('/api/sessions')->json('data.sessions'))->where('is_current', false);

        $this->deleteJson('/api/sessions/'.hash('sha256', $single))->assertStatus(204);
        $this->assertNotSame('initial-token', $tokenAtDestroy[$single]);

        $rotated = $user->refresh()->remember_token;
        $this->deleteJson('/api/sessions/others', ['password' => 'password'])->assertStatus(200);
        $this->assertNotSame($rotated, $tokenAtDestroy[$bulk]);
        $this->assertCount(2, $others);
    }

    /**
     * A request passes the revocation check, is revoked while it runs, then rotates its session id (the
     * impersonation swap). The new id carries the old data and no tombstone: the id the browser presented is what
     * must give it away, at the end of that request and on the next.
     */
    public function test_a_session_revoked_mid_request_cannot_escape_by_rotating_its_id(): void
    {
        config(['access.impersonation.enabled' => true]);
        $actor = $this->createUser();
        $actor->givePermissionTo(
            config('permission.models.permission')::findOrCreate('users.impersonate', config('access.guard'))
        );
        $target = $this->createUser();

        $oldId = null;
        $revoked = false;
        ObservableSessionHandler::install(function (string $id) use ($actor, &$oldId, &$revoked): void {
            // The swap destroys the signed-in id as it rotates: revoke it at that instant, once, as another browser
            // would (the registry's own destroy comes back through this handler for the same id).
            if (!$revoked && $id === $oldId) {
                $revoked = true;
                app(SessionRegistry::class)->destroy($actor, $this->registryRowId($id));
            }
        });

        $this->loginAndCarrySession($actor);
        $oldId = $this->app['session']->driver()->getId();

        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertOk();
        $this->assertTrue($revoked);
        $this->assertSessionRevoked($oldId);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
        $this->assertSame(0, $this->liveSessionCount($actor));
        $this->assertSame(0, $this->liveSessionCount($target));
    }

    /**
     * The other order: the swap rotates first and its replacement session is saved, then a revocation that had
     * selected the old id lands. The row followed the rotation, so the revocation still finds the session under
     * its new id - the browser presenting only the replacement cookie is signed out.
     */
    public function test_a_revocation_selected_before_a_rotation_still_lands_on_the_rotated_session(): void
    {
        config(['access.impersonation.enabled' => true]);
        $actor = $this->createUser();
        $actor->givePermissionTo(
            config('permission.models.permission')::findOrCreate('users.impersonate', config('access.guard'))
        );
        $target = $this->createUser();

        $this->loginAndCarrySession($actor);
        $oldId = $this->app['session']->driver()->getId();
        $selectedRow = $this->registryRowId($oldId);

        $swap = $this->postJson("/api/access/users/{$target->id}/impersonate");
        $swap->assertOk();
        $newId = $this->app['session']->driver()->getId();
        $this->assertNotSame($oldId, $newId);
        $this->assertSame($selectedRow, $this->registryRowId($newId));

        // The browser now presents the replacement cookie only.
        $this->carrySessionCookieFrom($swap);

        app(SessionRegistry::class)->destroy($actor, $selectedRow);

        $this->assertSessionRevoked($newId);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    /**
     * A request loaded the session under its old id, another request rotated it (the swap) and moved the row, and
     * the session was then revoked. The first request finishes and writes its copy back under the old id, which no
     * row holds any more. The copy still names the row from its payload, so the tombstone must reach it.
     */
    public function test_a_copy_written_back_under_the_id_a_rotation_replaced_is_still_signed_out(): void
    {
        config(['access.impersonation.enabled' => true]);
        $actor = $this->createUser();
        $actor->givePermissionTo(
            config('permission.models.permission')::findOrCreate('users.impersonate', config('access.guard'))
        );
        $target = $this->createUser();

        $this->loginAndCarrySession($actor);
        $oldId = $this->app['session']->driver()->getId();
        $handler = app('session')->driver()->getHandler();

        // What the request running through the swap holds, and will write back.
        $inFlight = $handler->read($oldId);

        $this->postJson("/api/access/users/{$target->id}/impersonate")->assertOk();
        $newId = $this->app['session']->driver()->getId();
        $this->assertNotSame($oldId, $newId);

        app(SessionRegistry::class)->destroy($actor, $this->registryRowId($newId));
        $this->assertSessionRevoked($newId);

        // The first request finishes: the old id is back in the store, and no row holds it.
        $handler->write($oldId, $inFlight);
        $this->assertTrue($this->sessionExists($oldId));
        $this->assertDatabaseMissing('user_sessions', ['session_id' => $oldId]);

        // A request presenting the old cookie is signed out, the copy destroyed, and nothing re-registered.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
        $this->assertFalse($this->sessionExists($oldId));
        $this->assertSame(0, $this->liveSessionCount($actor));
    }

    /**
     * A browser whose session the store has forgotten still presents its cookie, and the registry row for it may
     * still stand. A sign-in as another account on that browser is a new session, not a continuation of the old
     * one: it gets its own row, under the account that signed in, with its own remember-me status.
     */
    public function test_a_sign_in_on_the_cookie_of_an_expired_session_opens_its_own_row(): void
    {
        $first = $this->createUser();
        $second = $this->createUser();

        $this->loginAndCarrySession($first);
        $expiredId = $this->app['session']->driver()->getId();
        $expiredRow = $this->registryRowId($expiredId);

        // The store forgets the session; the cookie and the registry row outlive it. The test client keeps one
        // session in memory across requests, so it is emptied like the store's copy.
        $this->flushSession();
        app('session')->driver()->getHandler()->destroy($expiredId);
        $this->app['auth']->forgetGuards();

        $signIn = $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', ['email' => $second->email, 'password' => 'password', 'remember' => true]);
        $signIn->assertOk();
        $this->carrySessionCookieFrom($signIn);
        $newId = $this->app['session']->driver()->getId();

        $this->assertNotSame($expiredId, $newId);
        $this->assertNotSame($expiredRow, $this->registryRowId($newId));
        $this->assertDatabaseHas('user_sessions', [
            'session_id' => $newId,
            'user_id' => $second->getKey(),
            'remembered' => true,
        ]);

        $this->assertCount(0, app(SessionRegistry::class)->forUser($first));
        $this->getJson('/api/sessions')
            ->assertJsonCount(1, 'data.sessions')
            ->assertJsonPath('data.sessions.0.is_current', true)
            ->assertJsonPath('data.sessions.0.remembered', true);
    }

    /**
     * A rotation leaves a window in the store: the old id is destroyed as the id rotates, and the new one is saved
     * only as the request finishes, with the registry pointing at the new id in between. A listing that runs in
     * that window finds the row's session missing. It must leave the row alone: deleted, the live session would
     * carry the id of a row that no longer exists, and a revocation already aimed at that row would find nothing.
     */
    public function test_a_listing_during_a_rotation_leaves_the_row_for_the_revocation_to_land_on(): void
    {
        config(['access.impersonation.enabled' => true]);
        $actor = $this->createUser();
        $actor->givePermissionTo(
            config('permission.models.permission')::findOrCreate('users.impersonate', config('access.guard'))
        );
        $target = $this->createUser();
        $registry = app(SessionRegistry::class);
        $swapping = false;
        $listedInWindow = null;
        ObservableSessionHandler::install(
            onDestroy: static fn(string $id) => null,
            onWrite: static function (string $id) use ($actor, $registry, &$swapping, &$listedInWindow): void {
                // The swap's only write is the save of its new id, which closes the window: list just before it.
                if ($swapping && $listedInWindow === null) {
                    $listedInWindow = $registry->forUser($actor);
                }
            },
        );

        $this->loginAndCarrySession($actor);
        $oldId = $this->app['session']->driver()->getId();
        $rowId = $this->registryRowId($oldId);

        $swapping = true;
        $swap = $this->postJson("/api/access/users/{$target->id}/impersonate");
        $swapping = false;
        $swap->assertOk();
        $newId = $this->app['session']->driver()->getId();

        $this->assertNotSame($oldId, $newId);
        $this->assertNotNull($listedInWindow);
        $this->assertCount(0, $listedInWindow);
        $this->assertSame($rowId, $this->registryRowId($newId));

        // The revocation aimed at the row lands on the rotated session.
        $registry->destroy($actor, $rowId);
        $this->assertSessionRevoked($newId);

        $this->carrySessionCookieFrom($swap);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    /**
     * A session naming a row the registry no longer holds is signed out, not re-registered: rows only go when
     * their session is dead (the sweep, a logout, a sign-in replacing it), so a live session naming one has
     * escaped the bookkeeping and is not taken back on its own word.
     */
    public function test_a_session_naming_a_missing_row_is_signed_out_not_re_registered(): void
    {
        $user = $this->createUser();
        $this->loginAndCarrySession($user);
        $sessionId = $this->app['session']->driver()->getId();

        DB::table('user_sessions')->where('id', $this->registryRowId($sessionId))->delete();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
        $this->assertFalse($this->sessionExists($sessionId));
        $this->assertSame(0, DB::table('user_sessions')->where('user_id', $user->getKey())->count());
    }

    /**
     * The tombstone is persisted before the driver destroys the session: a logout arriving in between must already
     * see the row as revoked, or its forget() would delete the row and leave nothing to block a late write.
     */
    public function test_the_tombstone_is_persisted_before_the_driver_destroys_the_session(): void
    {
        $user = $this->createUser();
        $registry = app(SessionRegistry::class);
        $revokedAtDestroy = null;
        ObservableSessionHandler::install(function (string $id) use (&$revokedAtDestroy): void {
            $revokedAtDestroy = DB::table('user_sessions')->where('session_id',
                $id)->whereNotNull('revoked_at')->exists();
        });
        $sessionId = $this->createOtherSessionFor($user);

        $registry->destroy($user, $this->registryRowId($sessionId));

        $this->assertTrue($revokedAtDestroy);
        $this->assertSessionRevoked($sessionId);
    }

    /**
     * A concurrent revocation tombstones a row and destroys its session while the session list is checking that
     * row's liveness. The list leaves the tombstone alone, and so does a late logout's forget().
     */
    public function test_cleanup_never_removes_a_fresh_tombstone(): void
    {
        $user = $this->createUser();
        $registry = app(SessionRegistry::class);
        $pruneTarget = null;
        ObservableSessionHandler::install(static fn(string $id) => null,
            function (string $id) use ($user, $registry, &$pruneTarget): void {
                // The liveness read of the row under test is the moment the revocation lands.
                if ($id === $pruneTarget) {
                    $registry->destroy($user, $this->registryRowId($id));
                }
            });
        $pruneTarget = $this->createOtherSessionFor($user);

        $this->assertCount(0, $registry->forUser($user));
        $this->assertSessionRevoked($pruneTarget);

        $registry->forget($this->registryRowId($pruneTarget));
        $this->assertSessionRevoked($pruneTarget);
    }

    public function test_a_remembered_sign_in_is_recorded_as_such(): void
    {
        $user = $this->createUser();

        $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'password', 'remember' => true])
            ->assertOk();

        $this->assertDatabaseHas('user_sessions', [
            'session_id' => $this->app['session']->driver()->getId(),
            'remembered' => true,
        ]);

        // Sign out first: the test client keeps one session, and signing in on top of it answers 409.
        $this->postJson('/api/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        $this->assertDatabaseHas('user_sessions', [
            'session_id' => $this->app['session']->driver()->getId(),
            'remembered' => false,
        ]);
    }

    /**
     * A sign-in the registry could not register is not left signed in: no revocation could ever reach that session.
     * The sign-in fails, the session is a guest, and the remember-me cookie the sign-in minted never reaches the
     * browser.
     */
    public function test_a_sign_in_whose_registration_fails_is_not_left_signed_in(): void
    {
        $user = $this->createUser();
        $this->failRegistryWrites('INSERT');

        $signIn = $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'password', 'remember' => true]);

        $signIn->assertStatus(500);
        $this->assertNull($signIn->getCookie(Auth::guard('web')->getRecallerName(), decrypt: false));
        $this->assertSame(0, DB::table('user_sessions')->count());

        // What the response saved is a guest session, inspected directly: a later request's own registration check
        // would sign a signed-in payload out and hide it behind the same 401.
        $savedId = $this->app['session']->driver()->getId();
        $this->assertTrue($this->sessionExists($savedId));
        $this->assertFalse($this->persistedSession($savedId)->has(Auth::guard('web')->getName()));

        $this->carrySessionCookieFrom($signIn);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    /**
     * The sign-out takes the remember cookie off the request as well as the response: the guard only expires it on
     * the response, and a fresh guard would otherwise read it straight back in and sign the browser in again
     * within the very request that signed it out. Seen on a keyless signed-in session from a remembered browser.
     */
    public function test_a_signed_out_session_cannot_sign_back_in_through_its_remember_cookie(): void
    {
        $user = $this->createUser();

        $signIn = $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'password', 'remember' => true]);
        $signIn->assertOk();
        $this->carrySessionCookieFrom($signIn);
        $this->carryCookieFrom($signIn, Auth::guard('web')->getRecallerName());
        $store = $this->app['session']->driver();
        $sessionId = $store->getId();
        $originalRows = DB::table('user_sessions')->pluck('id')->all();

        $store->forget(SessionRegistry::SESSION_KEY);
        $store->save();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
        $this->assertFalse($this->sessionExists($sessionId));
        $this->assertSame($originalRows, DB::table('user_sessions')->pluck('id')->all());
    }

    /**
     * A remember-cookie restoration whose registration fails must end as a guest, cookie gone: the guard restored
     * the sign-in during the request, so the sign-out has to take the cookie off the request or the sign-in comes
     * straight back before the session is saved. The saved payload is inspected directly.
     */
    public function test_a_restoration_whose_registration_fails_saves_a_guest_session(): void
    {
        $user = $this->createUser();
        $recaller = Auth::guard('web')->getRecallerName();

        $signIn = $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'password', 'remember' => true]);
        $signIn->assertOk();
        $this->carryCookieFrom($signIn, $recaller);
        $originalRows = DB::table('user_sessions')->pluck('id')->all();

        // Only the remember cookie comes back, and the registry is down.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->failRegistryWrites('INSERT');

        $restore = $this->getJson('/api/user');
        $restore->assertStatus(500);

        $recallerCookie = $restore->getCookie($recaller, decrypt: false);
        $this->assertNotNull($recallerCookie);
        $this->assertLessThan(time(), $recallerCookie->getExpiresTime());

        $savedId = $this->app['session']->driver()->getId();
        $this->assertTrue($this->sessionExists($savedId));
        $this->assertFalse($this->persistedSession($savedId)->has(Auth::guard('web')->getName()));
        $this->assertSame($originalRows, DB::table('user_sessions')->pluck('id')->all());

        // The browser, holding the guest session and no remember cookie, is signed out.
        $this->carrySessionCookieFrom($restore);
        $this->dropCookie($recaller);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    /**
     * A rotation whose registry move fails would leave the row on the destroyed id for good: the session would stay
     * revocable in bulk but vanish from listings and single-session revocation. The swap fails and the session is
     * signed out.
     */
    public function test_a_rotation_whose_registry_move_fails_signs_the_session_out(): void
    {
        config(['access.impersonation.enabled' => true]);
        $actor = $this->createUser();
        $actor->givePermissionTo(
            config('permission.models.permission')::findOrCreate('users.impersonate', config('access.guard'))
        );
        $target = $this->createUser();

        $this->loginAndCarrySession($actor);
        $oldId = $this->app['session']->driver()->getId();
        $this->failRegistryWrites('UPDATE');

        $swap = $this->postJson("/api/access/users/{$target->id}/impersonate");
        $swap->assertStatus(500);
        $this->carrySessionCookieFrom($swap);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
        $this->assertFalse($this->sessionExists($oldId));
    }

    /**
     * A signed-in session the registry never recorded (one that predates the row id in the payload, or whose
     * registration was lost some other way) is signed out rather than served: no revocation could reach it.
     */
    public function test_a_signed_in_session_the_registry_never_recorded_is_signed_out(): void
    {
        $user = $this->createUser();
        $this->loginAndCarrySession($user);
        $store = $this->app['session']->driver();
        $sessionId = $store->getId();

        // The stored payload loses its row id and keeps its sign-in.
        $store->forget(SessionRegistry::SESSION_KEY);
        $store->save();
        $this->assertTrue($store->has(Auth::guard('web')->getName()));

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
        $this->assertFalse($this->sessionExists($sessionId));
    }

    /**
     * A session the remember-me cookie restores is signed in by the guard before the registry sees it, into a fresh
     * session without a row id. That is a legitimate sign-in: it is served, and registered as the request finishes.
     */
    public function test_a_session_restored_by_the_remember_cookie_is_registered_not_signed_out(): void
    {
        $user = $this->createUser();

        $signIn = $this->withHeader('Referer', config('app.url'))
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'password', 'remember' => true]);
        $signIn->assertOk();
        $this->carryCookieFrom($signIn, Auth::guard('web')->getRecallerName());

        // Only the remember cookie comes back: the session cookie is gone, as after a browser restart.
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/user')->assertOk();

        $this->assertDatabaseHas('user_sessions', [
            'session_id' => $this->app['session']->driver()->getId(),
            'user_id' => $user->getKey(),
            'remembered' => true,
        ]);
    }

    /**
     * A logout that cannot drop the session's registry row fails with nothing changed: a row left live would take a
     * late write of the session back as signed in. Once the registry is back, the logout goes through and the late
     * write is signed out.
     */
    public function test_a_logout_fails_rather_than_leave_the_registry_row_live(): void
    {
        $user = $this->createUser();
        $this->loginAndCarrySession($user);
        $sessionId = $this->app['session']->driver()->getId();
        $handler = app('session')->driver()->getHandler();
        $inFlight = $handler->read($sessionId);

        $this->failRegistryWrites('DELETE');
        $this->postJson('/api/logout')->assertStatus(500);

        $this->assertSame(1, $this->liveSessionCount($user));
        $this->getJson('/api/user')->assertOk();

        $this->allowRegistryWrites('DELETE');
        $this->postJson('/api/logout')->assertNoContent();
        $this->assertSame(0, DB::table('user_sessions')->where('user_id', $user->getKey())->count());

        // A request that was running through the logout saves the session back; the missing row is what signs it out.
        $handler->write($sessionId, $inFlight);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_the_current_session_cannot_be_revoked(): void
    {
        $user = $this->createUser();

        $this->loginAndCarrySession($user);
        $current = collect($this->getJson('/api/sessions')->json('data.sessions'))->firstWhere('is_current', true);

        $this->deleteJson('/api/sessions/'.$current['id'])->assertStatus(422);
    }

    public function test_revoking_an_unknown_session_returns_not_found(): void
    {
        $user = $this->createUser();

        $this->actingAsStateful($user)
            ->deleteJson('/api/sessions/'.str_repeat('a', 64))
            ->assertStatus(404);
    }

    public function test_destroy_others_requires_the_password(): void
    {
        $user = $this->createUser();
        $rawId = $this->createOtherSessionFor($user);

        $this->loginAndCarrySession($user);

        $this->deleteJson('/api/sessions/others', [])->assertStatus(422);
        $this->assertDatabaseHas('user_sessions', ['session_id' => $rawId]);

        $this->deleteJson('/api/sessions/others', ['password' => 'password'])->assertStatus(200);

        $this->assertSessionRevoked($rawId);
        $this->assertSame(1, $this->liveSessionCount($user));
        $this->assertNotNull($user->refresh()->remember_token);
    }

    public function test_passwordless_user_can_destroy_others_without_a_password(): void
    {
        // refresh() loads DB-defaulted columns (is_active) that the factory
        // instance lacks; the Referer marks the request as first-party so
        // Sanctum treats the session user as a TransientToken.
        $user = $this->createUser(['password' => null])->refresh();
        $rawId = $this->createOtherSessionFor($user);

        $this->actingAs($user)->withHeader('Referer', config('app.url'));

        $this->deleteJson('/api/sessions/others')->assertStatus(200);

        $this->assertSessionRevoked($rawId);
    }

    public function test_api_tokens_cannot_list_sessions(): void
    {
        $user = $this->createUser();

        $this->actingAsStateless($user)->getJson('/api/sessions')->assertStatus(403);
    }

}
