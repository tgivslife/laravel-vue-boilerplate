<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\JsonSuccessResponse;
use App\Services\Access\ImpersonationService;
use App\Services\Auth\IdentityProviderRegistry;
use App\Services\Auth\IdentityProviderService;
use App\Support\Auth\IdentityLinkStatus;
use App\Support\Auth\IdentityLoginStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OidcUser;
use Symfony\Component\HttpFoundation\RedirectResponse as ProviderRedirect;
use Throwable;

/**
 * Browser-facing OIDC flow (redirect out, callback in), serving two intents.
 *
 * The intent is remembered as a session marker set at redirect time: the callback URL is registered
 * at the provider and arrives carrying only the provider's own parameters, so the session is the one
 * trustworthy place to record why the flow started.
 *
 * - `login` (guests): the callback maps the identity onto a local session
 *   (IdentityProviderService::authenticate()). Enrolled accounts land on the two-factor challenge
 *   page instead of /app when the provider's `two_factor` config says 'require'.
 * - `connect` (signed-in users arriving with ?intent=connect from the settings page): the callback
 *   links the identity to the current account and lands back on the settings Security tab.
 *
 * Everything speaks full-page redirects rather than JSON, outcomes riding as query parameters for the SPA to toast.
 * Every failure collapses into a small set of generic landing errors, so the endpoints cannot be used to probe
 * which identities or accounts exist.
 */
class IdentityProviderController extends Controller
{
    private const string INTENT_SESSION_KEY = 'oidc_intent';

    private const string REDIRECT_SESSION_KEY = 'oidc_redirect';

    private const string SETTINGS_URL = '/app/settings?tab=security';

    public function __construct(
        private readonly IdentityProviderRegistry $registry,
        private readonly IdentityProviderService $identityProviderService,
        private readonly ImpersonationService $impersonation,
    ) {
    }

    /**
     * The sign-in methods currently available, for the login page to adapt its layout.
     * Public by design: which sign-in methods exist is visible on the login screen anyway.
     */
    public function methods(Request $request): JsonResponse
    {
        $captchaEnabled = (bool) config('security.captcha.enabled', false);

        return new JsonSuccessResponse(
            status: 200,
            message: 'Authentication methods retrieved successfully',
            data: [
                'password' => (bool) config('security.password_login.enabled', true),
                'magic_link' => (bool) config('security.magic_link.enabled', true),
                'magic_link_provision' => (bool) config('security.magic_link.enabled', true)
                    && (bool) config('security.magic_link.provision', false),
                'invitations' => (bool) config('security.invitations.enabled', true),
                'providers' => $this->registry->enabledProviders(),
                'captcha_doors' => $captchaEnabled
                    ? array_values((array) config('security.captcha.doors', []))
                    : [],
                'captcha_site_key' => $captchaEnabled ? config('security.captcha.site_key') : null,
                'captcha_script_url' => $captchaEnabled ? config('security.captcha.script_url') : null,
                'captcha_provider' => $captchaEnabled ? config('security.captcha.provider') : null,
            ],
        )->toResponse($request);
    }

