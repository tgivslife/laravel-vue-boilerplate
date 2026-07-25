<script setup>
import { useRbac } from '@/plugins/rbac/composables/useRbac.js'
import { useAuthStore } from '@/stores/AuthStore.js'
import AccessService from '@/services/AccessService.js'
import { useAccessUserDisplay } from '@/composables/useAccessUserDisplay.js'

definePage({
    meta: {
        layout: 'protected',

        requiresAuth: true,
    },
})

const { t } = useI18n()
const { can } = useRbac()
const route = useRoute()
const router = useRouter()
const toast = useAppToast()
const authStore = useAuthStore()
const { formatDate } = useAccessUserDisplay()

const accessService = new AccessService()

/* ------------------------------------------------------------------ *
 *  Role detail + edit page
 *
 *  Banner: identity and headline facts. Tabs: profile (rename, facts
 *  and the dangerous delete) and the permission transfer list. The
 *  protected super-admin role is view-only - the API refuses to touch
 *  it, so every mutation surface hides.
 * ------------------------------------------------------------------ */

const role = ref(null)
const loading = ref(true)
const failed = ref(false)

/* The permission editor needs the permissions dictionary (roles.manage). */
const permissions = ref([])

const canManage = computed(() => can('roles.manage') && !role.value?.protected)

/* Editable state, re-synced from the server after every mutation. */
const editedName = ref('')
const selectedPermissionIds = ref([])

function applyRole (freshRole) {
    role.value = freshRole
    editedName.value = freshRole.name
    selectedPermissionIds.value = freshRole.permissions.map(permission => permission.id)
}

const tabItems = computed(() => [
    { label: t('messages.access.roles.tab_profile'), icon: 'i-tabler-id', slot: 'profile' },
    { label: t('messages.access.roles.permissions'), icon: 'i-tabler-key', slot: 'permissions' },
])

async function loadRole () {
    loading.value = true
    try {
        const data = await accessService.fetchRole(route.params.id)
        applyRole(data.role)
        failed.value = false
    } catch {
        failed.value = true
    } finally {
        loading.value = false
    }
}

/* The transfer list joins the selected ids against the dictionary, so the
 * editor shows a skeleton until it is in - rendering earlier would flash
 * an empty "assigned" pane for a role that does grant permissions. */
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
    if (can('roles.view')) {
        loadRole()

        if (can('roles.manage')) {
            loadPermissions()
        }
    }
})

function mutationErrorToast (error) {
    toast.add({
        title: t('messages.access.save_failed'),
        description: error.errors?.[0]?.detail ?? error.detail ?? t('messages.common.errors.network_description'),
        color: 'error',
    })
}

/**
 * Changing a role can change the current admin's own effective grants.
 */
async function refreshOwnGrants () {
    await authStore.fetchUser()
}

/* ------------------------------------------------------------------ *
 *  Profile (rename) + deletion
 * ------------------------------------------------------------------ */

const savingProfile = ref(false)

const profileDirty = computed(() => role.value !== null
    && editedName.value.trim() !== ''
    && editedName.value.trim() !== role.value.name)

async function saveProfile () {
    savingProfile.value = true
    try {
        const data = await accessService.renameRole(role.value.id, editedName.value.trim())
        applyRole(data.role)
        toast.add({
            title: t('messages.access.roles.saved'),
            color: 'success',
        })
    } catch (error) {
        mutationErrorToast(error)
    } finally {
        savingProfile.value = false
    }
}

const deleteOpen = ref(false)
const deleting = ref(false)

async function deleteRole () {
    deleting.value = true
    try {
        await accessService.deleteRole(role.value.id)
        toast.add({
            title: t('messages.access.roles.deleted'),
            color: 'success',
        })
        await refreshOwnGrants()
        await router.push('/app/access/roles')
    } catch (error) {
        deleteOpen.value = false
        mutationErrorToast(error)
    } finally {
        deleting.value = false
    }
}

/* ------------------------------------------------------------------ *
 *  Permission editor (transfer list)
 * ------------------------------------------------------------------ */

const savingPermissions = ref(false)

function sameSet (a, b) {
    return a.length === b.length && [...a].sort().join(',') === [...b].sort().join(',')
}

const permissionsDirty = computed(() => role.value !== null
    && !sameSet(selectedPermissionIds.value, role.value.permissions.map(permission => permission.id)))

function resetPermissions () {
    applyRole(role.value)
}

