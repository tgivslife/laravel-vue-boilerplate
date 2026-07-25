import HttpClient from '@/services/HttpClient.js'

export default class AuthService extends HttpClient {
    csrf () {
        return this.get('/sanctum/csrf-cookie')
    }

    async login (credentials) {
        await this.csrf()
        return this.post('/api/login', credentials)
    }

    logout () {
        return this.post('/api/logout')
    }

    /**
     * Request a sign-in link.
     *
     * @param {Object} payload
     * @param {string} payload.email - The address to mail the link to.
     * @param {string} [payload.redirect] - Internal path the link should land back on.
     * @param {string} [payload.captcha_token] - Widget token when the door is captcha-guarded.
     */
    async requestMagicLink ({ email, redirect, captcha_token }) {
        await this.csrf()
        return this.post('/api/magic-link', {
            email,
            ...(redirect ? { redirect } : {}),
            ...(captcha_token ? { captcha_token } : {}),
        })
    }

    async consumeMagicLink (token) {
        await this.csrf()
        return this.post('/api/magic-link/consume', { token })
    }

    /**
     * Complete a pending two-factor challenge.
     *
     * @param {Object} payload
     * @param {string} [payload.code] - The 6-digit authenticator code.
     * @param {string} [payload.recovery_code] - A recovery code, used instead of the authenticator code.
     */
    async challengeTwoFactor (payload) {
        await this.csrf()
        return this.post('/api/two-factor/challenge', payload)
    }

    /**
     * Request a password-reset link.
     *
     * @param {Object} payload
     * @param {string} payload.email - The address to mail the link to.
     * @param {string} [payload.captcha_token] - Widget token when the door is captcha-guarded.
     */
    async requestPasswordReset ({ email, captcha_token }) {
        await this.csrf()
        return this.post('/api/password/forgot', {
            email,
            ...(captcha_token ? { captcha_token } : {}),
        })
    }

    async resetPassword ({ token, email, password, password_confirmation }) {
        await this.csrf()
        return this.post('/api/password/reset', { token, email, password, password_confirmation })
    }

    fetchUser () {
        return this.get('/api/user')
    }

    fetchAuthMethods () {
        return this.get('/api/auth/methods')
    }

    /**
     * End the current impersonation and restore the original admin. The response carries the
     * restored admin's user resource, or a null `user` when the admin could no longer be restored
     * (deactivated, banned or deleted mid-impersonation) and the session was destroyed instead.
     */
    stopImpersonation () {
        return this.delete('/api/impersonation')
    }
}
