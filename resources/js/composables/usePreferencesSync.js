import { useColorMode } from '@vueuse/core'
import { useAuthStore } from '@/stores/AuthStore.js'
import SettingsService from '@/services/SettingsService.js'

const settingsService = new SettingsService()

/**
 * Two-way sync between the device-local presentation state (i18n locale, color mode) and the
 * account's server-persisted preferences. Mounted once at the app root, so every control that
 * changes locale or theme - the settings page, the user menu's Appearance and Language entries -
 * persists without knowing the preference store exists.
 *
 * Server → device: whenever a fresh user resource lands (login, refetch), the account preferences
 * hydrate the device so they follow the user across devices. Local storage stays the pre-login
 * fallback; a null preference means "not chosen" and leaves the device value alone.
 *
 * Device → server: any change while signed in persists to the account. Values equal to the stored
 * preference are skipped, which also swallows the echo of a hydration - without the guard the two
 * watchers would ping-pong forever. A failed save only logs: presentation preferences are
 * low-stakes and the next change retries.
 */
export function usePreferencesSync () {
    const { locale } = useI18n()
    const colorMode = useColorMode()
    const authStore = useAuthStore()

    watch(() => authStore.user?.preferences, (preferences) => {
        if (!preferences) {
            return
        }

        if (preferences.locale && preferences.locale !== locale.value) {
            locale.value = preferences.locale
        }

        if (preferences.theme && preferences.theme !== colorMode.store.value) {
            colorMode.value = preferences.theme
        }
    })

    /**
     * @param {string} key - The registered preference to persist.
     * @param {*} value - The new value.
     */
    async function persistPreference (key, value) {
        if (!authStore.isLoggedIn || (authStore.user?.preferences?.[key] ?? null) === (value ?? null)) {
            return
        }

        try {
            const data = await settingsService.updatePreferences({ [key]: value })
            authStore.persistSession(data, data.roles ?? [], data.permissions ?? [])
        } catch (error) {
            console.warn(`Preference '${key}' failed to persist.`, error)
        }
    }

    watch(locale, value => persistPreference('locale', value))
    watch(() => colorMode.store.value, value => persistPreference('theme', value))
}
