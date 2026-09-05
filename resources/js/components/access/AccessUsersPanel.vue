<script setup>
import { useRbac } from '@/plugins/rbac/composables/useRbac.js'
import { useAuthStore } from '@/stores/AuthStore.js'
import AccessService from '@/services/AccessService.js'
import { useAccessUserDisplay } from '@/composables/useAccessUserDisplay.js'
import { useCopyText } from '@/composables/useCopyText.js'

const { t } = useI18n()
const { can } = useRbac()
const toast = useAppToast()
const router = useRouter()
const authStore = useAuthStore()
const { fullName, formatDateTime, formatDate, statusOf } = useAccessUserDisplay()

const accessService = new AccessService()

/* Impersonation is offered when the deployment has it switched on and the admin holds the capability;
 * per-row it is further hidden for the admin's own account and for targets above
 * the impersonation tier (`impersonable`, computed server-side - the server stays authoritative). */
const canImpersonate = computed(() => can('users.impersonate') && authStore.user?.impersonation_available === true)

function mayImpersonate (user) {
    return canImpersonate.value && user.id !== authStore.user?.id && user.impersonable !== false
}

/* ------------------------------------------------------------------ *
 *  Users data table
 *
 *  Toolbar (search + filters slideover + column visibility) over a
 *  server-paged table. Clicking a row navigates to the user detail
 *  page; the shared editor modal reshapes roles and direct permissions
 *  through the access API.
 * ------------------------------------------------------------------ */

const route = useRoute()

/* ------------------------------------------------------------------ *
 *  Route-query state
 *
 *  Search, filters and pagination live in the URL, so a narrowed view
 *  survives a refresh, deep-links, and can be handed to a colleague.
 *  Unknown or invalid query values fall back to the defaults.
 * ------------------------------------------------------------------ */

const STATUS_VALUES = ['active', 'inactive', 'banned', 'deleted']
const TWO_FACTOR_VALUES = ['enabled', 'required', 'disabled']
const ONBOARDING_VALUES = ['invited', 'reset_pending', 'unverified']
const PAGE_SIZES = [10, 25, 50, 100]
const SYNCED_QUERY_KEYS = ['search', 'role', 'status', 'two_factor', 'onboarding', 'page', 'per_page']

/**
 * The table state a route query describes, defaults filled in.
 *
 * @param {Object} query - The route's query object.
 */
function stateFromQuery (query) {
    return {
        search: typeof query.search === 'string' ? query.search : '',
        role: typeof query.role === 'string' && Number(query.role) > 0 ? Number(query.role) : 'all',
        status: STATUS_VALUES.includes(query.status) ? query.status : 'all',
        two_factor: TWO_FACTOR_VALUES.includes(query.two_factor) ? query.two_factor : 'all',
        onboarding: ONBOARDING_VALUES.includes(query.onboarding) ? query.onboarding : 'all',
        page: Number(query.page) > 1 ? Math.floor(Number(query.page)) : 1,
        per_page: PAGE_SIZES.includes(Number(query.per_page)) ? Number(query.per_page) : 25,
    }
}

const initialState = stateFromQuery(route.query)

const users = ref([])
const total = ref(0)
const page = ref(initialState.page)
const pageSize = ref(initialState.per_page)
const search = ref(initialState.search)
const roleFilter = ref(initialState.role)
const statusFilter = ref(initialState.status)
const twoFactorFilter = ref(initialState.two_factor)
const onboardingFilter = ref(initialState.onboarding)
const loading = ref(true)

const roles = ref([])
const stats = ref(null)
const statsLoading = ref(true)

const canManage = computed(() => can('users.manage'))

const pageSizeItems = [10, 25, 50, 100].map(size => ({ label: String(size), value: size }))

function activeFilters () {
    return {
        search: search.value.trim() || undefined,
        role_id: roleFilter.value === 'all' ? undefined : roleFilter.value,
        status: statusFilter.value === 'all' ? undefined : statusFilter.value,
        two_factor: twoFactorFilter.value === 'all' ? undefined : twoFactorFilter.value,
        onboarding: onboardingFilter.value === 'all' ? undefined : onboardingFilter.value,
    }
}

/**
 * The route query this table state deserves - defaults omitted, so a
 * pristine table keeps a pristine URL.
 */
function queryFromState () {
    const query = {}

    if (search.value.trim() !== '') {
        query.search = search.value.trim()
    }
    if (roleFilter.value !== 'all') {
        query.role = String(roleFilter.value)
    }
    if (statusFilter.value !== 'all') {
        query.status = statusFilter.value
    }
    if (twoFactorFilter.value !== 'all') {
        query.two_factor = twoFactorFilter.value
    }
    if (onboardingFilter.value !== 'all') {
        query.onboarding = onboardingFilter.value
    }
    if (page.value !== 1) {
        query.page = String(page.value)
    }
    if (pageSize.value !== 25) {
        query.per_page = String(pageSize.value)
    }

    return query
}

/* State -> URL. replace() keeps browsing history clean of every keystroke;
 * foreign query keys are preserved. The difference check breaks the loop
 * with the URL -> state watcher below. */
