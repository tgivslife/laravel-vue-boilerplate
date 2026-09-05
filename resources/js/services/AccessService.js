import HttpClient from '@/services/HttpClient.js'

/**
 * Client for the access-administration API (/api/access/*).
 * Every mutation is guarded server-side by the lockout invariants; 422 responses carry the
 * refusal reason in `errors` under the `access` field.
 */
export default class AccessService extends HttpClient {
    /**
     * Filter values are sent as `filter[...]` query parameters; axios serializes the nested object.
     *
     * @param {{ page?: number, per_page?: number, search?: string, role_id?: number|string, status?: string, two_factor?: string, onboarding?: string }} filters
     */
    fetchUsers ({ page = 1, per_page, search, role_id, status, two_factor, onboarding } = {}) {
        const params = { page, filter: {} }

        if (per_page) {
            params.per_page = per_page
        }

        if (search) {
            params.filter.search = search
        }
        if (role_id) {
            params.filter.role_id = role_id
        }
        if (status) {
            params.filter.status = status
        }
        if (two_factor) {
            params.filter.two_factor = two_factor
        }
        if (onboarding) {
            params.filter.onboarding = onboarding
        }

        return this.get('/api/access/users', params)
    }

    fetchUser (userId) {
        return this.get(`/api/access/users/${userId}`)
    }

    fetchUserStats () {
        return this.get('/api/access/users/stats')
    }

    /**
     * Create an account with the chosen onboarding delivery: 'temporary_password' carries the
     * server-generated password back exactly once (`temporary_password`), 'invitation' mails the
     * user a single-use first-sign-in link instead and returns no credential.
     *
     * @param {{ first_name: string, last_name: string, email: string, delivery?: string, role_ids?: number[] }} payload
     */
    createUser (payload) {
        return this.post('/api/access/users', payload)
    }

    /**
     * Download the filtered user list as a CSV blob (same filters as fetchUsers, no pagination).
     *
     * @param {{ search?: string, role_id?: number|string, status?: string, two_factor?: string, onboarding?: string }} filters
     * @returns {Promise<Blob>}
     */
    exportUsers ({ search, role_id, status, two_factor, onboarding } = {}) {
        const params = { filter: {} }

        if (search) {
            params.filter.search = search
        }
        if (role_id) {
            params.filter.role_id = role_id
        }
        if (status) {
            params.filter.status = status
        }
        if (two_factor) {
            params.filter.two_factor = two_factor
        }
        if (onboarding) {
            params.filter.onboarding = onboarding
        }

        return this.get('/api/access/users/export', params, { responseType: 'blob' })
    }

    /**
     * Patch account facts; only the keys present are applied.
     *
     * Ban semantics: `banned: true` records the ban, and `ban_reason` can be updated on its own while
     * the account stays banned - an explicit null clears it.
     * Lifting the ban (`banned: false`) clears the reason too; a reason sent for an unbanned account is ignored.
     *
     * @param {number|string} userId
     * @param {{ first_name?: string, last_name?: string, email_verified?: boolean, is_active?: boolean, banned?: boolean, ban_reason?: string|null }} payload
     */
    updateUser (userId, payload) {
        return this.patch(`/api/access/users/${userId}`, payload)
    }

    deleteUser (userId) {
        return this.delete(`/api/access/users/${userId}`)
    }

    /**
     * Answer whether an email is an account now, was one before deletion (tombstone hash match), or was never one.
     * The response carries `status` ('active' | 'deleted' | 'none') and the matched `user` (or null).
     *
     * @param {string} email
     */
    lookupMembership (email) {
        return this.get('/api/access/users/membership', { email })
    }

    /**
     * Force a password reset: the server generates a temporary password, destroys every session of the user,
     * and returns the plaintext exactly once (`temporary_password`) for the admin to pass on.
     *
     * @param {number|string} userId
     */
    forcePasswordReset (userId) {
        return this.post(`/api/access/users/${userId}/force-password-reset`)
    }

    /**
     * Sign in as the target account (requires users.impersonate and the feature switch). The
     * response is the target's authenticated-user resource; the session now answers as them until
     * the impersonation is ended.
     *
     * @param {number|string} userId
     */
    impersonate (userId) {
        return this.post(`/api/access/users/${userId}/impersonate`)
    }

    /**
     * Re-mail a pending invitation's first-sign-in link, revoking the previous one.
     * Only accounts that never signed in qualify; others are refused with a 422.
     *
     * @param {number|string} userId - The target user id.
     */
    resendInvitation (userId) {
        return this.post(`/api/access/users/${userId}/resend-invitation`)
    }

    /**
     * Clear the user's two-factor enrollment (authenticator and recovery codes); the server audits the reset and notifies the owner.
     *
     * @param {number|string} userId - The target user id.
     */
    resetUserTwoFactor (userId) {
        return this.delete(`/api/access/users/${userId}/two-factor`)
    }

