<?php

namespace App\Services\Auth;

/**
 * Config-driven view over the OIDC identity providers.
 *
 * A provider is usable only when the master switch is on, its own flag is on, and its services.php credentials are complete.
 * A half-configured provider can never expose a broken login door.
 * Behavior flags live in security.identity_providers; credentials in services.{provider}.
 */
readonly class IdentityProviderRegistry
{
    public function enabled(string $provider): bool
    {
        if (!(bool) config('security.identity_providers.enabled', true)) {
            return false;
        }

        if (!(bool) config("security.identity_providers.providers.{$provider}.enabled", false)) {
            return false;
        }

        $service = (array) config("services.{$provider}", []);

        return filled($service['issuer'] ?? null)
            && filled($service['client_id'] ?? null)
            && filled($service['client_secret'] ?? null)
            && filled($service['redirect'] ?? null);
    }

    /**
     * How a provider login maps to a local account:
     * - 'explicit' (must be linked from settings first)
     * - 'email' (auto-link on a verified matching email)
     * - 'provision' (JIT account creation, guarded).
     */
    public function linkPolicy(string $provider): string
    {
        return (string) config("security.identity_providers.providers.{$provider}.link_policy", 'explicit');
    }

    /**
     * Whether this provider's logins owe the app-side two-factor challenge:
     * - 'skip' (the default) trusts the IdP to own MFA for its identities
     * - 'require' interposes the app challenge for enrolled accounts.
     */
    public function requiresTwoFactor(string $provider): bool
    {
        return config("security.identity_providers.providers.{$provider}.two_factor", 'skip') === 'require';
    }

    /**
     * The optional claim gate for the 'provision' policy: a dot-notation claim path and
     * the value it must carry (or contain, for array claims) before an account may be created.
     *
     * @return array{claim: ?string, value: ?string}
     */
    public function provisionGate(string $provider): array
    {
        return [
            'claim' => config("security.identity_providers.providers.{$provider}.provision_claim"),
            'value' => config("security.identity_providers.providers.{$provider}.provision_value"),
        ];
    }

    /**
     * Every provider key known to the config, enabled or not.
     *
     * @return list<string>
     */
    public function providers(): array
    {
        return array_keys((array) config('security.identity_providers.providers', []));
    }

    /**
     * The providers currently usable for sign-in.
     *
     * @return list<string>
     */
    public function enabledProviders(): array
    {
        return array_values(array_filter(
            $this->providers(),
            fn(string $provider): bool => $this->enabled($provider),
        ));
    }
}
