import { defineStore } from 'pinia'
import { StorageSerializers, useLocalStorage } from '@vueuse/core'
import AuthService from '@/services/AuthService.js'
import AccessService from '@/services/AccessService.js'

const authService = new AuthService()
const accessService = new AccessService()

/**
 * The API serializes roles and permissions as `{ name }` objects (RoleResource/PermissionResource),
 * while the access checks compare plain strings.
 * Reduce every entry to its name and drop anything malformed, so a bad payload can only fail closed, never open.
 *
 * @param {Array<string|{ name?: string }>} values
 * @returns {string[]}
 */
function extractNames (values) {
    if (!Array.isArray(values)) {
        return []
    }

    return values
        .map(value => typeof value === 'string' ? value : value?.name)
        .filter(name => typeof name === 'string' && name !== '')
}

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: useLocalStorage('user', null, { serializer: StorageSerializers.object }),

        // Deliberately not persisted: the router guard awaits fetchUser() before the first navigation,
        // so grants are always confirmed by the server before anything reads them.
        // Persisting them would only create a stale-grant window on page load.
        roles: [],
        permissions: [],

        isInitialized: false,

        isLoggingIn: false,
        isLoggingOut: false,
        isFetchingUser: false,
    }),

    getters: {
        isLoading: (state) => state.isLoggingIn || state.isLoggingOut || state.isFetchingUser,

        isLoggedIn: (state) => !!state.user,

        // The borrowed-identity marker from the user resource: truthy while an admin is signed in
        // as this account. Drives the global impersonation banner and its exit control.
        isImpersonating: (state) => !!state.user?.impersonation,
    },

    actions: {
        persistSession (user, roles = [], permissions = []) {
            this.user = user
            this.roles = extractNames(roles)
            this.permissions = extractNames(permissions)
        },

        clearSession () {
            this.user = null
            this.roles = []
            this.permissions = []
        },

        /**
         * Authenticate with email + password.
         *
         * @param {Object} credentials
         * @param {string} credentials.email - The account email address.
         * @param {string} credentials.password - The account password.
         * @param {boolean} [credentials.remember] - Whether to issue a remember-me cookie.
         * @returns {Promise<{twoFactorRequired: boolean}>} Whether a two-factor challenge is now pending
         *   instead of a session (the caller routes to the challenge page; no user was fetched).
         */
        async login (credentials) {
            this.isLoggingIn = true
            try {
                let result = null
                try {
                    result = await authService.login(credentials)
                } catch (error) {
                    // The server still considers an earlier session valid - most  likely our last fetchUser()
                    // failed closed for a reason that never actually invalidated it server-side.
                    // Treat this as a successful login instead of a dead end the user can't get past (client says logged out, server disagrees).
                    if (!error.isAlreadyAuthenticated) {
                        throw error
                    }
                }

                if (result?.two_factor_required) {
                    // Credentials verified, but the session stays guest until the challenge is answered - nothing to fetch yet.
                    return { twoFactorRequired: true }
                }

                await this.fetchUser()

                return { twoFactorRequired: false }
            } finally {
                this.isLoggingIn = false
            }
        },

        /**
         * Complete a pending two-factor challenge and adopt the session.
         *
         * @param {Object} payload
         * @param {string} [payload.code] - The 6-digit authenticator code.
         * @param {string} [payload.recovery_code] - A recovery code, used instead of the authenticator code.
         */
        async challengeTwoFactor (payload) {
            this.isLoggingIn = true
            try {
                try {
                    await authService.challengeTwoFactor(payload)
                } catch (error) {
                    // Same recovery as login(): if the server says a session is already active, adopt it instead of failing.
                    if (!error.isAlreadyAuthenticated) {
                        throw error
                    }
                }

                await this.fetchUser()
            } finally {
                this.isLoggingIn = false
            }
        },

        /**
         * Fire-and-forget: the API responds identically whether or not the email has an account, so there is no outcome to store.
         *
         * @param {Object} payload
         * @param {string} payload.email - The address to mail the link to.
         * @param {string} [payload.redirect] - Internal path the link should land back on.
         * @param {string} [payload.captcha_token] - Widget token when the door is captcha-guarded.
         */
        async requestMagicLink ({ email, redirect, captcha_token }) {
            await authService.requestMagicLink({ email, redirect, captcha_token })
        },

        /**
         * Fire-and-forget, like requestMagicLink(): the API responds identically whether or not the email has an account.
         *
         * @param {Object} payload
         * @param {string} payload.email - The address to mail the link to.
         * @param {string} [payload.captcha_token] - Widget token when the door is captcha-guarded.
         */
        async requestPasswordReset ({ email, captcha_token }) {
            await authService.requestPasswordReset({ email, captcha_token })
        },

        /**
         * No session is established by a reset; the caller sends the user back to the login page afterward.
         */
        async resetPassword (payload) {
            await authService.resetPassword(payload)
        },

        /**
         * Authenticate by consuming an emailed magic-link token.
         *
         * @param {string} token - The plaintext token from the emailed link.
         * @returns {Promise<{twoFactorRequired: boolean, provisioned: boolean}>} Whether a two-factor challenge is now
         *   pending instead of a session (the caller routes to the challenge page; no user was fetched), and whether
         *   the login just created the account (self-provisioning), so the caller can greet it.
         */
        async loginWithMagicLink (token) {
            this.isLoggingIn = true
            try {
                let result = null
                try {
                    result = await authService.consumeMagicLink(token)
                } catch (error) {
                    // Same recovery as login(): if the server says a session is already active, adopt it instead of failing.
                    if (!error.isAlreadyAuthenticated) {
                        throw error
                    }
                }

                if (result?.two_factor_required) {
                    // The link is spent but the session stays guest until the challenge is answered - nothing to fetch yet.
                    return { twoFactorRequired: true, provisioned: false }
                }

                await this.fetchUser()

                return { twoFactorRequired: false, provisioned: result?.provisioned === true }
            } finally {
                this.isLoggingIn = false
            }
        },

        async logout () {
            this.isLoggingOut = true
            try {
                await authService.logout()
            } catch (error) {
                console.warn('Logout request failed; clearing local session anyway.', error)
            } finally {
                this.clearSession()
                this.isLoggingOut = false
            }
        },

        /**
         * Sign in as another account. The session swaps server-side and the adopted user resource
         * carries the impersonation marker; the caller routes into the app to view it.
         *
         * @param {number|string} userId - The account to impersonate.
         */
        async impersonate (userId) {
            const data = await accessService.impersonate(userId)
            this.persistSession(data, data.roles ?? [], data.permissions ?? [])
        },

        /**
         * End the current impersonation. Restores the original admin from the response, or clears
         * the session when the admin could not be restored (retired mid-impersonation), leaving the
         * caller to route to login.
         *
         * @returns {Promise<{restored: boolean}>} Whether an admin session was restored.
         */
        async stopImpersonation () {
            const data = await authService.stopImpersonation()

            if (!data) {
                this.clearSession()
                return { restored: false }
            }

            this.persistSession(data, data.roles ?? [], data.permissions ?? [])
            return { restored: true }
        },

        /**
         * Rethrows when the session state could not be determined: retries exhausted (session cleared,
         * failed closed) or rate limited (session kept, store left uninitialized).
         * Callers decide how to surface the failure - this store never touches the UI.
         */
        async fetchUser () {
            this.isFetchingUser = true
            let rateLimited = false
            try {
                const maxRetries = 2

                for (let attempt = 0; ; attempt++) {
                    try {
                        const data = await authService.fetchUser()
                        this.persistSession(data, data.roles ?? [], data.permissions ?? [])
                        return
                    } catch (error) {
                        if (error.isUnauthenticated) {
                            this.clearSession()
                            return
                        }

                        // A 429 says nothing about the session: keep the cached identity (clearing it
                        // would fake a logout over a live server session) and don't retry into the limit.
                        if (error.isRateLimited) {
                            rateLimited = true
                            throw error
                        }

                        if (attempt < maxRetries) {
                            await new Promise(resolve => setTimeout(resolve, 200 * 2 ** attempt))
                            continue
                        }

                        // Retries exhausted - we genuinely don't know the session state.
                        // Don't keep trusting the stale cache; fail closed instead.
                        this.clearSession()
                        throw error
                    }
                }
            } finally {
                // True even on failure: it means "session resolution finished", not "fetch succeeded".
                // Rate limiting is the exception - resolution never happened, so a later navigation retries it.
                if (!rateLimited) {
                    this.isInitialized = true
                }
                this.isFetchingUser = false
            }
        },
    },
})
