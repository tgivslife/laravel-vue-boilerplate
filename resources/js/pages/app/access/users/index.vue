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
    <UDashboardPanel id="access-users">
        <template #header>
            <UDashboardNavbar :title="t('messages.access.nav.users')">
                <template #leading>
                    <UDashboardSidebarCollapse/>
                </template>
            </UDashboardNavbar>
        </template>

        <template #body>
            <AccessDenied
                v-if="!can('users.view')"
                variant="page"
            />

            <AccessUsersPanel v-else/>
        </template>
    </UDashboardPanel>
</template>
