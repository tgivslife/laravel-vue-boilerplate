<script setup>
definePage({
    meta: {
        layout: 'protected',

        requiresAuth: true,
    },
})

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

/* ------------------------------------------------------------------ *
 *  Category tabs
 *
 *  A single page: the left menu swaps the visible category in place.
 *  The active tab rides in the `tab` query so refreshes and shared
 *  links land on the same category (replace() keeps history clean).
 * ------------------------------------------------------------------ */

const tabs = computed(() => [
    { value: 'general', label: t('messages.settings.nav.general'), icon: 'i-tabler-adjustments' },
    { value: 'account', label: t('messages.settings.nav.account'), icon: 'i-tabler-user' },
    { value: 'security', label: t('messages.settings.nav.security'), icon: 'i-tabler-shield-lock' },
    { value: 'sessions', label: t('messages.settings.nav.sessions'), icon: 'i-tabler-devices' },
    { value: 'authentication-log', label: t('messages.settings.nav.authentication_log'), icon: 'i-tabler-history' },
])

const activeTab = computed({
    get: () => tabs.value.some(tab => tab.value === route.query.tab) ? route.query.tab : 'general',
    set: (value) => {
        router.replace({ query: { ...route.query, tab: value === 'general' ? undefined : value } })
    },
})

const menuItems = computed(() => tabs.value.map(tab => ({
    label: tab.label,
    icon: tab.icon,
    active: activeTab.value === tab.value,
    onSelect: () => { activeTab.value = tab.value },
})))
</script>

<template>
    <UDashboardPanel id="settings">
        <template #header>
            <UDashboardNavbar :title="t('messages.settings.title')">
                <template #leading>
                    <UDashboardSidebarCollapse/>
                </template>
            </UDashboardNavbar>
        </template>

        <template #body>
            <div class="mb-8">
                <h2 class="text-xl font-semibold text-highlighted">
                    {{ t('messages.settings.title') }}
                </h2>
                <p class="text-muted mt-1">
                    {{ t('messages.settings.subtitle') }}
                </p>
            </div>

            <div class="flex flex-col gap-6 lg:flex-row lg:gap-10">
                <UNavigationMenu
                    :items="menuItems"
                    orientation="horizontal"
                    class="lg:hidden"
                />

                <UNavigationMenu
                    :items="menuItems"
                    orientation="vertical"
                    class="hidden lg:block w-56 shrink-0"
                />

                <div class="flex-1 min-w-0">
                    <SettingsGeneralTab v-if="activeTab === 'general'"/>
                    <SettingsAccountTab v-else-if="activeTab === 'account'"/>
                    <SettingsSecurityTab v-else-if="activeTab === 'security'"/>
                    <SettingsSessionsTab v-else-if="activeTab === 'sessions'"/>
                    <SettingsAuthenticationLogTab v-else/>
                </div>
            </div>
        </template>
    </UDashboardPanel>
</template>
