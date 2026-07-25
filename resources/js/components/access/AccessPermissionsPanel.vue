<script setup>
import AccessService from '@/services/AccessService.js'

const { t } = useI18n()
const toast = useAppToast()

const accessService = new AccessService()

/* ------------------------------------------------------------------ *
 *  Permission vocabulary data table (read-only)
 *
 *  Stat cards (vocabulary coverage: total, unassigned, direct user
 *  grants, most granted) over a toolbar (search + column visibility) and a server-paged table.
 *  Permissions are code-seeded from config/access.php - there is nothing to create or
 *  delete here, so the table carries no actions column.
 *  Each row shows which roles grant the permission so an admin can see the
 *  vocabulary's coverage at a glance.
 * ------------------------------------------------------------------ */

const permissions = ref([])
const roles = ref([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(25)
const loading = ref(true)
const search = ref('')

const stats = ref(null)
const statsLoading = ref(true)

const pageSizeItems = [10, 25, 50, 100].map(size => ({ label: String(size), value: size }))

async function loadPermissions () {
    loading.value = true
    try {
        const data = await accessService.fetchPermissions({
            page: page.value,
            per_page: pageSize.value,
            search: search.value.trim() || undefined,
        })
        permissions.value = data.permissions
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
        const data = await accessService.fetchPermissionStats()
        stats.value = data.stats
    } catch {
        stats.value = null
    } finally {
        statsLoading.value = false
    }
}

/* The "granted by" column joins against the full roles dictionary; the
 * table stays in its loading state until the dictionary is in, so rows
 * never flash the "no role" badge while it is still on the wire. */
const rolesLoading = ref(true)

async function loadRoles () {
    try {
        const data = await accessService.fetchRoles()
        roles.value = data.roles
    } catch (error) {
        toast.add({
            title: t('messages.access.load_failed'),
            description: error.detail ?? t('messages.common.errors.network_description'),
            color: 'error',
        })
    } finally {
        rolesLoading.value = false
    }
}

onMounted(() => {
    loadPermissions()
    loadStats()
    loadRoles()
})

/* Resetting to page 1 triggers the page watcher; when already there,
 * reload directly so the change still applies. */
function resetToFirstPage () {
    if (page.value === 1) {
        loadPermissions()
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

watch(page, () => loadPermissions())

const showingFrom = computed(() => (total.value === 0 ? 0 : (page.value - 1) * pageSize.value + 1))
const showingTo = computed(() => Math.min(page.value * pageSize.value, total.value))

function rolesGranting (permission) {
    return roles.value.filter(role => role.permissions.some(entry => entry.id === permission.id))
}

/* ------------------------------------------------------------------ *
 *  Table definition (cells rendered via the slots below)
 * ------------------------------------------------------------------ */

const table = useTemplateRef('table')
const columnVisibility = ref({})

const columns = computed(() => [
    { accessorKey: 'name', header: t('messages.access.permissions.col_name'), enableHiding: false },
    { accessorKey: 'roles', header: t('messages.access.permissions.col_roles') },
])

const columnLabels = computed(() => ({
    roles: t('messages.access.permissions.col_roles'),
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
                    <span class="text-sm text-muted">{{ t('messages.access.permissions.stats_total') }}</span>
                    <span class="flex items-center justify-center size-8 rounded-lg bg-primary/10">
                        <UIcon name="i-tabler-key" class="size-5 text-primary"/>
                    </span>
                </div>
                <span class="text-2xl font-semibold text-highlighted">{{ stats.total }}</span>
            </div>

            <div class="border border-default rounded-lg p-4 flex flex-col gap-1">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted">{{ t('messages.access.permissions.stats_unassigned') }}</span>
                    <span class="flex items-center justify-center size-8 rounded-lg bg-warning/10">
                        <UIcon name="i-tabler-key-off" class="size-5 text-warning"/>
                    </span>
                </div>
                <span class="text-2xl font-semibold text-highlighted">{{ stats.unassigned }}</span>
            </div>

            <div class="border border-default rounded-lg p-4 flex flex-col gap-1">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted">{{ t('messages.access.permissions.stats_direct') }}</span>
                    <span class="flex items-center justify-center size-8 rounded-lg bg-info/10">
                        <UIcon name="i-tabler-user-shield" class="size-5 text-info"/>
                    </span>
                </div>
                <span class="text-2xl font-semibold text-highlighted">{{ stats.direct_grants }}</span>
            </div>

            <div class="border border-default rounded-lg p-4 flex flex-col gap-1">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted">{{ t('messages.access.permissions.stats_most_granted') }}</span>
                    <span class="flex items-center justify-center size-8 rounded-lg bg-success/10">
                        <UIcon name="i-tabler-trophy" class="size-5 text-success"/>
                    </span>
                </div>
                <template v-if="stats.most_granted">
                    <span
                        class="text-lg font-semibold font-mono text-highlighted truncate"
                        :title="stats.most_granted.name"
                    >
                        {{ stats.most_granted.name }}
                    </span>
                    <span class="text-xs text-muted">
                        {{ t('messages.access.permissions.stats_most_granted_count', stats.most_granted.roles_count) }}
                    </span>
                </template>
                <span v-else class="text-2xl font-semibold text-highlighted">&mdash;</span>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <UInput
                v-model="search"
                :placeholder="t('messages.access.permissions.search_placeholder')"
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

            <UDropdownMenu
                :items="columnVisibilityItems"
                :content="{ align: 'end' }"
                class="ms-auto"
            >
                <UButton
                    :label="t('messages.access.columns')"
                    color="neutral"
                    variant="outline"
                    trailing-icon="i-tabler-chevron-down"
                />
            </UDropdownMenu>
        </div>

        <UTable
            ref="table"
            v-model:column-visibility="columnVisibility"
            :data="permissions"
            :columns="columns"
            :loading="loading || rolesLoading"
            class="border border-default rounded-lg"
        >
            <template #loading>
                <TableSkeleton/>
            </template>

            <template #name-cell="{ row }">
                <span class="font-mono font-medium text-highlighted">{{ row.original.name }}</span>
            </template>

            <template #roles-cell="{ row }">
                <div class="flex items-center gap-1 flex-wrap">
                    <UBadge
                        v-for="role in rolesGranting(row.original).slice(0, BADGE_LIMIT)"
                        :key="role.id"
                        :label="role.name"
                        color="neutral"
                        variant="subtle"
                        size="sm"
                    />
                    <UBadge
                        v-if="rolesGranting(row.original).length > BADGE_LIMIT"
                        :label="`+${rolesGranting(row.original).length - BADGE_LIMIT}`"
                        color="info"
                        variant="subtle"
                        size="sm"
                    />
                    <UBadge
                        v-if="rolesGranting(row.original).length === 0"
                        :label="t('messages.access.permissions.unassigned')"
                        color="warning"
                        variant="subtle"
                        size="sm"
                    />
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
                    {{ t('messages.access.permissions.showing', { from: showingFrom, to: showingTo, total }) }}
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
</template>
