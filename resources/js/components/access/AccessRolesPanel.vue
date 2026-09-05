<script setup>
import { useRbac } from '@/plugins/rbac/composables/useRbac.js'
import { useAuthStore } from '@/stores/AuthStore.js'
import AccessService from '@/services/AccessService.js'
import { useAccessUserDisplay } from '@/composables/useAccessUserDisplay.js'

const { t } = useI18n()
const { formatDate } = useAccessUserDisplay()
const { can } = useRbac()
const toast = useAppToast()
const router = useRouter()
const authStore = useAuthStore()

const accessService = new AccessService()

/* ------------------------------------------------------------------ *
 *  Roles data table
 *
 *  Stat cards (role composition: total, unused, permissionless, active
 *  super-admin holders) over a toolbar (search + column visibility +
 *  creation) and a server-paged table. Clicking a row
 *  navigates to the role detail page (rename + permission editor); the
 *  row menu carries edit and delete. The super-admin role is listed but
 *  locked - the API refuses to touch it, so it gets no actions menu.
 * ------------------------------------------------------------------ */

const roles = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(25)
const loading = ref(true)
const search = ref('')

const stats = ref(null)
const statsLoading = ref(true)

const pageSizeItems = [10, 25, 50, 100].map(size => ({ label: String(size), value: size }))

async function loadRoles () {
    loading.value = true
    try {
        const data = await accessService.fetchRoles({
            page: page.value,
            per_page: pageSize.value,
            search: search.value.trim() || undefined,
        })
        roles.value = data.roles
        total.value = data.total
    } catch (error) {
        toast.add({
            title: t('messages.access.load_failed'),
            description: error.detail ?? t('messages.common.errors.network_description'),
            color: 'error',
        })
    } finally {
        loading.value = false
    }
}

/* Stat cards are informative chrome: a failed load stays silent rather
 * than stacking a second error toast on top of the table's own. */
async function loadStats () {
    try {
        const data = await accessService.fetchRoleStats()
        stats.value = data.stats
    } catch {
        stats.value = null
    } finally {
        statsLoading.value = false
    }
}

onMounted(() => {
    loadRoles()
    loadStats()
})

/* Resetting to page 1 triggers the page watcher; when already there,
 * reload directly so the change still applies. */
function resetToFirstPage () {
    if (page.value === 1) {
        loadRoles()
    } else {
        page.value = 1
    }
}

let searchDebounce = null
watch(search, () => {
    clearTimeout(searchDebounce)
    searchDebounce = setTimeout(resetToFirstPage, 300)
})

watch(pageSize, resetToFirstPage)

watch(page, () => loadRoles())

const showingFrom = computed(() => (total.value === 0 ? 0 : (page.value - 1) * pageSize.value + 1))
const showingTo = computed(() => Math.min(page.value * pageSize.value, total.value))

