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

/* ------------------------------------------------------------------ *
 *  Role history page
 *
 *  The role-surface change feed on a page of its own.
 *  This is the durable record: a deleted role's detail page 404s, but its whole life stays readable here.
 * ------------------------------------------------------------------ */
</script>

<template>
    <UDashboardPanel id="access-roles-history">
        <template #header>
            <UDashboardNavbar :title="t('messages.access.roles.audit.tab')">
                <template #leading>
                    <UDashboardSidebarCollapse/>
                    <UButton
                        icon="i-tabler-arrow-left"
                        color="neutral"
                        variant="ghost"
                        to="/app/access/roles"
                        :aria-label="t('messages.access.roles.back')"
                    />
                </template>
            </UDashboardNavbar>
        </template>

        <template #body>
            <AccessDenied
                v-if="!can('roles.view')"
                variant="page"
            />

            <div v-else class="flex flex-col gap-4">
                <p class="text-sm text-muted">{{ t('messages.access.roles.audit.description') }}</p>

                <AccessRoleAuditLog/>
            </div>
        </template>
    </UDashboardPanel>
</template>
