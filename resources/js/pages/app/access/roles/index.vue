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
    <UDashboardPanel id="access-roles">
        <template #header>
            <UDashboardNavbar :title="t('messages.access.nav.roles')">
                <template #leading>
                    <UDashboardSidebarCollapse/>
                </template>
            </UDashboardNavbar>
        </template>

        <template #body>
            <AccessDenied
                v-if="!can('roles.view')"
                variant="page"
            />

            <AccessRolesPanel v-else/>
        </template>
    </UDashboardPanel>
</template>