function surfaceError (error) {
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
 *  Table definition (cells rendered via the slots below)
 * ------------------------------------------------------------------ */

const table = useTemplateRef('table')
const columnVisibility = ref({})

const columns = computed(() => [
    { accessorKey: 'name', header: t('messages.access.roles.col_name'), enableHiding: false },
    { accessorKey: 'permissions', header: t('messages.access.roles.col_permissions') },
    { accessorKey: 'users_count', header: t('messages.access.roles.col_users') },
    { accessorKey: 'created_at', header: t('messages.access.roles.col_created') },
    { id: 'actions', enableHiding: false, meta: { class: { td: 'text-right', th: 'text-right' } } },
])

const columnLabels = computed(() => ({
    permissions: t('messages.access.roles.col_permissions'),
    users_count: t('messages.access.roles.col_users'),
    created_at: t('messages.access.roles.col_created'),
}))

const columnVisibilityItems = computed(() => (table.value?.tableApi?.getAllColumns() ?? [])
    .filter(column => column.getCanHide())
    .map(column => ({
        label: columnLabels.value[column.id] ?? column.id,
        type: 'checkbox',
        checked: column.getIsVisible(),
        onUpdateChecked (checked) {
            table.value?.tableApi?.getColumn(column.id)?.toggleVisibility(!!checked)
        },
        onSelect (event) {
            event.preventDefault()
        },
    })))

const BADGE_LIMIT = 3

function rowActions (role) {
    return [
        { type: 'label', label: t('messages.access.roles.actions') },
        {
            label: t('messages.access.roles.edit'),
            icon: 'i-tabler-pencil',
            onSelect: () => openDetail(role),
        },
        { type: 'separator' },
        {
            label: t('messages.access.roles.delete'),
            icon: 'i-tabler-trash',
            color: 'error',
            onSelect: () => { deleting.value = role },
        },
    ]
}

/* ------------------------------------------------------------------ *
 *  Detail page navigation (viewing and editing both live there)
 * ------------------------------------------------------------------ */

function openDetail (role) {
    router.push(`/app/access/roles/${role.id}`)
}

function onRowSelect (event, row) {
    openDetail(row.original)
}

/* ------------------------------------------------------------------ *
 *  Delete confirmation modal
 * ------------------------------------------------------------------ */

const deleting = ref(null)
const deletingBusy = ref(false)

async function deleteRole () {
    deletingBusy.value = true
    try {
        await accessService.deleteRole(deleting.value.id)

        toast.add({
            title: t('messages.access.roles.deleted'),
            color: 'success',
        })

        deleting.value = null
        await refreshOwnGrants()
        await loadRoles()
        loadStats()
    } catch (error) {
        surfaceError(error)
    } finally {
        deletingBusy.value = false
    }
}
</script>

<template>
    <div class="flex flex-col flex-1 w-full gap-4">
        <div v-if="statsLoading" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div
                v-for="card in 4"
                :key="card"
                class="border border-default rounded-lg p-4 flex flex-col gap-2"
            >
                <div class="flex items-center justify-between">
                    <USkeleton class="h-4 w-24"/>
                    <USkeleton class="size-8 rounded-lg"/>
                </div>
                <USkeleton class="h-7 w-16"/>
            </div>
        </div>

        <div v-else-if="stats" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="border border-default rounded-lg p-4 flex flex-col gap-1">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted">{{ t('messages.access.roles.stats_total') }}</span>
                    <span class="flex items-center justify-center size-8 rounded-lg bg-primary/10">
                        <UIcon name="i-tabler-shield" class="size-5 text-primary"/>
                    </span>
                </div>
                <span class="text-2xl font-semibold text-highlighted">{{ stats.total }}</span>
            </div>

            <div class="border border-default rounded-lg p-4 flex flex-col gap-1">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted">{{ t('messages.access.roles.stats_unused') }}</span>
                    <span class="flex items-center justify-center size-8 rounded-lg bg-warning/10">
                        <UIcon name="i-tabler-shield-off" class="size-5 text-warning"/>
                    </span>
                </div>
                <span class="text-2xl font-semibold text-highlighted">{{ stats.unused }}</span>
            </div>

            <div class="border border-default rounded-lg p-4 flex flex-col gap-1">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted">{{ t('messages.access.roles.stats_empty') }}</span>
                    <span class="flex items-center justify-center size-8 rounded-lg bg-info/10">
                        <UIcon name="i-tabler-shield-question" class="size-5 text-info"/>
                    </span>
                </div>
                <span class="text-2xl font-semibold text-highlighted">{{ stats.empty }}</span>
            </div>

            <div class="border border-default rounded-lg p-4 flex flex-col gap-1">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted">{{ t('messages.access.roles.stats_super_admins') }}</span>
                    <span class="flex items-center justify-center size-8 rounded-lg bg-error/10">
                        <UIcon name="i-tabler-crown" class="size-5 text-error"/>
                    </span>
                </div>
                <span class="text-2xl font-semibold text-highlighted">{{ stats.super_admin_holders }}</span>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <UInput
                v-model="search"
                :placeholder="t('messages.access.roles.search_placeholder')"
                icon="i-tabler-search"
                class="w-full sm:max-w-xs"
                :ui="{ trailing: 'pe-1' }"
            >
                <template v-if="search !== ''" #trailing>
                    <UTooltip :text="t('messages.access.clear_search')">
                        <UButton
                            icon="i-tabler-x"
                            color="neutral"
                            variant="link"
                            size="sm"
                            :aria-label="t('messages.access.clear_search')"
                            @click="search = ''"
                        />
                    </UTooltip>
                </template>
            </UInput>

            <div class="ms-auto flex items-center gap-2">
                <!-- The change feed lives on its own page: it is also where deleted roles' history survives. -->
                <UButton
                    :label="t('messages.access.roles.audit.tab')"
                    icon="i-tabler-history"
                    color="neutral"
                    variant="outline"
                    to="/app/access/roles/history"
                />

                <UDropdownMenu
                    :items="columnVisibilityItems"
                    :content="{ align: 'end' }"
                >
                    <UButton
                        :label="t('messages.access.columns')"
                        color="neutral"
                        variant="outline"
                        trailing-icon="i-tabler-chevron-down"
                    />
                </UDropdownMenu>

                <UButton
                    v-if="can('roles.manage')"
                    :label="t('messages.access.roles.create')"
                    icon="i-tabler-plus"
                    to="/app/access/roles/new"
                />
            </div>
        </div>

        <UTable
            ref="table"
            v-model:column-visibility="columnVisibility"
            :data="roles"
            :columns="columns"
            :loading="loading"
            class="border border-default rounded-lg"
            @select="onRowSelect"
        >
            <template #loading>
                <TableSkeleton :rows="4"/>
            </template>

            <template #name-cell="{ row }">
                <div class="flex items-center gap-2">
                    <span class="font-medium text-highlighted">{{ row.original.name }}</span>
                    <UBadge
                        v-if="row.original.protected"
                        :label="t('messages.access.roles.protected')"
                        color="warning"
                        variant="subtle"
                        size="sm"
                        icon="i-tabler-lock"
                    />
                </div>
            </template>

            <template #permissions-cell="{ row }">
                <div class="flex items-center gap-1 flex-wrap">
                    <UBadge
                        v-for="permission in row.original.permissions.slice(0, BADGE_LIMIT)"
                        :key="permission.id"
                        :label="permission.name"
                        color="neutral"
                        variant="subtle"
                        size="sm"
                    />
                    <UBadge
                        v-if="row.original.permissions.length > BADGE_LIMIT"
                        :label="`+${row.original.permissions.length - BADGE_LIMIT}`"
                        color="info"
                        variant="subtle"
                        size="sm"
                    />
                    <span v-if="row.original.permissions.length === 0" class="text-muted">—</span>
                </div>
            </template>

            <template #users_count-cell="{ row }">
                {{ row.original.users_count }}
            </template>

            <template #created_at-cell="{ row }">
                {{ formatDate(row.original.created_at) ?? '—' }}
            </template>

            <template #actions-cell="{ row }">
                <div class="flex items-center justify-end gap-1" @click.stop>
                    <UTooltip :text="t('messages.access.roles.view')">
                        <UButton
                            icon="i-tabler-eye"
                            color="neutral"
                            variant="ghost"
                            :aria-label="t('messages.access.roles.view')"
                            @click="openDetail(row.original)"
                        />
                    </UTooltip>
                    <UDropdownMenu
                        v-if="can('roles.manage') && !row.original.protected"
                        :items="rowActions(row.original)"
                        :content="{ align: 'end' }"
                        :aria-label="t('messages.access.roles.actions')"
                    >
                        <UTooltip :text="t('messages.access.roles.actions')">
                            <UButton
                                icon="i-tabler-dots-vertical"
                                color="neutral"
                                variant="ghost"
                                :aria-label="t('messages.access.roles.actions')"
                            />
                        </UTooltip>
                    </UDropdownMenu>
                </div>
            </template>
        </UTable>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <span class="text-sm text-muted">{{ t('messages.access.rows_per_page') }}</span>
                <USelect
                    v-model="pageSize"
                    :items="pageSizeItems"
                    class="w-22"
                />
            </div>

            <div class="flex flex-wrap items-center gap-4">
                <span class="text-sm text-muted">
                    {{ t('messages.access.roles.showing', { from: showingFrom, to: showingTo, total }) }}
                </span>

                <UPagination
                    v-model:page="page"
                    :total="total"
                    :items-per-page="pageSize"
                    :disabled="loading"
                />
            </div>
        </div>
    </div>

    <UModal
        :open="deleting !== null"
        :title="t('messages.access.roles.delete_title', { name: deleting?.name ?? '' })"
        :description="t('messages.access.roles.delete_description', { users: deleting?.users_count ?? 0 })"
        @update:open="value => { if (!value) deleting = null }"
    >
        <template #footer>
            <div class="flex w-full justify-end gap-2">
                <UButton
                    :label="t('messages.access.cancel')"
                    color="neutral"
                    variant="ghost"
                    @click="deleting = null"
                />
                <UButton
                    :label="t('messages.access.roles.delete')"
                    color="error"
                    :loading="deletingBusy"
                    @click="deleteRole"
                />
            </div>
        </template>
    </UModal>
</template>
