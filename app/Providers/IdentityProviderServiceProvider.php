<?php

namespace App\Providers;

use App\Services\Auth\Oidc\OidcProvider;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;

/**
 * Registers every configured OIDC identity provider as a Socialite driver.
 *
 * One generic OidcProvider serves all issuers; the differences (issuer, credentials, redirect) are configuration in services.php.
 * New providers need a config block, never code.
 */
class IdentityProviderServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        foreach (array_keys((array) config('security.identity_providers.providers', [])) as $name) {
            Socialite::extend($name, function ($app) use ($name): OidcProvider {
                $config = (array) $app['config']["services.{$name}"];

                $provider = new OidcProvider(
                    $app['request'],
                    (string) ($config['client_id'] ?? ''),
                    (string) ($config['client_secret'] ?? ''),
                    url((string) ($config['redirect'] ?? '')),
                );

                $provider->setIssuer((string) ($config['issuer'] ?? ''));
                $provider->enablePKCE();

                return $provider;
            });
        }
    }
}
