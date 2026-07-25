import { useAuthStore } from '@/stores/AuthStore.js'
import { getI18n } from '@/plugins/i18n/index.js'
import { resolveRedirectTarget } from '@/plugins/1.router/redirect.js'

export const setupGuards = router => {
    router.beforeEach(async to => {
        const authStore = useAuthStore()

        if (!authStore.isInitialized) {
            try {
                await authStore.fetchUser()
            } catch (error) {
                // The store already failed closed (session cleared), so the  requiresAuth check below redirects to the login page;
                // The toast explains why the user landed there.
                console.warn('Failed to fetch the authenticated user.', error)

                const { t } = getI18n().global
                useAppToast().add({
                    title: error.title ?? t('messages.common.errors.network_title'),
                    description: error.detail ?? t('messages.common.errors.network_description'),
                    color: 'error',
                })
            }
        }

        if (to.meta.requiresAuth && !authStore.isLoggedIn) {
            return {
                path: '/auth/login',
                query: { redirect: to.fullPath },
            }
        }

        // An admin-flagged account must set a new password before using the app;
        // the API enforces the same gate, this just lands the user on the change form instead of a wall of 403s.
        // The form is exclusively for that flow - without the flag it bounces to the app
        if (to.path === '/auth/password/change'
            && authStore.isLoggedIn
            && !authStore.user?.require_password_reset) {
            return { path: '/app' }
        }

        if (to.meta.requiresAuth
            && authStore.user?.require_password_reset
            && to.path !== '/auth/password/change') {
            return { path: '/auth/password/change' }
        }

        // An admin-mandated account must enroll two-factor before using the app;
        // The API enforces the same gate (EnsureTwoFactorEnrolled), this just lands the user on the enrollment card instead of a wall of 403s.
        // The settings page itself stays reachable - it hosts the way out.
        if (to.meta.requiresAuth
            && authStore.user?.two_factor_enrollment_required
            && to.path !== '/app/settings') {
            return { path: '/app/settings', query: { tab: 'security' } }
        }

        if (to.meta.requiresGuest && authStore.isLoggedIn) {
            // An already-authenticated visit to a guest page still honors a pending redirect (e.g. the session came back mid-login flow).
            return resolveRedirectTarget(to.query.redirect)
        }
    })
}