watch([search, roleFilter, statusFilter, twoFactorFilter, onboardingFilter, page, pageSize], () => {
    const desired = queryFromState()

    const current = {}
    for (const key of SYNCED_QUERY_KEYS) {
        if (typeof route.query[key] === 'string') {
            current[key] = route.query[key]
        }
    }

    if (JSON.stringify(current) !== JSON.stringify(desired)) {
        const foreign = Object.fromEntries(Object.entries(route.query)
            .filter(([key]) => !SYNCED_QUERY_KEYS.includes(key)))
        router.replace({ query: { ...foreign, ...desired } })
    }
})

/* URL -> state, for back/forward navigation. Only differing values are
 * assigned, so a write-back of our own replace() is a no-op. */
watch(() => route.query, (query) => {
    const state = stateFromQuery(query)

    if (search.value !== state.search) {
        search.value = state.search
    }
    if (roleFilter.value !== state.role) {
        roleFilter.value = state.role
    }
    if (statusFilter.value !== state.status) {
        statusFilter.value = state.status
    }
    if (twoFactorFilter.value !== state.two_factor) {
        twoFactorFilter.value = state.two_factor
    }
    if (onboardingFilter.value !== state.onboarding) {
        onboardingFilter.value = state.onboarding
    }
    if (pageSize.value !== state.per_page) {
        pageSize.value = state.per_page
    }
    if (page.value !== state.page) {
        page.value = state.page
    }
})

async function loadUsers () {
    loading.value = true
    try {
        const data = await accessService.fetchUsers({
            page: page.value,
            per_page: pageSize.value,
            ...activeFilters(),
        })
        users.value = data.users
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
        const data = await accessService.fetchUserStats()
        stats.value = data.stats
    } catch {
        stats.value = null
    } finally {
        statsLoading.value = false
    }
}

/* The role filter needs the roles dictionary, which sits behind roles.view,
 * without the capability the request would only ever 403 (view-only and impersonate-only admins). */
const canFilterByRole = computed(() => can('roles.view'))

async function loadDictionaries () {
    if (!canFilterByRole.value) {
        return
    }

    try {
        const rolesData = await accessService.fetchRoles()
        roles.value = rolesData.roles
    } catch (error) {
        toast.add({
            title: t('messages.access.load_failed'),
            description: error.detail ?? t('messages.common.errors.network_description'),
            color: 'error',
        })
    }
}

onMounted(() => {
    loadUsers()
    loadStats()
    loadDictionaries()
})

/* Resetting to page 1 triggers the page watcher; when already there,
 * reload directly so the change still applies. Changing the search or a
 * filter re-scopes the list, so the cross-page selection is dropped too -
 * plain page navigation keeps it (rows are keyed by user id). */
function resetToFirstPage () {
    clearSelection()

    if (page.value === 1) {
        loadUsers()
    } else {
        page.value = 1
    }
}

let searchDebounce = null
watch(search, () => {
    clearTimeout(searchDebounce)
    searchDebounce = setTimeout(resetToFirstPage, 300)
})

watch([roleFilter, statusFilter, twoFactorFilter, onboardingFilter, pageSize], resetToFirstPage)

watch(page, () => loadUsers())

const showingFrom = computed(() => (total.value === 0 ? 0 : (page.value - 1) * pageSize.value + 1))
const showingTo = computed(() => Math.min(page.value * pageSize.value, total.value))

/* ------------------------------------------------------------------ *
 *  Filters slideover (houses every list filter, present and future)
 * ------------------------------------------------------------------ */

const filtersOpen = ref(false)

const roleFilterItems = computed(() => [
    { label: t('messages.access.users.all_roles'), value: 'all' },
    ...roles.value.map(role => ({ label: role.name, value: role.id })),
])

const statusFilterItems = computed(() => [
    { label: t('messages.access.users.all_statuses'), value: 'all' },
    { label: t('messages.access.users.active'), value: 'active' },
    { label: t('messages.access.users.inactive'), value: 'inactive' },
    { label: t('messages.access.users.banned'), value: 'banned' },
    { label: t('messages.access.users.status_deleted'), value: 'deleted' },
])

/* The three states of the two-factor column, as a narrowing. */
const twoFactorFilterItems = computed(() => [
    { label: t('messages.access.users.any'), value: 'all' },
    { label: t('messages.access.users.enabled'), value: 'enabled' },
    { label: t('messages.access.users.two_factor_mandated'), value: 'required' },
    { label: t('messages.access.users.disabled'), value: 'disabled' },
])

/* The not-fully-landed flavors, each with a matching admin action. */
const onboardingFilterItems = computed(() => [
    { label: t('messages.access.users.any'), value: 'all' },
    { label: t('messages.access.users.invited_badge'), value: 'invited' },
    { label: t('messages.access.users.reset_required'), value: 'reset_pending' },
    { label: t('messages.access.users.unverified'), value: 'unverified' },
])

const activeFilterCount = computed(() =>
    (roleFilter.value !== 'all' ? 1 : 0)
    + (statusFilter.value !== 'all' ? 1 : 0)
    + (twoFactorFilter.value !== 'all' ? 1 : 0)
    + (onboardingFilter.value !== 'all' ? 1 : 0))

