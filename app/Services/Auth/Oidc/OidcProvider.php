<?php

namespace App\Services\Auth\Oidc;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;
use Throwable;

/**
 * Generic OpenID Connect provider, discovery-document driven.
 *
 * One implementation serves every configured issuer (ROeID, the Keycloak
 * "ID" instance, ...): endpoints come from the issuer's
 * /.well-known/openid-configuration, the flow is Authorization Code with
 * PKCE, and the identity is taken from the ID token after full validation
 * (RS256 signature against the issuer's JWKS, iss, aud, exp handled by the
 * JWT library, and the session-bound nonce). The userinfo endpoint is
 * deliberately not consulted: the validated ID token is the trustworthy
 * source, and it carries everything matching needs (sub, email,
 * email_verified).
 */
class OidcProvider extends AbstractProvider
{
    protected const string NONCE_SESSION_KEY = 'oidc_nonce';

    /** Discovery and JWKS documents change rarely; cache for an hour. */
    protected const int METADATA_CACHE_SECONDS = 3600;

    /**
     * @var array<int, string>
     */
    protected $scopes = ['openid', 'profile', 'email'];

    /**
     * @var string
     */
    protected $scopeSeparator = ' ';

    protected string $issuer;

    /**
     * The last token-endpoint response; Socialite only forwards the access
     * token to getUserByToken(), but the identity lives in the id_token.
     *
     * @var array<string, mixed>
     */
    protected array $tokenResponse = [];

    public function setIssuer(string $issuer): static
    {
        $this->issuer = rtrim($issuer, '/');

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase((string) $this->discovery('authorization_endpoint'), $state);
    }

    /**
     * {@inheritdoc}
     *
     * Adds the OIDC nonce: generated per authorization request, kept in
     * the session, and required to match the ID token's `nonce` claim -
     * which binds the token to this browser and blocks replayed codes.
     */
    protected function getCodeFields($state = null): array
    {
        $nonce = Str::random(40);
        $this->request->session()->put(self::NONCE_SESSION_KEY, $nonce);

        return array_merge(parent::getCodeFields($state), ['nonce' => $nonce]);
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenUrl(): string
    {
        return (string) $this->discovery('token_endpoint');
    }

    /**
     * {@inheritdoc}
     *
     * Exchanged through Laravel's HTTP client instead of Socialite's bare
     * Guzzle: explicit timeouts, and Http::fake() can stand in for the
     * provider in tests.
     */
    public function getAccessTokenResponse($code): array
    {
        return $this->tokenResponse = Http::asForm()
            ->timeout(10)->connectTimeout(5)
            ->post($this->getTokenUrl(), $this->getTokenFields($code))
            ->throw()
            ->json() ?? [];
    }

    /**
     * {@inheritdoc}
     *
     * Socialite calls this with the access token; the identity instead
     * comes from the ID token captured in the token response, validated
     * end to end.
     */
    protected function getUserByToken($token): array
    {
        $idToken = (string) Arr::get($this->tokenResponse, 'id_token', '');

        if ($idToken === '') {
            throw new OidcValidationException('The token response carried no ID token.');
        }

        return $this->validateIdToken($idToken);
    }

    /**
     * {@inheritdoc}
     */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['sub'],
            'email' => $user['email'] ?? null,
            'name' => $user['name'] ?? null,
        ]);
    }

    /**
     * Validate the ID token and return its claims.
     *
     * @return array<string, mixed>
     */
    protected function validateIdToken(string $idToken): array
    {
        try {
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($this->jwks(), 'RS256'));
        } catch (Throwable $exception) {
            throw new OidcValidationException('The ID token failed signature validation.', previous: $exception);
        }

        $claims = json_decode(json_encode($claims), true);

        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== rtrim((string) $this->discovery('issuer'), '/')) {
            throw new OidcValidationException('The ID token issuer does not match the discovery document.');
        }

        $audience = (array) ($claims['aud'] ?? []);
        if (!in_array($this->clientId, $audience, true)) {
            throw new OidcValidationException('The ID token audience does not include this client.');
        }

        $expectedNonce = (string) $this->request->session()->pull(self::NONCE_SESSION_KEY, '');
        if ($expectedNonce === '' || !hash_equals($expectedNonce, (string) ($claims['nonce'] ?? ''))) {
            throw new OidcValidationException('The ID token nonce does not match this session.');
        }

        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new OidcValidationException('The ID token carries no subject.');
        }

        return $claims;
    }

    /**
     * A value from the issuer's discovery document.
     */
    protected function discovery(string $key): mixed
    {
        $document = Cache::remember(
            'oidc:discovery:'.$this->issuer,
            self::METADATA_CACHE_SECONDS,
            fn(): array => Http::timeout(10)->connectTimeout(5)
                ->get($this->issuer.'/.well-known/openid-configuration')
                ->throw()
                ->json() ?? [],
        );

        if (!isset($document[$key])) {
            throw new OidcValidationException("The discovery document is missing '{$key}'.");
        }

        return $document[$key];
    }

    /**
     * The issuer's JWK set.
     *
     * @return array<string, mixed>
     */
    protected function jwks(): array
    {
        return Cache::remember(
            'oidc:jwks:'.$this->issuer,
            self::METADATA_CACHE_SECONDS,
            fn(): array => Http::timeout(10)->connectTimeout(5)
                ->get((string) $this->discovery('jwks_uri'))
                ->throw()
                ->json() ?? [],
        );
    }
}
