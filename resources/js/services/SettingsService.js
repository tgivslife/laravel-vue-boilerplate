import HttpClient from '@/services/HttpClient.js'

export default class SettingsService extends HttpClient {
    updateProfile ({ first_name, last_name }) {
        return this.patch('/api/profile', { first_name, last_name })
    }

    /**
     * Partial update: only the submitted preference keys change, the rest keep
     * their stored value or registry default. Returns the fresh user resource.
     *
     * @param {{ locale?: string|null, theme?: string }} preferences
     */
    updatePreferences (preferences) {
        return this.patch('/api/preferences', preferences)
    }

    /**
     * The public-flagged app settings (announcement banner, support and legal
     * URLs). Unauthenticated: safe to call before login for SPA bootstrap.
     */
    fetchPublicSettings () {
        return this.get('/api/settings')
    }

    /**
     * `current_password` is required for password accounts and absent for
     * passwordless ones setting their first password.
     */
    updatePassword (payload) {
        return this.put('/api/password', payload)
    }

    /**
     * Confirmation is `{ password }` for password accounts or `{ email }`
     * for passwordless ones - the API decides which it expects.
     */
    deleteAccount (confirmation) {
        return this.delete('/api/account', { data: confirmation })
    }

    fetchSessions () {
        return this.get('/api/sessions')
    }

    fetchAuthenticationLog (page = 1, date = null) {
        return this.get('/api/authentication-log', date ? { page, date } : { page })
    }

    fetchIdentities () {
        return this.get('/api/identities')
    }

    /**
     * Confirmation is `{ password }` for password accounts, empty for
     * passwordless ones.
     */
    unlinkIdentity (provider, confirmation = {}) {
        return this.delete(`/api/identities/${provider}`, { data: confirmation })
    }

    /**
     * Start two-factor enrollment; the response carries the secret, the otpauth URI and its QR SVG.
     * Confirmation is `{ password }` for password accounts, empty for passwordless ones.
     *
     * @param {Object} [confirmation]
     * @param {string} [confirmation.password] - The current account password.
     */
    startTwoFactorEnrollment (confirmation = {}) {
        return this.post('/api/two-factor', confirmation)
    }

    /**
     * Activate the pending enrollment; the response carries the one-time
     * plaintext recovery codes.
     *
     * @param {string} code - The 6-digit authenticator code.
     */
    confirmTwoFactorEnrollment (code) {
        return this.post('/api/two-factor/confirm', { code })
    }

    /**
     * Confirmation is `{ password }` for password accounts, empty for
     * passwordless ones.
     *
     * @param {Object} [confirmation]
     * @param {string} [confirmation.password] - The current account password.
     */
    disableTwoFactor (confirmation = {}) {
        return this.delete('/api/two-factor', { data: confirmation })
    }

    /**
     * Replace the recovery codes; the response carries the fresh one-time
     * plaintext set.
     *
     * @param {Object} [confirmation]
     * @param {string} [confirmation.password] - The current account password.
     */
    regenerateTwoFactorRecoveryCodes (confirmation = {}) {
        return this.post('/api/two-factor/recovery-codes', confirmation)
    }

    revokeSession (sessionId) {
        return this.delete(`/api/sessions/${sessionId}`)
    }

    revokeOtherSessions (confirmation = {}) {
        return this.delete('/api/sessions/others', { data: confirmation })
    }
}
