<script setup>
import { en, ro } from '@nuxt/ui/locale'
import GlobalSpinner from '@/components/common/GlobalSpinner.vue'
import { usePreferencesSync } from '@/composables/usePreferencesSync.js'

const nuxtLocales = { en, ro }
const { locale } = useI18n()
const uiLocale = computed(() => nuxtLocales[locale.value] ?? en)

/*
 * Keeps device locale and theme in sync with the account's server-persisted
 * preferences, whichever control changes them (settings page, user menu).
 */
usePreferencesSync()
</script>

<template>
    <Suspense>
        <UApp :locale="uiLocale">
            <RouterView/>
            <GlobalSpinner/>
        </UApp>
    </Suspense>
</template>