function clearFilters () {
    roleFilter.value = 'all'
    statusFilter.value = 'all'
    twoFactorFilter.value = 'all'
    onboardingFilter.value = 'all'
}

/* ------------------------------------------------------------------ *
 *  Membership lookup ("was this email ever an account?")
 *
 *  Live accounts match by address; retired ones by the tombstone hash,
 *  which is the only trace of the address a deletion leaves behind.
 * ------------------------------------------------------------------ */

const membershipEmail = ref('')
const membershipChecking = ref(false)
const membershipResult = ref(null)
const membershipError = ref('')

// A fresh input invalidates both the previous answer and the previous complaint.
watch(membershipEmail, () => {
    membershipError.value = ''
    membershipResult.value = null
})

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

const membershipText = computed(() => {
    if (!membershipResult.value) {
        return ''
    }

    return {
        active: t('messages.access.users.membership_active'),
        deleted: t('messages.access.users.membership_deleted'),
        none: t('messages.access.users.membership_none'),
    }[membershipResult.value.status] ?? ''
})

async function checkMembership () {
    const email = membershipEmail.value.trim()

    if (!email || membershipChecking.value) {
        return
    }

    if (!EMAIL_PATTERN.test(email)) {
        membershipError.value = t('messages.access.users.membership_invalid_email')
        return
    }

    membershipChecking.value = true
    membershipResult.value = null
    membershipError.value = ''
    try {
        membershipResult.value = await accessService.lookupMembership(email)
    } catch (error) {
        // Validation complaints belong on the field (the server's specific
        // message rides in errors[0]); everything else keeps the toast.
        if (error.isValidationError) {
            membershipError.value = error.errors?.[0]?.detail
                ?? t('messages.access.users.membership_invalid_email')
            return
        }

        toast.add({
            title: t('messages.access.load_failed'),
            description: error.detail ?? t('messages.common.errors.network_description'),
            color: 'error',
        })
    } finally {
        membershipChecking.value = false
    }
}

/* ------------------------------------------------------------------ *
 *  Table definition (cells rendered via the slots below)
 * ------------------------------------------------------------------ */

const table = useTemplateRef('table')
const columnVisibility = ref({})
const rowSelection = ref({})

/* Out-of-reach rows (the target ceiling) cannot enter the bulk selection,
 * every bulk action would just 422 for them. TanStack consults this for select-all too,
 * so the header checkbox only ever gathers rows the admin can actually act on. */
const rowSelectionOptions = { enableRowSelection: row => row.original.manageable !== false }

const columns = computed(() => [
    ...(canManage.value ? [{ id: 'select', enableHiding: false, meta: { class: { td: 'w-10' } } }] : []),
    { accessorKey: 'name', header: t('messages.access.users.col_name'), enableHiding: false },
    { accessorKey: 'roles', header: t('messages.access.users.col_roles') },
    { accessorKey: 'direct_permissions', header: t('messages.access.users.col_direct') },
    { accessorKey: 'last_login_at', header: t('messages.access.users.col_last_login') },
    { accessorKey: 'two_factor_enabled', header: t('messages.access.users.col_two_factor') },
    { accessorKey: 'require_password_reset', header: t('messages.access.users.col_reset_required') },
    { accessorKey: 'is_active', header: t('messages.access.users.col_status') },
    { accessorKey: 'created_at', header: t('messages.access.users.member_since') },
    {
        id: 'actions',
        header: t('messages.access.users.actions'),
        enableHiding: false,
        meta: { class: { td: 'text-right', th: 'text-right' } },
    },
])

