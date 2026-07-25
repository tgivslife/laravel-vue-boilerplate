import { en, ro } from '@nuxt/ui/locale'
import messages from '@intlify/unplugin-vue-i18n/messages'
import { syncRef, useLocalStorage } from '@vueuse/core'
import { createI18n } from 'vue-i18n'

let _i18n = null

export const getI18n = () => {
    if (_i18n === null) {
        const mergedMessages = {}
        const nuxtLocales = { en, ro }

        for (const [locale, data] of Object.entries(nuxtLocales)) {
            const userMessages = messages[locale] || {}
            mergedMessages[locale] = {
                ...data,
                messages: {
                    ...data.messages,
                    ...userMessages,
                },
            }
        }

        const storedLocale = useLocalStorage('locale', 'en')

        _i18n = createI18n({
            legacy: false,
            locale: storedLocale.value,
            fallbackLocale: 'en',
            messages: mergedMessages,
        })

        // Keep localStorage in sync regardless of where `locale` is changed
        // (login page's locale switcher, dashboard user menu, etc.).
        syncRef(storedLocale, _i18n.global.locale)
    }

    return _i18n
}

export default function (app) {
    app.use(getI18n())
}