    /**
     * Send the browser to the provider's authorization endpoint.
     *
     * A validated internal `redirect` query is parked in the session next to the intent, so a login that started
     * from a guarded page can land back where the user originally wanted.
     * Stale targets from earlier abandoned flows are cleared rather than inherited.
     */
    public function redirect(Request $request, string $provider): ProviderRedirect
    {
        abort_unless($this->registry->enabled($provider), 404);

        $connecting = Auth::guard('web')->check() && $request->query('intent') === 'connect';

        if (Auth::guard('web')->check() && !$connecting) {
            return redirect('/app');
        }

        /*
         * A borrowed session (admin impersonation) must not link identities: the identity would
         * become a persistent way into the target's account, audited as if the owner linked it.
         * Refused before anything is parked, so no half-started flow survives in the session.
         */
        if ($connecting && $this->impersonation->state($request) !== null) {
            return redirect(self::SETTINGS_URL.'&identity_error=impersonating');
        }

        $request->session()->put(self::INTENT_SESSION_KEY, $connecting ? 'connect' : 'login');

        $target = $connecting ? null : $this->internalRedirectTarget($request);

        if ($target === null) {
            $request->session()->forget(self::REDIRECT_SESSION_KEY);
        } else {
            $request->session()->put(self::REDIRECT_SESSION_KEY, $target);
        }

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Complete the flow for whichever intent started it.
     *
     * The parked redirect target is consumed no matter the outcome, so an abandoned flow can never leak it into a later, unrelated one.
     * On success, it becomes the landing page; when the two-factor challenge interposes it rides along as a query
     * for the challenge page to complete to;
     * On failure it returns to the login page so a retry through another door still remembers the destination.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless($this->registry->enabled($provider), 404);

        $connecting = $request->session()->pull(self::INTENT_SESSION_KEY) === 'connect' && Auth::guard('web')->check();
        $target = (string) $request->session()->pull(self::REDIRECT_SESSION_KEY, '');
        $redirectQuery = $target === '' ? '' : '&redirect='.rawurlencode($target);

        if (Auth::guard('web')->check() && !$connecting) {
            return redirect('/app');
        }

        /*
         * Checked again on the way back in: the session survives an impersonation started
         * mid-flow (the swap regenerates the id, not the data), so a `connect` intent parked
         * before the swap would otherwise link the admin's identity to the target's account.
         * Refused before the code exchange - the provider round-trip is never completed.
         */
        if ($connecting && $this->impersonation->state($request) !== null) {
            return redirect(self::SETTINGS_URL.'&identity_error=impersonating');
        }

        $failed = fn(): RedirectResponse => $connecting
            ? redirect(self::SETTINGS_URL.'&identity_error=failed')
            : redirect('/auth/login?error=identity_failed'.$redirectQuery);

        try {
            $oidcUser = Socialite::driver($provider)->user();
        } catch (Throwable) {
            return $failed();
        }

        /*
         * The facade only promises the Socialite user contract, but every registered driver is the app's
         * OidcProvider (Two\AbstractProvider), whose user() returns Two\User - the shape the service layer needs
         * (getRaw() for the ID-token claims, which the contract does not declare).
         * Anything else is a misregistered driver: fail closed, indistinguishable from any other broken flow.
         */
        if (!$oidcUser instanceof OidcUser) {
            return $failed();
        }

        if ($connecting) {
            return match ($this->identityProviderService->link($request->user(), $provider, $oidcUser)) {
                IdentityLinkStatus::Linked,
                IdentityLinkStatus::AlreadyLinked => redirect(self::SETTINGS_URL.'&linked='.$provider),
                IdentityLinkStatus::SubjectTaken => redirect(self::SETTINGS_URL.'&identity_error=taken'),
                IdentityLinkStatus::ProviderAlreadyLinked => redirect(self::SETTINGS_URL.'&identity_error=already_linked'),
            };
        }

        return match ($this->identityProviderService->authenticate($provider, $oidcUser)) {
            IdentityLoginStatus::Success => redirect($target === '' ? '/app' : $target),
            IdentityLoginStatus::TwoFactorRequired => redirect(
                '/auth/two-factor'.($target === '' ? '' : '?redirect='.rawurlencode($target))
            ),
            IdentityLoginStatus::NotLinked => redirect('/auth/login?error=identity_not_linked'.$redirectQuery),
            IdentityLoginStatus::AccountUnavailable => redirect('/auth/login?error=identity_unavailable'.$redirectQuery),
        };
    }

    /**
     * The validated internal redirect target from the query, or null.
     *
     * Same rules as the magic-link mail URL: a single leading slash only, so the OIDC flow cannot be turned into an open redirect.
     * The SPA validates the target again before navigating.
     */
    private function internalRedirectTarget(Request $request): ?string
    {
        $redirect = $request->query('redirect');

        if (is_string($redirect)
            && str_starts_with($redirect, '/')
            && !str_starts_with($redirect, '//')
            && !str_starts_with($redirect, '/\\')
        ) {
            return $redirect;
        }

        return null;
    }
}