const columnLabels = computed(() => ({
    roles: t('messages.access.users.col_roles'),
    direct_permissions: t('messages.access.users.col_direct'),
    last_login_at: t('messages.access.users.col_last_login'),
    two_factor_enabled: t('messages.access.users.col_two_factor'),
    require_password_reset: t('messages.access.users.col_reset_required'),
    is_active: t('messages.access.users.col_status'),
    created_at: t('messages.access.users.member_since'),
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

/* View is an inline icon button; the menu carries whatever the admin can actually do to the row.
 * Account mutations need users.manage plus a within-reach target; impersonation rides its own capability and tier,
 * so a view+impersonate support role still gets its action without any account management. */
function mayMutate (user) {
    return canManage.value && user.manageable !== false
}

function hasRowActions (user) {
    return mayMutate(user) || mayImpersonate(user)
}

function rowActions (user) {
    return [
        { type: 'label', label: t('messages.access.users.actions') },

        ...(mayMutate(user)
            ? [
                {
                    label: user.is_active
                        ? t('messages.access.users.deactivate')
                        : t('messages.access.users.activate'),
                    icon: user.is_active ? 'i-tabler-user-off' : 'i-tabler-user-check',
                    onSelect: () => setActive(user, !user.is_active),
                },
                ...(user.invitable
                    ? [{
                        label: t('messages.access.users.resend_invitation'),
                        icon: 'i-tabler-mail-forward',
                        color: 'success',
                        onSelect: () => resendInvitation(user),
                    }]
                    : []),
                {
                    label: t('messages.access.users.force_reset'),
                    icon: 'i-tabler-key',
                    color: 'warning',
                    onSelect: () => openResetModal(user),
                },
            ]
            : []),

        ...(mayImpersonate(user)
            ? [{
                label: t('messages.access.users.impersonate'),
                icon: 'i-tabler-user-shield',
                class: 'text-violet-600 data-highlighted:text-violet-600 data-highlighted:before:bg-violet-600/10'
                    + ' dark:text-violet-400 dark:data-highlighted:text-violet-400 dark:data-highlighted:before:bg-violet-400/10',
                ui: {
                    itemLeadingIcon: 'text-violet-600/75 group-data-highlighted:text-violet-600'
                        + ' dark:text-violet-400/75 dark:group-data-highlighted:text-violet-400',
                },
                onSelect: () => openImpersonateModal(user),
            }]
            : []),

        ...(mayMutate(user)
            ? [
                { type: 'separator' },
                {
                    label: t('messages.access.users.delete_account'),
                    icon: 'i-tabler-trash',
                    color: 'error',
                    onSelect: () => openDeleteModal(user),
                },
            ]
            : []),
    ]
}

async function setActive (user, active) {
    try {
        await accessService.updateUser(user.id, { is_active: active })
        toast.add({
            title: t('messages.access.users.account_updated'),
            color: 'success',
        })
        loadUsers()
        loadStats()
    } catch (error) {
        mutationErrorToast(error)
    }
}

/*
 * Re-mail a pending invitation's first-sign-in link;
 * the server revokes the previous one and refuses accounts that were already entered.
 */
async function resendInvitation (user) {
    try {
        await accessService.resendInvitation(user.id)
        toast.add({
            title: t('messages.access.users.invitation_resent'),
            color: 'success',
        })
        loadUsers()
    } catch (error) {
        mutationErrorToast(error)
    }
}

/* ------------------------------------------------------------------ *
 *  Detail page navigation (viewing and editing both live there)
 * ------------------------------------------------------------------ */

function openDetail (user) {
    router.push(`/app/access/users/${user.id}`)
}

function onRowSelect (event, row) {
    openDetail(row.original)
}

function mutationErrorToast (error) {
    toast.add({
        title: t('messages.access.save_failed'),
        description: error.errors?.[0]?.detail ?? error.detail ?? t('messages.common.errors.network_description'),
        color: 'error',
    })
}

/* ------------------------------------------------------------------ *
 *  Force password reset (same flow as the detail page: the server generates a temporary password shown exactly once)
 * ------------------------------------------------------------------ */

const resetOpen = ref(false)
const resetDone = ref(false)
const resetTarget = ref(null)
const tempPassword = ref('')
const forcingReset = ref(false)
const { copied: passwordCopied, copy: copyText, reset: resetCopiedFlag } = useCopyText()

function openResetModal (user) {
    resetTarget.value = user
    tempPassword.value = ''
    resetDone.value = false
    resetCopiedFlag()
    resetOpen.value = true
}

async function copyTempPassword () {
    if (!await copyText(tempPassword.value)) {
        toast.add({
            title: t('messages.access.users.copy_failed'),
            color: 'error',
        })
    }
}

async function forceReset () {
    forcingReset.value = true
    try {
        const data = await accessService.forcePasswordReset(resetTarget.value.id)
        tempPassword.value = data.temporary_password
        resetDone.value = true
        toast.add({
            title: t('messages.access.users.reset_forced'),
            color: 'success',
        })
        loadUsers()
    } catch (error) {
        mutationErrorToast(error)
    } finally {
        forcingReset.value = false
    }
}

function closeResetModal () {
    resetOpen.value = false
    resetTarget.value = null
    tempPassword.value = ''
    resetDone.value = false
}

/* ------------------------------------------------------------------ *
 *  Impersonation (start; the exit lives in the global banner)
 * ------------------------------------------------------------------ */

const impersonateOpen = ref(false)
const impersonateTarget = ref(null)
const impersonating = ref(false)

function openImpersonateModal (user) {
    impersonateTarget.value = user
    impersonateOpen.value = true
}

async function confirmImpersonate () {
    impersonating.value = true
    try {
        await authStore.impersonate(impersonateTarget.value.id)
        impersonateOpen.value = false
        router.push('/app')
    } catch (error) {
        toast.add({
            title: t('messages.access.users.impersonate_failed'),
            description: error.errors?.[0]?.detail ?? error.detail ?? t('messages.common.errors.network_description'),
            color: 'error',
        })
    } finally {
        impersonating.value = false
    }
}

/* ------------------------------------------------------------------ *
 *  Account deletion
 * ------------------------------------------------------------------ */

const deleteOpen = ref(false)
const deleteTarget = ref(null)
const deleting = ref(false)

function openDeleteModal (user) {
    deleteTarget.value = user
    deleteOpen.value = true
}

async function deleteAccount () {
    deleting.value = true
    try {
        await accessService.deleteUser(deleteTarget.value.id)
        deleteOpen.value = false
        toast.add({
            title: t('messages.access.users.deleted'),
            color: 'success',
        })
        loadUsers()
        loadStats()
    } catch (error) {
        deleteOpen.value = false
        mutationErrorToast(error)
    } finally {
        deleting.value = false
    }
}

/* ------------------------------------------------------------------ *
 *  Bulk actions (checkbox selection; each target goes through the same single-user endpoints, so the lockout guards apply per user)
 * ------------------------------------------------------------------ */

const selectedCount = computed(() => Object.values(rowSelection.value).filter(Boolean).length)
const bulkDeleteOpen = ref(false)
const bulkWorking = ref(false)

/* Selection is keyed by user id (getRowId) and survives page changes, so the targets come from the selection state itself,
 * the row model only knows the page currently rendered. */
function selectedUserIds () {
    return Object.keys(rowSelection.value).filter(id => rowSelection.value[id])
}

function clearSelection () {
    rowSelection.value = {}
}

function reportBulk (results) {
    const failed = results.filter(result => result.status === 'rejected')

    if (failed.length === 0) {
        toast.add({
            title: t('messages.access.users.bulk_success', { count: results.length }),
            color: 'success',
        })
        return
    }

    toast.add({
        title: t('messages.access.users.bulk_partial', {
            done: results.length - failed.length,
            total: results.length,
            failed: failed.length,
        }),
        description: failed[0].reason?.errors?.[0]?.detail ?? failed[0].reason?.detail ?? undefined,
        color: 'error',
    })
}

async function bulkDelete () {
    const targets = selectedUserIds()
    bulkDeleteOpen.value = false
    bulkWorking.value = true

    reportBulk(await Promise.allSettled(targets.map(id => accessService.deleteUser(id))))

    clearSelection()
    await loadUsers()
    loadStats()
    bulkWorking.value = false
}

async function bulkSetActive (active) {
    const targets = selectedUserIds()
    bulkWorking.value = true

    reportBulk(await Promise.allSettled(
        targets.map(id => accessService.updateUser(id, { is_active: active }))
    ))

    clearSelection()
    await loadUsers()
    loadStats()
    bulkWorking.value = false
}

const bulkStatusItems = computed(() => [
    {
        label: t('messages.access.users.activate'),
        icon: 'i-tabler-user-check',
        onSelect: () => bulkSetActive(true),
    },
    {
        label: t('messages.access.users.deactivate'),
        icon: 'i-tabler-user-off',
        onSelect: () => bulkSetActive(false),
    },
])

/* ------------------------------------------------------------------ *
 *  CSV export (current filters, no pagination)
 * ------------------------------------------------------------------ */

const exporting = ref(false)

async function exportCsv () {
    exporting.value = true
    try {
        const blob = await accessService.exportUsers(activeFilters())

        const url = URL.createObjectURL(blob)
        const anchor = document.createElement('a')
        anchor.href = url
        anchor.download = `users-${new Date().toISOString().slice(0, 10)}.csv`
        anchor.click()
        URL.revokeObjectURL(url)
    } catch {
        toast.add({
            title: t('messages.access.users.export_failed'),
            color: 'error',
        })
    } finally {
        exporting.value = false
    }
}
</script>

<template>
    <div class="flex flex-col flex-1 w-full gap-4">
        <div v-if="statsLoading" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <div
                v-for="card in 5"
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

        <div v-else-if="stats" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <div class="border border-default rounded-lg p-4 flex flex-col gap-1">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted">{{ t('messages.access.users.stats_total') }}</span>
                    <span class="flex items-center justify-center size-8 rounded-lg bg-primary/10">
                        <UIcon name="i-tabler-users" class="size-5 text-primary"/>
                    </span>
                </div>
                <span class="text-2xl font-semibold text-highlighted">{{ stats.total }}</span>
            </div>

            <div class="border border-default rounded-lg p-4 flex flex-col gap-1">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted">{{ t('messages.access.users.stats_active') }}</span>
                    <span class="flex items-center justify-center size-8 rounded-lg bg-success/10">
                        <UIcon name="i-tabler-user-check" class="size-5 text-success"/>
                    </span>
                </div>
                <span class="text-2xl font-semibold text-highlighted">{{ stats.active }}</span>
            </div>

            <div class="border border-default rounded-lg p-4 flex flex-col gap-1">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted">{{ t('messages.access.users.stats_unverified') }}</span>
                    <span class="flex items-center justify-center size-8 rounded-lg bg-warning/10">
                        <UIcon name="i-tabler-user-exclamation" class="size-5 text-warning"/>
                    </span>
                </div>
                <span class="text-2xl font-semibold text-highlighted">{{ stats.unverified }}</span>
            </div>

            <div class="border border-default rounded-lg p-4 flex flex-col gap-1">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted">{{ t('messages.access.users.stats_deleted') }}</span>
                    <span class="flex items-center justify-center size-8 rounded-lg bg-elevated">
                        <UIcon name="i-tabler-user-x" class="size-5 text-muted"/>
                    </span>
                </div>
                <span class="text-2xl font-semibold text-highlighted">{{ stats.deleted }}</span>
            </div>

            <div class="border border-default rounded-lg p-4 flex flex-col gap-1">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted">{{ t('messages.access.users.stats_new_week') }}</span>
                    <span class="flex items-center justify-center size-8 rounded-lg bg-info/10">
                        <UIcon name="i-tabler-user-plus" class="size-5 text-info"/>
                    </span>
                </div>
                <div class="flex items-baseline gap-2">
                    <span class="text-2xl font-semibold text-highlighted">{{ stats.new_this_week }}</span>
                    <span
                        v-if="stats.new_this_week_delta !== null"
                        :class="stats.new_this_week_delta >= 0 ? 'text-success' : 'text-error'"
                        class="text-xs font-medium"
                    >
                        {{ stats.new_this_week_delta >= 0 ? '+' : '' }}{{ stats.new_this_week_delta }}%
                    </span>
                </div>
                <span v-if="stats.new_this_week_delta !== null" class="text-xs text-muted">
                    {{ t('messages.access.users.stats_vs_previous') }}
                </span>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <UInput
                v-model="search"
                :placeholder="t('messages.access.users.search_placeholder')"
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

            <div class="flex items-center gap-2 ms-auto">
                <!-- Kept mounted, visibility-toggled: the button appearing must not reflow the toolbar. -->
                <UTooltip :text="t('messages.access.clear_filters')">
                    <UButton
                        icon="i-tabler-filter-x"
                        color="neutral"
                        variant="ghost"
                        :class="activeFilterCount > 0 ? '' : 'invisible'"
                        :aria-label="t('messages.access.clear_filters')"
                        @click="clearFilters"
                    />
                </UTooltip>

                <UChip
                    :show="activeFilterCount > 0"
                    :text="activeFilterCount"
                    size="3xl"
                >
                    <UButton
                        :label="t('messages.access.filters')"
                        icon="i-tabler-filter"
                        color="neutral"
                        variant="outline"
                        @click="filtersOpen = true"
                    />
                </UChip>

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
                    :label="t('messages.access.users.export')"
                    icon="i-tabler-download"
                    color="neutral"
                    variant="outline"
                    :loading="exporting"
                    @click="exportCsv"
                />

                <UButton
                    v-if="canManage"
                    :label="t('messages.access.users.add_user')"
                    icon="i-tabler-plus"
                    to="/app/access/users/new"
                />
            </div>
        </div>

        <!-- Always occupies its row, visibility-toggled: selecting the first
             checkbox must not push the table down (and clearing, snap it back). -->
        <div
            class="flex flex-wrap items-center gap-3"
            :class="selectedCount > 0 ? '' : 'invisible'"
        >
            <span class="text-sm font-medium text-highlighted">
                {{ t('messages.access.users.selected_count', { count: selectedCount }) }}
            </span>

            <UButton
                :label="t('messages.access.users.delete_selected')"
                icon="i-tabler-trash"
                color="error"
                variant="soft"
                size="sm"
                :loading="bulkWorking"
                @click="bulkDeleteOpen = true"
            />

            <UDropdownMenu :items="bulkStatusItems">
                <UButton
                    :label="t('messages.access.users.change_status')"
                    color="neutral"
                    variant="outline"
                    size="sm"
                    trailing-icon="i-tabler-chevron-down"
                    :disabled="bulkWorking"
                />
            </UDropdownMenu>

            <UButton
                :label="t('messages.access.users.clear_selection')"
                color="neutral"
                variant="ghost"
                size="sm"
                :disabled="bulkWorking"
                @click="clearSelection"
            />
        </div>

        <UTable
            ref="table"
            v-model:column-visibility="columnVisibility"
            v-model:row-selection="rowSelection"
            :data="users"
            :columns="columns"
            :loading="loading"
            :get-row-id="user => String(user.id)"
            :row-selection-options="rowSelectionOptions"
            class="border border-default rounded-lg"
            @select="onRowSelect"
        >
            <template #loading>
                <TableSkeleton avatar stacked/>
            </template>

            <template #select-header="{ table: tableApi }">
                <UCheckbox
                    :model-value="tableApi.getIsSomePageRowsSelected()
                        ? 'indeterminate'
                        : tableApi.getIsAllPageRowsSelected()"
                    :aria-label="t('messages.access.users.select_all')"
                    @update:model-value="value => tableApi.toggleAllPageRowsSelected(!!value)"
                />
            </template>

            <template #select-cell="{ row }">
                <div @click.stop>
                    <UCheckbox
                        v-if="row.getCanSelect()"
                        :model-value="row.getIsSelected()"
                        :aria-label="t('messages.access.users.select_row')"
                        @update:model-value="value => row.toggleSelected(!!value)"
                    />
                </div>
            </template>

            <template #name-cell="{ row }">
                <div class="flex items-center gap-3">
                    <UAvatar :alt="fullName(row.original)" size="md"/>
                    <div class="min-w-0">
                        <p class="font-medium text-highlighted truncate">{{ fullName(row.original) }}</p>
                        <!-- A tombstoned address is a meaningless uuid; show when the account ended instead. -->
                        <p v-if="row.original.deleted_at" class="text-sm text-muted truncate">
                            {{ formatDate(row.original.deleted_at) }}
                        </p>
                        <div v-else class="flex items-center gap-1.5">
                            <p class="text-sm text-muted truncate">{{ row.original.email }}</p>
                            <UTooltip
                                v-if="row.original.email_verified"
                                :text="t('messages.access.users.email_verified')"
                            >
                                <UIcon name="i-tabler-circle-check" class="size-4 text-success shrink-0"/>
                            </UTooltip>
                        </div>
                    </div>
                </div>
            </template>

            <template #roles-cell="{ row }">
                <div class="flex items-center gap-1 flex-wrap">
                    <UBadge
                        v-for="role in row.original.roles.slice(0, 2)"
                        :key="role.id"
                        :label="role.name"
                        color="neutral"
                        variant="subtle"
                        size="sm"
                    />
                    <UBadge
                        v-if="row.original.roles.length > 2"
                        :label="`+${row.original.roles.length - 2}`"
                        color="neutral"
                        variant="outline"
                        size="sm"
                    />
                    <span v-if="row.original.roles.length === 0" class="text-muted">—</span>
                </div>
            </template>

            <template #direct_permissions-cell="{ row }">
                <UBadge
                    v-if="row.original.direct_permissions.length"
                    :label="String(row.original.direct_permissions.length)"
                    color="info"
                    variant="subtle"
                    size="sm"
                />
                <span v-else class="text-muted">—</span>
            </template>

            <template #last_login_at-cell="{ row }">
                <span :class="row.original.last_login_at ? '' : 'text-muted'">
                    {{ formatDateTime(row.original.last_login_at) ?? t('messages.access.users.never') }}
                </span>
            </template>

            <template #two_factor_enabled-cell="{ row }">
                <!-- Three states: enrolled, mandated-but-not-enrolled (the gap an admin wants to spot), off. -->
                <UBadge
                    v-if="row.original.two_factor_enabled"
                    :label="t('messages.access.users.enabled')"
                    color="success"
                    variant="subtle"
                    size="sm"
                />
                <UBadge
                    v-else-if="row.original.two_factor_required"
                    :label="t('messages.access.users.two_factor_mandated')"
                    color="warning"
                    variant="subtle"
                    size="sm"
                />
                <UBadge
                    v-else
                    :label="t('messages.access.users.disabled')"
                    color="neutral"
                    variant="subtle"
                    size="sm"
                />
            </template>

            <template #require_password_reset-cell="{ row }">
                <!-- A badge only when action is pending; a badge on every row would drown the signal. -->
                <UBadge
                    v-if="row.original.require_password_reset"
                    :label="t('messages.access.users.reset_required_badge')"
                    color="warning"
                    variant="subtle"
                    size="sm"
                />
                <span v-else class="text-muted">—</span>
            </template>

            <template #is_active-cell="{ row }">
                <div class="flex items-center gap-1">
                    <UBadge
                        :label="statusOf(row.original).label"
                        :color="statusOf(row.original).color"
                        variant="subtle"
                        size="sm"
                    />
                    <UBadge
                        v-if="row.original.invitation_pending"
                        :label="t('messages.access.users.invited_badge')"
                        color="info"
                        variant="subtle"
                        size="sm"
                    />
                </div>
            </template>

            <template #created_at-cell="{ row }">
                {{ formatDate(row.original.created_at) ?? '—' }}
            </template>

            <template #actions-cell="{ row }">
                <div class="flex items-center justify-end gap-1" @click.stop>
                    <UButton
                        icon="i-tabler-eye"
                        color="neutral"
                        variant="ghost"
                        :aria-label="t('messages.access.users.view')"
                        @click="openDetail(row.original)"
                    />
                    <UDropdownMenu
                        v-if="hasRowActions(row.original)"
                        :items="rowActions(row.original)"
                        :content="{ align: 'end' }"
                        :aria-label="t('messages.access.users.actions')"
                    >
                        <UButton
                            icon="i-tabler-dots-vertical"
                            color="neutral"
                            variant="ghost"
                            :aria-label="t('messages.access.users.actions')"
                        />
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
                    {{ t('messages.access.users.showing', { from: showingFrom, to: showingTo, total }) }}
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

    <USlideover
        v-model:open="filtersOpen"
        :title="t('messages.access.filters')"
        :description="t('messages.access.users.filters_description')"
    >
        <template #body>
            <div class="flex flex-col gap-4">
                <UFormField v-if="canFilterByRole" :label="t('messages.access.users.role_filter')">
                    <USelect
                        v-model="roleFilter"
                        :items="roleFilterItems"
                        class="w-full"
                    />
                </UFormField>

                <UFormField :label="t('messages.access.users.status_filter')">
                    <USelect
                        v-model="statusFilter"
                        :items="statusFilterItems"
                        class="w-full"
                    />
                </UFormField>

                <UFormField :label="t('messages.access.users.two_factor_filter')">
                    <USelect
                        v-model="twoFactorFilter"
                        :items="twoFactorFilterItems"
                        class="w-full"
                    />
                </UFormField>

                <UFormField :label="t('messages.access.users.onboarding_filter')">
                    <USelect
                        v-model="onboardingFilter"
                        :items="onboardingFilterItems"
                        class="w-full"
                    />
                </UFormField>

                <USeparator/>

                <UFormField
                    :label="t('messages.access.users.membership_title')"
                    :description="t('messages.access.users.membership_description')"
                    :error="membershipError || undefined"
                >
                    <div class="flex items-center gap-2">
                        <UInput
                            v-model="membershipEmail"
                            type="email"
                            :placeholder="t('messages.access.users.membership_placeholder')"
                            class="flex-1"
                            @keyup.enter="checkMembership"
                        />
                        <UButton
                            :label="t('messages.access.users.membership_check')"
                            :loading="membershipChecking"
                            color="neutral"
                            variant="outline"
                            @click="checkMembership"
                        />
                    </div>
                </UFormField>

                <div v-if="membershipResult" class="flex items-center justify-between gap-2 text-sm">
                    <span class="text-muted">{{ membershipText }}</span>
                    <UButton
                        v-if="membershipResult.user"
                        :label="t('messages.access.users.view')"
                        variant="link"
                        size="sm"
                        @click="openDetail(membershipResult.user)"
                    />
                </div>
            </div>
        </template>

        <template #footer>
            <div class="flex w-full justify-between gap-2">
                <UButton
                    :label="t('messages.access.clear_filters')"
                    color="neutral"
                    variant="ghost"
                    :disabled="activeFilterCount === 0"
                    @click="clearFilters"
                />
                <UButton
                    :label="t('messages.access.done')"
                    @click="filtersOpen = false"
                />
            </div>
        </template>
    </USlideover>

    <UModal
        v-model:open="resetOpen"
        :title="t('messages.access.users.force_reset')"
        :description="t('messages.access.users.force_reset_description')"
    >
        <template #body>
            <div v-if="resetDone" class="flex flex-col gap-3">
                <UFormField :label="t('messages.access.users.temp_password')">
                    <div class="flex items-center gap-2">
                        <UInput
                            :model-value="tempPassword"
                            readonly
                            class="flex-1 font-mono"
                        />
                        <UButton
                            :icon="passwordCopied ? 'i-tabler-check' : 'i-tabler-copy'"
                            :color="passwordCopied ? 'success' : 'neutral'"
                            variant="outline"
                            :aria-label="passwordCopied
                                ? t('messages.access.users.password_copied')
                                : t('messages.access.users.copy_password')"
                            @click="copyTempPassword"
                        />
                    </div>
                </UFormField>

                <UAlert
                    :description="t('messages.access.users.force_reset_hint')"
                    color="warning"
                    variant="subtle"
                    icon="i-tabler-alert-triangle"
                />
            </div>
        </template>

        <template #footer>
            <div class="flex w-full justify-end gap-2">
                <template v-if="resetDone">
                    <UButton
                        :label="t('messages.access.done')"
                        @click="closeResetModal"
                    />
                </template>
                <template v-else>
                    <UButton
                        :label="t('messages.access.cancel')"
                        color="neutral"
                        variant="ghost"
                        @click="closeResetModal"
                    />
                    <UButton
                        :label="t('messages.access.users.force_reset')"
                        color="warning"
                        :loading="forcingReset"
                        @click="forceReset"
                    />
                </template>
            </div>
        </template>
    </UModal>

    <UModal
        v-model:open="impersonateOpen"
        :title="impersonateTarget ? t('messages.access.users.impersonate_confirm_title', { name: fullName(impersonateTarget) }) : ''"
        :description="t('messages.access.users.impersonate_confirm_description')"
    >
        <template #footer>
            <div class="flex w-full justify-end gap-2">
                <UButton
                    :label="t('messages.access.cancel')"
                    color="neutral"
                    variant="ghost"
                    @click="impersonateOpen = false"
                />
                <UButton
                    :label="t('messages.access.users.impersonate_confirm')"
                    icon="i-tabler-user-shield"
                    :loading="impersonating"
                    class="bg-violet-600 hover:bg-violet-600/75 active:bg-violet-600/75 disabled:bg-violet-600 aria-disabled:bg-violet-600 outline-violet-600/25
                        dark:bg-violet-400 dark:hover:bg-violet-400/75 dark:active:bg-violet-400/75 dark:disabled:bg-violet-400 dark:aria-disabled:bg-violet-400 dark:outline-violet-400/25"
                    @click="confirmImpersonate"
                />
            </div>
        </template>
    </UModal>

    <UModal
        v-model:open="deleteOpen"
        :title="deleteTarget ? t('messages.access.users.delete_title', { name: fullName(deleteTarget) }) : ''"
        :description="t('messages.access.users.delete_description')"
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
                    :label="t('messages.access.users.delete_account')"
                    color="error"
                    :loading="deleting"
                    @click="deleteAccount"
                />
            </div>
        </template>
    </UModal>

    <UModal
        v-model:open="bulkDeleteOpen"
        :title="t('messages.access.users.delete_selected_title', { count: selectedCount })"
        :description="t('messages.access.users.delete_selected_description')"
    >
        <template #footer>
            <div class="flex w-full justify-end gap-2">
                <UButton
                    :label="t('messages.access.cancel')"
                    color="neutral"
                    variant="ghost"
                    @click="bulkDeleteOpen = false"
                />
                <UButton
                    :label="t('messages.access.users.delete_selected')"
                    color="error"
                    :loading="bulkWorking"
                    @click="bulkDelete"
                />
            </div>
        </template>
    </UModal>

</template>
