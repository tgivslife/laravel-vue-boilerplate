<script setup>
import { useRbac } from '@/plugins/rbac/composables/useRbac.js'
import AccessService from '@/services/AccessService.js'

definePage({
    meta: {
        layout: 'protected',

        requiresAuth: true,
    },
})

const { t } = useI18n()
const { can } = useRbac()
const router = useRouter()
const toast = useAppToast()

const accessService = new AccessService()

/* ------------------------------------------------------------------ *
 *  Role creation page
 *
 *  The detail page's layout without the server-backed facts: name and
 *  initial permissions stacked as sections (no tabs - nothing else
 *  exists before creation). Creating navigates to the new role's
 *  detail page.
 * ------------------------------------------------------------------ */

const permissions = ref([])

const newName = ref('')
const selectedPermissionIds = ref([])
const creating = ref(false)

/* Skeleton over an empty transfer list while the dictionary is on the wire. */
const permissionsLoading = ref(true)

async function loadPermissions () {
    try {
        const data = await accessService.fetchPermissions()
        permissions.value = data.permissions
    } catch (error) {
        toast.add({
            title: t('messages.access.load_failed'),
            description: error.detail ?? t('messages.common.errors.network_description'),
            color: 'error',
        })
    } finally {
        permissionsLoading.value = false
    }
}

onMounted(() => {
    if (can('roles.manage')) {
        loadPermissions()
    }
})

function mutationErrorToast (error) {
    toast.add({
        title: t('messages.access.save_failed'),
        description: error.errors?.[0]?.detail ?? error.detail ?? t('messages.common.errors.network_description'),
        color: 'error',
    })
}

async function createRole () {
    if (newName.value.trim() === '') {
        return
    }

    creating.value = true

    let created = null
    try {
        created = (await accessService.createRole(newName.value.trim())).role
    } catch (error) {
        mutationErrorToast(error)
        creating.value = false
        return
    }

    try {
        if (selectedPermissionIds.value.length > 0) {
            await accessService.syncRolePermissions(created.id, selectedPermissionIds.value)
        }

        toast.add({
            title: t('messages.access.roles.created'),
            color: 'success',
        })
    } catch (error) {
        /* The role exists; the grants can be finished on its detail page. */
        mutationErrorToast(error)
    } finally {
        creating.value = false
    }

    await router.push(`/app/access/roles/${created.id}`)
}
</script>

<template>
    <UDashboardPanel id="access-role-new">
        <template #header>
            <UDashboardNavbar :title="t('messages.access.roles.create')">
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
                v-if="!can('roles.manage')"
                variant="page"
            />

            <div v-else class="flex flex-col gap-4">
                <!-- The detail page's identity card, echoing the form as it is filled in. -->
                <div class="border border-default rounded-lg overflow-hidden">
                    <GridBanner/>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 sm:px-6 pb-4">
                        <UAvatar
                            :alt="newName.trim() || t('messages.access.roles.create')"
                            size="3xl"
                            class="-mt-10 ring-4 ring-(--ui-bg)"
                        />
                        <div class="flex-1 min-w-48">
                            <h2 class="text-xl font-semibold text-highlighted">
                                {{ newName.trim() || t('messages.access.roles.new_placeholder') }}
                            </h2>
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-1 text-sm text-muted">
                                <span class="flex items-center gap-1.5">
                                    <UIcon name="i-tabler-key" class="size-4"/>
                                    {{ selectedPermissionIds.length }}
                                    {{ t('messages.access.roles.col_permissions').toLowerCase() }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col divide-y divide-default">
                    <AccessSection
                        :title="t('messages.access.roles.name')"
                        :description="t('messages.access.roles.create_description')"
                    >
                        <UFormField :label="t('messages.access.roles.name')">
                            <UInput
                                v-model="newName"
                                :placeholder="t('messages.access.roles.new_placeholder')"
                                class="w-full"
                                autofocus
                            />
                        </UFormField>
                    </AccessSection>

                    <AccessSection
                        :title="t('messages.access.roles.permissions')"
                        :description="t('messages.access.roles.permissions_description')"
                    >
                        <ListSkeleton v-if="permissionsLoading" :rows="4"/>
                        <AccessTransferList
                            v-else
                            v-model="selectedPermissionIds"
                            :items="permissions"
                            :available-label="t('messages.access.roles.available_permissions')"
                            :assigned-label="t('messages.access.roles.assigned_permissions')"
                        />
                    </AccessSection>

                    <div class="flex items-center justify-end gap-2 py-6">
                        <UButton
                            :label="t('messages.access.cancel')"
                            color="neutral"
                            variant="ghost"
                            :disabled="creating"
                            to="/app/access/roles"
                        />
                        <UButton
                            :label="t('messages.access.roles.create_submit')"
                            :disabled="newName.trim() === ''"
                            :loading="creating"
                            @click="createRole"
                        />
                    </div>
                </div>
            </div>
        </template>
    </UDashboardPanel>
</template>
