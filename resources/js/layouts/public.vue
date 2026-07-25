<script setup>
import { useI18n } from 'vue-i18n'
import AnnouncementBanner from '@/components/common/AnnouncementBanner.vue'

// Placeholder public navigation - add marketing pages here as they are built.
const items = computed(() => [])

const { locale, availableLocales, messages } = useI18n()

const locales = computed(() => availableLocales.map(l => ({
    ...messages.value[l],
    label: messages.value[l]?.name || l.toUpperCase(),
    code: l,
})))

</script>

<template>
    <AnnouncementBanner/>

    <UHeader>
        <template #left>
            <RouterLink to="/">
                <AppLogo class="w-auto h-6 shrink-0"/>
            </RouterLink>
        </template>

        <UNavigationMenu :items="items"/>

        <USeparator class="my-6"/>

        <template #right>
            <UColorModeButton/>

            <ULocaleSelect v-model="locale"
                           :locales="locales"
                           class="hidden lg:inline-flex"/>

            <USeparator orientation="vertical" class="h-7"/>

            <UButton
                :label="$t('messages.landing.sign-in')"
                color="neutral"
                to="/auth/login"
                class="hidden lg:inline-flex"
            />
        </template>

        <template #body>
            <UNavigationMenu :items="items"
                             orientation="vertical"
                             class="-mx-2.5"/>

            <USeparator class="my-6"/>

            <ULocaleSelect v-model="locale"
                           :locales="locales"/>

            <USeparator class="my-6"/>

            <UButton
                :label="$t('messages.landing.sign-in')"
                color="neutral"
                to="/auth/login"
                class="mb-3"
            />
        </template>
    </UHeader>

    <UMain>
        <RouterView/>
    </UMain>

    <USeparator icon="i-tabler-brand-vue"/>

    <UFooter>
        <template #left>
            <p class="text-sm text-muted">
                Built with Nuxt UI • © {{ new Date().getFullYear() }}
            </p>
        </template>

        <template #right>
            <UButton
                to="https://github.com/nuxt/ui"
                target="_blank"
                icon="i-tabler-brand-github"
                aria-label="GitHub"
                color="neutral"
                variant="ghost"
            />
        </template>
    </UFooter>
</template>
