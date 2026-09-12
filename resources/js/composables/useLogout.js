import { useAuthStore } from '@/stores/AuthStore.js'

/**
 * The sign-out action shared by every "log out" control: ends the session and lands on the login page,
 * or reports the failure and leaves the user signed in so they can try again.
 * The store only clears its state once the server confirmed the session is gone.
 *
 * @returns {{ logout: () => Promise<void> }}
 */
export function useLogout () {
    const { t } = useI18n()
    const authStore = useAuthStore()
    const router = useRouter()
    const toast = useAppToast()

    async function logout () {
        try {
            await authStore.logout()
        } catch (error) {
            toast.add({
                title: t('messages.auth.logout.failed'),
                description: error.detail ?? t('messages.common.errors.network_description'),
                color: 'error',
            })

            return
        }

        await router.push('/auth/login')
    }

    return { logout }
}
