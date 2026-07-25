<script setup>
import { useSeoMeta } from '@unhead/vue'
import { useI18n } from 'vue-i18n'

definePage({
        meta: {
            layout: 'public',
        },
    },
)

const { t } = useI18n()

const appName = import.meta.env.VITE_APP_NAME || 'Acme'

// Placeholder marketing copy - replace with the real product's messaging.
useSeoMeta({
    titleTemplate: `%s - ${appName}`,
    title: 'Home',
    description: 'A secure, production-ready web application platform.',
    ogTitle: `Home - ${appName}`,
    ogDescription: 'A secure, production-ready web application platform.',
})

const heroLinks = computed(() => [{
    label: t('messages.landing.hero.sign_in'),
    to: '/auth/login',
    trailingIcon: 'i-tabler-arrow-right',
    size: 'xl',
}])

const features = computed(() => [{
    icon: 'i-tabler-lock',
    title: t('messages.landing.features.security_title'),
    description: t('messages.landing.features.security_description'),
}, {
    icon: 'i-tabler-shield-check',
    title: t('messages.landing.features.access_title'),
    description: t('messages.landing.features.access_description'),
}, {
    icon: 'i-tabler-settings',
    title: t('messages.landing.features.settings_title'),
    description: t('messages.landing.features.settings_description'),
}, {
    icon: 'i-tabler-history',
    title: t('messages.landing.features.audit_title'),
    description: t('messages.landing.features.audit_description'),
}, {
    icon: 'i-tabler-language',
    title: t('messages.landing.features.localization_title'),
    description: t('messages.landing.features.localization_description'),
}, {
    icon: 'i-tabler-activity',
    title: t('messages.landing.features.operations_title'),
    description: t('messages.landing.features.operations_description'),
}])

// Placeholder figures - replace with confirmed numbers before launch.
const stats = computed(() => [{
    value: t('messages.landing.stats.uptime_value'),
    label: t('messages.landing.stats.uptime_label'),
}, {
    value: t('messages.landing.stats.users_value'),
    label: t('messages.landing.stats.users_label'),
}, {
    value: t('messages.landing.stats.requests_value'),
    label: t('messages.landing.stats.requests_label'),
}, {
    value: t('messages.landing.stats.monitoring_value'),
    label: t('messages.landing.stats.monitoring_label'),
}])

const ctaLinks = computed(() => [{
    label: t('messages.landing.cta.sign_in'),
    to: '/auth/login',
    trailingIcon: 'i-tabler-arrow-right',
}])
</script>

<template>
    <div>
        <UPageHero
            :ui="{
        root: 'pb-24 sm:pb-32',
        container: 'relative z-10 lg:py-32'
      }"
            :headline="t('messages.landing.hero.headline')"
            :title="t('messages.landing.hero.title')"
            :description="t('messages.landing.hero.description')"
            :links="heroLinks"
        >
            <template #top>
                <HeroShaders class="absolute top-0 inset-x-0 h-full opacity-15"/>
            </template>
        </UPageHero>

        <UPageSection
            id="features"
            :title="t('messages.landing.features.title')"
            :description="t('messages.landing.features.description')"
            :features="features"
        />

        <UPageSection id="stats" :ui="{ container: 'py-12 sm:py-16 lg:py-20' }">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-8 text-center">
                <div
                    v-for="stat in stats"
                    :key="stat.label"
                    class="flex flex-col gap-1"
                >
                    <span class="text-3xl sm:text-4xl font-bold text-primary">{{ stat.value }}</span>
                    <span class="text-sm text-muted">{{ stat.label }}</span>
                </div>
            </div>
        </UPageSection>

        <UPageSection>
            <UPageCTA
                :title="t('messages.landing.cta.title')"
                :description="t('messages.landing.cta.description')"
                variant="subtle"
                :links="ctaLinks"
            />
        </UPageSection>
    </div>
</template>
