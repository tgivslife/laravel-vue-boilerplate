import { useColorMode } from '@vueuse/core'
import { useAuthStore } from '@/stores/AuthStore.js'

export function useUserMenuItems (fullName) {
    const { t, locale } = useI18n()
    const authStore = useAuthStore()
    const router = useRouter()
    const toast = useAppToast()
    const colorMode = useColorMode()

    /* Ends the borrowed session: restores the admin (routing back to the users panel), or clears
     * everything and lands on login when the admin could not be restored mid-impersonation. */
    async function exitImpersonation () {
        try {
            const { restored } = await authStore.stopImpersonation()

            if (restored) {
                toast.add({
                    title: t('messages.impersonation.exited_title'),
                    description: t('messages.impersonation.exited_description'),
                    color: 'success',
                })
                router.push('/app/access/users')
            } else {
                toast.add({
                    title: t('messages.impersonation.ended_signed_out'),
                    color: 'info',
                })
                router.push('/auth/login')
            }
        } catch (error) {
            /* A 401/403 means the borrowed session is already gone server-side (e.g. the target
             * was deactivated and the cutoff destroyed it): that is an ended impersonation, not a
             * failed exit. */
            if (error.status === 401 || error.status === 403) {
                authStore.clearSession()
                toast.add({
                    title: t('messages.impersonation.ended_signed_out'),
                    color: 'info',
                })
                router.push('/auth/login')
                return
            }

            toast.add({
                title: t('messages.impersonation.exit_failed'),
                description: error.detail ?? t('messages.common.errors.network_description'),
                color: 'error',
            })
        }
    }

    return computed(() => [
        [
            { type: 'label', label: fullName.value, avatar: { alt: fullName.value } },
            ...(authStore.isImpersonating
                ? [{
                    label: t('messages.impersonation.exit'),
                    // Names the borrowed session's source account - empty when the admin was
                    // retired mid-impersonation (the exit then signs out entirely).
                    description: authStore.user?.impersonation?.actor_name
                        ? t('messages.impersonation.exit_description', { actor: authStore.user.impersonation.actor_name })
                        : undefined,
                    icon: 'i-tabler-user-shield',
                    class: 'text-violet-600 data-highlighted:text-violet-600 data-highlighted:before:bg-violet-600/10'
                        + ' dark:text-violet-400 dark:data-highlighted:text-violet-400 dark:data-highlighted:before:bg-violet-400/10',
                    ui: {
                        itemLeadingIcon: 'text-violet-600/75 group-data-highlighted:text-violet-600'
                            + ' dark:text-violet-400/75 dark:group-data-highlighted:text-violet-400',
                    },
                    onSelect: exitImpersonation,
                }]
                : []),
        ],
        [
            {
                label: t('messages.app.nav.appearance'),
                icon: 'i-tabler-palette',
                children: [[
                    {
                        label: t('messages.app.nav.settings.light'),
                        icon: 'i-tabler-sun',
                        type: 'checkbox',
                        checked: colorMode.store.value === 'light',
                        onSelect: () => { colorMode.value = 'light' },
                    },
                    {
                        label: t('messages.app.nav.settings.dark'),
                        icon: 'i-tabler-moon',
                        type: 'checkbox',
                        checked: colorMode.store.value === 'dark',
                        onSelect: () => { colorMode.value = 'dark' },
                    },
                    {
                        label: t('messages.app.nav.settings.system'),
                        icon: 'i-tabler-device-desktop',
                        type: 'checkbox',
                        checked: colorMode.store.value === 'auto',
                        onSelect: () => { colorMode.value = 'auto' },
                    },
                ]],
            },
            {
                label: t('messages.app.nav.locale'),
                icon: 'i-tabler-language',
                children: [[
                    {
                        label: 'English',
                        type: 'checkbox',
                        checked: locale.value === 'en',
                        onSelect: () => { locale.value = 'en' },
                    },
                    {
                        label: 'Română',
                        type: 'checkbox',
                        checked: locale.value === 'ro',
                        onSelect: () => { locale.value = 'ro' },
                    },
                ]],
            },
        ],
        [{
            label: t('messages.settings.title'),
            icon: 'i-tabler-settings',
            to: '/app/settings',
        }],
        [{
            label: t('messages.app.nav.logout'),
            icon: 'i-tabler-logout',
            onSelect: async () => {
                await authStore.logout()
                await router.push('/auth/login')
            },
        }],
    ])
}
