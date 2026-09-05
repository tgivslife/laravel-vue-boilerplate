<script setup>
import { en, ro } from '@nuxt/ui/locale'
import GlobalSpinner from '@/components/common/GlobalSpinner.vue'
import { usePreferencesSync } from '@/composables/usePreferencesSync.js'

const nuxtLocales = { en, ro }
const { locale } = useI18n()
const uiLocale = computed(() => nuxtLocales[locale.value] ?? en)

// Keeps device locale and theme in sync with the account's server-persisted preferences.
usePreferencesSync()

// Zero closes the 300ms skip window, which let a table's tooltips fire on their own after the first.
const tooltipDefaults = { skipDelayDuration: 0 }
</script>

<template>
    <Suspense>
        <UApp :locale="uiLocale" :tooltip="tooltipDefaults">
            <RouterView/>
            <GlobalSpinner/>
        </UApp>
    </Suspense>
</template>
