<script setup>
import { useNavigationItems } from '@/composables/useNavigationItems.js'
import { useAuthStore } from '@/stores/AuthStore.js'

definePage({
    meta: {
        layout: 'protected',

        requiresAuth: true,
    },
})

const { t } = useI18n()
const authStore = useAuthStore()

const firstName = computed(() => authStore.user?.first_name ?? '')

const navigationItems = useNavigationItems()

// Mirror the sidebar's grouped entries so new nav sections surface here automatically.
const sections = computed(() => navigationItems.value
    .filter(item => item.type === 'trigger')
    .map(item => ({
        title: item.label,
        icon: item.icon,
        links: item.children.map(({ label, to }) => ({ label, to })),
    })))
</script>

<template>
    <UDashboardPanel id="home">
        <template #header>
            <UDashboardNavbar :title="t('messages.app.nav.home')">
                <template #leading>
                    <UDashboardSidebarCollapse/>
                </template>
            </UDashboardNavbar>
        </template>

        <template #body>
            <div class="mb-8">
                <h2 class="text-xl font-semibold text-highlighted">
                    {{ t('messages.app.home.welcome', { name: firstName }) }}
                </h2>
                <p class="text-muted mt-1">
                    {{ t('messages.app.home.subtitle') }}
                </p>
            </div>

            <div class="space-y-8">
                <div v-for="section in sections" :key="section.title">
                    <div class="flex items-center gap-2 mb-3">
                        <UIcon :name="section.icon" class="size-5 text-muted"/>
                        <h3 class="font-medium text-highlighted">{{ section.title }}</h3>
                    </div>

                    <UPageGrid class="sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <UPageCard
                            v-for="link in section.links"
                            :key="link.to"
                            :title="link.label"
                            :to="link.to"
                            variant="subtle"
                        />
                    </UPageGrid>
                </div>
            </div>
        </template>
    </UDashboardPanel>
</template>
