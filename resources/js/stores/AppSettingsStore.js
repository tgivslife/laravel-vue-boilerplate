import { defineStore } from 'pinia'
import SettingsService from '@/services/SettingsService.js'

const settingsService = new SettingsService()

/**
 * The public-flagged app settings (config/settings.php `app`, public: true entries), fetched once
 * per page load from the unauthenticated bootstrap endpoint. Consumers (announcement banner,
 * footer links) call fetch() on mount; concurrent callers share the single request.
 */
export const useAppSettingsStore = defineStore('appSettings', {
    state: () => ({
        settings: {},

        isLoaded: false,
        isLoading: false,
    }),

    actions: {
        /**
         * Load the public settings once; later calls are no-ops. These settings are presentation
         * chrome, so a failed load stays silent and simply leaves the consumers hidden.
         */
        async fetch () {
            if (this.isLoaded || this.isLoading) {
                return
            }

            this.isLoading = true
            try {
                const data = await settingsService.fetchPublicSettings()
                this.settings = data.settings ?? {}
                this.isLoaded = true
            } catch (error) {
                console.warn('Public app settings failed to load.', error)
            } finally {
                this.isLoading = false
            }
        },

        /**
         * Force a refetch: called after an admin saves a public setting, so
         * consumers (the announcement banner) reflect the change immediately
         * instead of waiting for the next page load.
         */
        refresh () {
            this.isLoaded = false
            return this.fetch()
        },
    },
})
