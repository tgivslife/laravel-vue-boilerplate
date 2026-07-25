<script setup>
import { useColorMode } from '@vueuse/core'

const { t, locale } = useI18n()
const colorMode = useColorMode()

/*
 * Same semantics as the user dropdown's Appearance submenu: the stored
 * mode drives the selection, and "auto" follows the system preference.
 * Persistence is app-wide (usePreferencesSync in App.vue) - the controls
 * here just write the shared state.
 */
const theme = computed({
    get: () => colorMode.store.value,
    set: (value) => { colorMode.value = value },
})

const themeOptions = computed(() => [
    { label: t('messages.settings.general.theme_light'), value: 'light' },
    { label: t('messages.settings.general.theme_dark'), value: 'dark' },
    { label: t('messages.settings.general.theme_system'), value: 'auto' },
])

const localeOptions = [
    { label: 'English', value: 'en' },
    { label: 'Română', value: 'ro' },
]
</script>

<template>
    <div class="flex flex-col gap-4">
        <UPageCard
            :title="t('messages.settings.general.theme_label')"
            :description="t('messages.settings.general.theme_description')"
            variant="subtle"
        >
            <URadioGroup
                v-model="theme"
                :items="themeOptions"
                orientation="horizontal"
            />
        </UPageCard>

        <UPageCard
            :title="t('messages.settings.general.locale_label')"
            :description="t('messages.settings.general.locale_description')"
            variant="subtle"
        >
            <USelect
                v-model="locale"
                :items="localeOptions"
                class="w-48"
            />
        </UPageCard>
    </div>
</template>
