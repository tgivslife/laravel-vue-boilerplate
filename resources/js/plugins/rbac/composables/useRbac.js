import { computed } from 'vue'
import { useAuthStore } from '@/stores/AuthStore.js'
import { can, canAll, canAny, checkAccess, hasRole } from '../access.js'

/**
 * Programmatic counterpart of <RbacGuard>. The check functions read the
 * reactive auth store, so calling them inside a computed or a template keeps
 * the result in sync with the session.
 *
 * The raw role/permission lists are intentionally not exposed: every check
 * must go through the validated, fail-closed functions.
 */
export function useRbac () {
    const authStore = useAuthStore()

    return {
        isInitialized: computed(() => authStore.isInitialized),

        can,
        canAny,
        canAll,
        hasRole,
        checkAccess,
    }
}
