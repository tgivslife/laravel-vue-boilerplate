<script setup>
import { useRbac } from '@/plugins/rbac/composables/useRbac.js'

definePage({
    meta: {
        layout: 'protected',

        requiresAuth: true,
    },
})

const { t } = useI18n()
const { can } = useRbac()
</script>

<template>
    <UDashboardPanel id="access-settings">
        <template #header>
            <UDashboardNavbar :title="t('messages.access.nav.settings')">
                <template #leading>
                    <UDashboardSidebarCollapse/>
                </template>
            </UDashboardNavbar>
        </template>

        <template #body>
            <AccessDenied
                v-if="!can('settings.manage')"
                variant="page"
            />

            <template v-else>
                <div class="mb-8">
                    <h2 class="text-xl font-semibold text-highlighted">
                        {{ t('messages.access.settings.title') }}
                    </h2>
                    <p class="text-muted mt-1">
                        {{ t('messages.access.settings.subtitle') }}
                    </p>
                </div>

                <AccessAppSettingsPanel/>
            </template>
        </template>
    </UDashboardPanel>
</template>