async function savePermissions () {
    savingPermissions.value = true
    try {
        const data = await accessService.syncRolePermissions(role.value.id, selectedPermissionIds.value)
        applyRole(data.role)

        toast.add({
            title: t('messages.access.roles.saved'),
            color: 'success',
        })

        await refreshOwnGrants()
    } catch (error) {
        mutationErrorToast(error)
    } finally {
        savingPermissions.value = false
    }
}
</script>

<template>
    <UDashboardPanel id="access-role-detail">
        <template #header>
            <UDashboardNavbar :title="role ? role.name : t('messages.access.roles.title')">
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

            <UAlert
                v-else-if="failed"
                :title="t('messages.access.roles.not_found')"
                :description="t('messages.access.roles.not_found_description')"
                color="warning"
                variant="subtle"
                icon="i-tabler-shield-question"
            />

            <div v-else-if="loading" class="flex flex-col gap-4">
                <div class="border border-default rounded-lg overflow-hidden">
                    <GridBanner/>
                    <div class="flex items-center gap-4 px-4 sm:px-6 pb-4">
                        <USkeleton class="size-16 rounded-full -mt-8 ring-4 ring-(--ui-bg)"/>
                        <div class="flex flex-col gap-2">
                            <USkeleton class="h-5 w-40"/>
                            <USkeleton class="h-4 w-52"/>
                        </div>
                        <USkeleton class="h-6 w-20 ms-auto"/>
                    </div>
                </div>

                <div class="flex gap-2">
                    <USkeleton v-for="n in 2" :key="n" class="h-9 w-32"/>
                </div>

                <div class="flex flex-col divide-y divide-default">
                    <div v-for="section in 2" :key="section" class="grid gap-x-8 gap-y-4 lg:grid-cols-[16rem_1fr] py-6">
                        <div class="flex flex-col gap-2">
                            <USkeleton class="h-5 w-32"/>
                            <USkeleton class="h-4 w-48"/>
                        </div>
                        <div class="min-w-0 flex flex-col gap-3">
                            <USkeleton class="h-8 w-full"/>
                            <USkeleton class="h-4 w-2/3"/>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else-if="role" class="flex flex-col gap-4">
                <!-- Role banner: mirrors the user detail page's identity card. -->
                <div class="border border-default rounded-lg overflow-hidden">
                    <GridBanner/>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 sm:px-6 pb-4">
                        <UAvatar
                            :alt="role.name"
                            size="3xl"
                            class="-mt-10 ring-4 ring-(--ui-bg)"
                        />
                        <div class="flex-1 min-w-48">
                            <h2 class="text-xl font-semibold text-highlighted">{{ role.name }}</h2>
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-1 text-sm text-muted">
                                <span class="flex items-center gap-1.5">
                                    <UIcon name="i-tabler-calendar" class="size-4"/>
                                    {{ formatDate(role.created_at) ?? '—' }}
                                </span>
                                <span class="flex items-center gap-1.5">
                                    <UIcon name="i-tabler-users" class="size-4"/>
                                    {{ role.users_count }} {{ t('messages.access.roles.col_users').toLowerCase() }}
                                </span>
                                <span class="flex items-center gap-1.5">
                                    <UIcon name="i-tabler-key" class="size-4"/>
                                    {{ role.permissions.length }}
                                    {{ t('messages.access.roles.col_permissions').toLowerCase() }}
                                </span>
                            </div>
                        </div>
                        <UBadge
                            v-if="role.protected"
                            :label="t('messages.access.roles.protected')"
                            color="warning"
                            variant="subtle"
                            icon="i-tabler-lock"
                        />
                    </div>
                </div>

                <UTabs :items="tabItems" variant="link">
                    <template #profile>
                        <div class="flex flex-col divide-y divide-default">
                            <AccessSection
                                :title="t('messages.access.roles.tab_profile')"
                                :description="t('messages.access.roles.profile_description')"
                            >
                                <div class="flex flex-col gap-4">
                                    <UFormField :label="t('messages.access.roles.name')">
                                        <UInput v-model="editedName" class="w-full" :disabled="!canManage"/>
                                    </UFormField>
                                    <div v-if="canManage" class="flex justify-end">
                                        <UButton
                                            :label="t('messages.access.save')"
                                            :disabled="!profileDirty"
                                            :loading="savingProfile"
                                            @click="saveProfile"
                                        />
                                    </div>
                                </div>
                            </AccessSection>

                            <AccessSection
                                :title="t('messages.access.roles.details')"
                                :description="t('messages.access.roles.details_description')"
                            >
                                <dl class="flex flex-col gap-3 text-sm">
                                    <div class="flex items-baseline justify-between gap-4">
                                        <dt class="text-muted">{{ t('messages.access.roles.col_created') }}</dt>
                                        <dd class="text-highlighted text-right">
                                            {{ formatDate(role.created_at) ?? '—' }}
                                        </dd>
                                    </div>
                                    <div class="flex items-baseline justify-between gap-4">
                                        <dt class="text-muted">{{ t('messages.access.roles.col_users') }}</dt>
                                        <dd class="text-highlighted text-right">{{ role.users_count }}</dd>
                                    </div>
                                    <div class="flex items-baseline justify-between gap-4">
                                        <dt class="text-muted">{{ t('messages.access.roles.col_permissions') }}</dt>
                                        <dd class="text-highlighted text-right">{{ role.permissions.length }}</dd>
                                    </div>
                                </dl>
                            </AccessSection>

                            <AccessSection
                                v-if="canManage"
                                :title="t('messages.access.roles.danger_title')"
                                :description="t('messages.access.roles.danger_description')"
                            >
                                <div class="flex flex-wrap items-center gap-4 border border-default rounded-lg p-4">
                                    <div class="flex-1 min-w-48">
                                        <p class="text-sm font-medium text-highlighted">
                                            {{ t('messages.access.roles.delete') }}
                                        </p>
                                        <p class="text-sm text-muted mt-1">
                                            {{
                                                t('messages.access.roles.delete_description', {
                                                    users: role.users_count
                                                })
                                            }}
                                        </p>
                                    </div>
                                    <UButton
                                        :label="t('messages.access.roles.delete')"
                                        icon="i-tabler-trash"
                                        color="error"
                                        variant="outline"
                                        size="sm"
                                        @click="deleteOpen = true"
                                    />
                                </div>
                            </AccessSection>
                        </div>
                    </template>

                    <template #permissions>
                        <div v-if="canManage && permissionsLoading" class="py-6">
                            <ListSkeleton :rows="4"/>
                        </div>

                        <div v-else-if="canManage" class="flex flex-col divide-y divide-default">
                            <AccessSection
                                :title="t('messages.access.roles.permissions')"
                                :description="t('messages.access.roles.permissions_description')"
                            >
                                <AccessTransferList
                                    v-model="selectedPermissionIds"
                                    :items="permissions"
                                    :available-label="t('messages.access.roles.available_permissions')"
                                    :assigned-label="t('messages.access.roles.assigned_permissions')"
                                />
                            </AccessSection>

                            <div class="flex items-center justify-end gap-2 py-6">
                                <UButton
                                    :label="t('messages.access.roles.reset_changes')"
                                    color="neutral"
                                    variant="ghost"
                                    :disabled="!permissionsDirty || savingPermissions"
                                    @click="resetPermissions"
                                />
                                <UButton
                                    :label="t('messages.access.roles.save_permissions')"
                                    :disabled="!permissionsDirty"
                                    :loading="savingPermissions"
                                    @click="savePermissions"
                                />
                            </div>
                        </div>

                        <!-- Read-only fallback: grants shown from the role payload, no dictionary needed. -->
                        <AccessSection
                            v-else
                            :title="t('messages.access.roles.permissions')"
                            :description="t('messages.access.roles.permissions_description')"
                        >
                            <div class="flex flex-wrap gap-1">
                                <template v-if="role.permissions.length">
                                    <UBadge
                                        v-for="permission in role.permissions"
                                        :key="permission.id"
                                        :label="permission.name"
                                        color="neutral"
                                        variant="subtle"
                                    />
                                </template>
                                <span v-else class="text-sm text-muted">—</span>
                            </div>
                        </AccessSection>
                    </template>
                </UTabs>
            </div>

            <UModal
                v-model:open="deleteOpen"
                :title="role ? t('messages.access.roles.delete_title', { name: role.name }) : ''"
                :description="t('messages.access.roles.delete_description', { users: role?.users_count ?? 0 })"
            >
                <template #footer>
                    <div class="flex w-full justify-end gap-2">
                        <UButton
                            :label="t('messages.access.cancel')"
                            color="neutral"
                            variant="ghost"
                            @click="deleteOpen = false"
                        />
                        <UButton
                            :label="t('messages.access.roles.delete')"
                            color="error"
                            :loading="deleting"
                            @click="deleteRole"
                        />
                    </div>
                </template>
            </UModal>
        </template>
    </UDashboardPanel>
</template>