    fetchUserSessions (userId) {
        return this.get(`/api/access/users/${userId}/sessions`)
    }

    fetchUserAuthenticationLog (userId, page = 1, date = null) {
        return this.get(`/api/access/users/${userId}/authentication-logs`, date ? { page, date } : { page })
    }

    fetchUserAuditLog (userId, page = 1) {
        return this.get(`/api/access/users/${userId}/audit-logs`, { page })
    }

    syncUserRoles (userId, roleIds) {
        return this.put(`/api/access/users/${userId}/roles`, { role_ids: roleIds })
    }

    syncUserPermissions (userId, permissionIds) {
        return this.put(`/api/access/users/${userId}/permissions`, { permission_ids: permissionIds })
    }

    /**
     * Without arguments the full list is returned (the dictionary read used by filters and grant editors);
     * Passing `per_page` turns on server pagination for the roles' browser.
     *
     * @param {{ page?: number, per_page?: number, search?: string }} params
     */
    fetchRoles ({ page, per_page, search } = {}) {
        const params = {}

        if (page) {
            params.page = page
        }
        if (per_page) {
            params.per_page = per_page
        }
        if (search) {
            params.filter = { search }
        }

        return this.get('/api/access/roles', params)
    }

    /**
     * Fetch a page of the role-surface audit feed, newest first.
     * Deleted roles remain in the feed (the roles table hard-deletes), so a deleted role's id is a valid filter.
     *
     * @param {number} [page=1] - The feed page.
     * @param {number|string|null} [roleId=null] - Narrow the feed to one role's entries.
     */
    fetchRoleAuditLog (page = 1, roleId = null) {
        const params = { page }

        if (roleId !== null) {
            params.filter = { role_id: roleId }
        }

        return this.get('/api/access/roles/audit-logs', params)
    }

    fetchRoleStats () {
        return this.get('/api/access/roles/stats')
    }

    fetchRole (roleId) {
        return this.get(`/api/access/roles/${roleId}`)
    }

    createRole (name) {
        return this.post('/api/access/roles', { name })
    }

    renameRole (roleId, name) {
        return this.patch(`/api/access/roles/${roleId}`, { name })
    }

    deleteRole (roleId) {
        return this.delete(`/api/access/roles/${roleId}`)
    }

    syncRolePermissions (roleId, permissionIds) {
        return this.put(`/api/access/roles/${roleId}/permissions`, { permission_ids: permissionIds })
    }

    /**
     * Without arguments the full vocabulary is returned (the dictionary read used by grant editors);
     * Passing `per_page` turns on server pagination for the permissions' browser.
     *
     * @param {{ page?: number, per_page?: number, search?: string }} params
     */
    fetchPermissions ({ page, per_page, search } = {}) {
        const params = {}

        if (page) {
            params.page = page
        }
        if (per_page) {
            params.per_page = per_page
        }
        if (search) {
            params.filter = { search }
        }

        return this.get('/api/access/permissions', params)
    }

    fetchPermissionStats () {
        return this.get('/api/access/permissions/stats')
    }

    fetchProtectables () {
        return this.get('/api/access/protectables')
    }

    fetchClassRules (alias) {
        return this.get(`/api/access/protectables/${alias}/rules`)
    }

    syncClassRules (alias, { type, mode, permissionIds }) {
        return this.put(`/api/access/protectables/${alias}/rules`, {
            type,
            mode,
            permission_ids: permissionIds,
        })
    }

    fetchRecords (alias, page = 1, search = '') {
        return this.get(`/api/access/protectables/${alias}/records`, search ? { page, filter: { search } } : { page })
    }

    fetchRecordRules (alias, recordId) {
        return this.get(`/api/access/protectables/${alias}/records/${recordId}`)
    }

    syncRecordRules (alias, recordId, { type, mode, permissionIds }) {
        return this.put(`/api/access/protectables/${alias}/records/${recordId}`, {
            type,
            mode,
            permission_ids: permissionIds,
        })
    }

    /**
     * The app-level settings registry with resolved values and editor metadata
     * (default, rules, public flag).
     */
    fetchAppSettings () {
        return this.get('/api/access/settings')
    }

    /**
     * Store a value for one registered setting; sending the registry default
     * resets the stored override.
     *
     * @param {string} key - The registry key of the setting.
     * @param {*} value - The new value; validated server-side against the registry rules.
     */
    updateAppSetting (key, value) {
        return this.put(`/api/access/settings/${key}`, { value })
    }

    /**
     * The read-only environment report: allowlisted runtime variables grouped
     * by category, secrets masked to a set/not-set flag.
     */
    fetchEnvironment () {
        return this.get('/api/access/settings/environment')
    }

    /**
     * The read-only config report: allowlisted dot-notation paths with the
     * effective values the app runs with, secrets masked to a set/not-set flag.
     */
    fetchConfigReport () {
        return this.get('/api/access/settings/config')
    }
}
