<script setup>
import { useRbac } from '@/plugins/rbac/composables/useRbac.js'
import { useAuthStore } from '@/stores/AuthStore.js'
import AccessService from '@/services/AccessService.js'
import { useAccessUserDisplay } from '@/composables/useAccessUserDisplay.js'
import { useCopyText } from '@/composables/useCopyText.js'

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
const { fullName, formatDateTime, formatDate, statusOf } = useAccessUserDisplay()

const accessService = new AccessService()

/* ------------------------------------------------------------------ *
 *  User detail + edit page
 *
 *  Left card: identity, editable profile, account facts and the
 *  dangerous actions. Right column: the access editor (transfer
 *  lists), security posture, live sessions and authentication log.
 * ------------------------------------------------------------------ */

const user = ref(null)
const loading = ref(true)
const failed = ref(false)

/* The target ceiling, mirrored from the server (`manageable` on the detail payload): an
 * account holding a privileged permission the signed-in admin lacks refuses every mutation
 * with a 422, so its editing controls render read-only instead. */
const targetManageable = computed(() => user.value?.manageable !== false)

const canManage = computed(() => can('users.manage') && targetManageable.value)

/* A tombstoned account is a permanent read-only record: the logs stay
 * readable, every mutation stays hidden - deletion is final, no restore. */
const isDeleted = computed(() => !!user.value?.deleted_at)

/* Deployment switches (from the admin's own user resource): a switched-off
 * feature's facts rows, account actions and sections disappear. */
const twoFactorAvailable = computed(() => authStore.user?.two_factor_available !== false)
const identityProvidersAvailable = computed(() => authStore.user?.identity_providers_available !== false)

/* The grant editor needs the roles/permissions dictionaries (roles.view); an out-of-reach
 * target falls through to the same read-only badge view deleted users get. */
const canPickGrants = computed(() => canManage.value && can('roles.view'))

const roles = ref([])
const permissions = ref([])

/* Editable state, re-synced from the server after every mutation. */
const firstName = ref('')
const lastName = ref('')
const selectedRoleIds = ref([])
const selectedPermissionIds = ref([])

function applyUser (freshUser) {
    firstName.value = freshUser.first_name ?? ''
    lastName.value = freshUser.last_name ?? ''
    applyGrants(freshUser)
}

/* The grant half on its own: the access editor must not reach into the profile tab's inputs, so a pending
 * rename survives cancelling (or failing) a grant edit. Owns advancing user.value - it is also called
 * standalone with the server's latest snapshot, and applyUser() delegates the assignment here. */
function applyGrants (freshUser) {
    user.value = freshUser
    selectedRoleIds.value = freshUser.roles.map(role => role.id)
    selectedPermissionIds.value = freshUser.direct_permissions.map(permission => permission.id)
}

/* Super-admin membership is only managed outside the API (seeder/console). */
const protectedRoleNames = computed(() => roles.value.filter(role => role.protected).map(role => role.name))

/* Grants above the signed-in admin's own ceiling: the server refuses adding them, so the picker locks them for adding (they stay removable).
 * The protected check runs first - the super-admin role carries no attached permissions and would otherwise read as grantable. */
const ungrantableRoleNames = computed(() => roles.value.filter(
    role => !role.protected && (role.permissions ?? []).some(permission => !can(permission.name))).map(role => role.name))

const ungrantablePermissionNames = computed(
    () => permissions.value.filter(permission => !can(permission.name)).map(permission => permission.name))

const inheritedPermissions = computed(() => {
    if (!user.value) {
        return []
    }

    const direct = new Set(user.value.direct_permissions.map(permission => permission.name))

    return (user.value.effective_permissions ?? []).filter(name => !direct.has(name))
})

/* Provider chips mirror the settings security tab (icons + display names). */
const providerMeta = {
    roeid: { icon: 'i-tabler-roeid' },
    id: { icon: 'i-tabler-id-badge-2' },
}

function providerName (provider) {
    return t(`messages.settings.security.provider_names.${provider}`)
}

const tabItems = computed(() => [
    { label: t('messages.access.users.tab_profile'), icon: 'i-tabler-user', slot: 'profile' },
    { label: t('messages.access.users.tab_access'), icon: 'i-tabler-shield-lock', slot: 'access' },
    { label: t('messages.access.users.tab_sessions'), icon: 'i-tabler-devices', slot: 'sessions' },
    { label: t('messages.access.users.tab_log'), icon: 'i-tabler-history', slot: 'log' },
    { label: t('messages.access.users.audit.tab'), icon: 'i-tabler-clipboard-list', slot: 'audit' },
])

async function loadUser () {
    loading.value = true
    try {
        const data = await accessService.fetchUser(route.params.id)
        applyUser(data.user)
        failed.value = false
    } catch {
        failed.value = true
    } finally {
        loading.value = false
    }
}

/* The transfer lists join the selected ids against these dictionaries, so
 * the access editor shows a skeleton until they are in - rendering earlier
 * would flash empty "assigned" panes for a user who does hold grants. */
const dictionariesLoading = ref(true)

async function loadDictionaries () {
    try {
        const [rolesData, permissionsData] = await Promise.all([
            accessService.fetchRoles(),
            accessService.fetchPermissions(),
        ])
        roles.value = rolesData.roles
        permissions.value = permissionsData.permissions
    } catch (error) {
        toast.add({
            title: t('messages.access.load_failed'),
            description: error.detail ?? t('messages.common.errors.network_description'),
            color: 'error',
        })
    } finally {
        dictionariesLoading.value = false
    }
}

onMounted(() => {
    if (can('users.view')) {
        loadUser()

        if (canPickGrants.value) {
            loadDictionaries()
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

/* ------------------------------------------------------------------ *
 *  Profile + account actions
 * ------------------------------------------------------------------ */

const savingProfile = ref(false)

/* Which account action is in flight, so each button spins on its own. */
const updatingAction = ref(null)

const profileDirty = computed(() => user.value !== null
    && (firstName.value !== (user.value.first_name ?? '') || lastName.value !== (user.value.last_name ?? '')))

async function saveProfile () {
    savingProfile.value = true
    try {
        const data = await accessService.updateUser(user.value.id, {
            first_name: firstName.value,
            last_name: lastName.value,
        })
        applyUser(data.user)
        toast.add({
            title: t('messages.access.users.account_updated'),
            color: 'success',
        })
    } catch (error) {
        mutationErrorToast(error)
    } finally {
        savingProfile.value = false
    }
}

async function patchAccount (payload, action) {
    updatingAction.value = action
    try {
        const data = await accessService.updateUser(user.value.id, payload)
        applyUser(data.user)
        toast.add({
            title: t('messages.access.users.account_updated'),
            color: 'success',
        })
    } catch (error) {
        mutationErrorToast(error)
    } finally {
        updatingAction.value = null
    }
}

/*
 * Forcing a reset makes the server generate a temporary password (admins
 * never choose one), destroy the user's sessions, and return the plaintext
 * exactly once for the admin to communicate out of band. Cancelling only
 * clears the flag, so it stays a plain PATCH.
 */
const resetOpen = ref(false)
const resetDone = ref(false)
const tempPassword = ref('')
const forcingReset = ref(false)

function openResetModal () {
    tempPassword.value = ''
    resetDone.value = false
    resetCopiedFlag()
    resetOpen.value = true
}

const { copied: passwordCopied, copy: copyText, reset: resetCopiedFlag } = useCopyText()

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
        const data = await accessService.forcePasswordReset(user.value.id)
        applyUser(data.user)
        tempPassword.value = data.temporary_password
        resetDone.value = true
        toast.add({
            title: t('messages.access.users.reset_forced'),
            color: 'success',
        })
    } catch (error) {
        mutationErrorToast(error)
    } finally {
        forcingReset.value = false
    }
}

function closeResetModal () {
    resetOpen.value = false
    tempPassword.value = ''
    resetDone.value = false
}

/* ------------------------------------------------------------------ *
 *  Impersonation (start; the exit lives in the global banner)
 *
 *  Gated on users.impersonate independently of users.manage, so a
 *  view+impersonate support role reaches it without account management.
 *  Targets above the impersonation tier hide the control (`impersonable`,
 *  computed server-side - the server stays authoritative).
 * ------------------------------------------------------------------ */

const impersonateOpen = ref(false)
const impersonating = ref(false)

const canImpersonate = computed(() =>
    can('users.impersonate')
    && authStore.user?.impersonation_available === true
    && !isDeleted.value
    && user.value?.id !== authStore.user?.id
    && user.value?.impersonable !== false)

async function startImpersonation () {
    impersonating.value = true
    try {
        await authStore.impersonate(user.value.id)
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

/*
 * Re-mailing a pending invitation revokes the previous first-sign-in link and sends a fresh one;
 * The server refuses accounts that were already entered. No modal: the action only re-sends mail.
 */
async function resendInvitation () {
    updatingAction.value = 'resend-invitation'
    try {
        const data = await accessService.resendInvitation(user.value.id)
        applyUser(data.user)
        toast.add({
            title: t('messages.access.users.invitation_resent'),
            color: 'success',
        })
    } catch (error) {
        mutationErrorToast(error)
    } finally {
        updatingAction.value = null
    }
}

/*
 * Resetting two-factor clears the target's authenticator and recovery codes (for a lost device);
 * The server audits the reset and mails the owner. No modal: the action is recoverable - the user simply enrolls again.
 */
async function resetTwoFactor () {
    updatingAction.value = 'two-factor-reset'
    try {
        const data = await accessService.resetUserTwoFactor(user.value.id)
        applyUser(data.user)
        toast.add({
            title: t('messages.access.users.two_factor_reset_done'),
            color: 'success',
        })
    } catch (error) {
        mutationErrorToast(error)
    } finally {
        updatingAction.value = null
    }
}

/*
 * Banning is a PATCH with an optional recorded reason; lifting the ban
 * clears both the timestamp and the reason server-side.
 */
const banOpen = ref(false)
const banReason = ref('')

async function banAccount () {
    await patchAccount({ banned: true, ban_reason: banReason.value.trim() || null }, 'ban')
    banOpen.value = false
    banReason.value = ''
}

const deleteOpen = ref(false)
const deleting = ref(false)

async function deleteAccount () {
    deleting.value = true
    try {
        await accessService.deleteUser(user.value.id)
        toast.add({
            title: t('messages.access.users.deleted'),
            color: 'success',
        })
        await router.push('/app/access/users')
    } catch (error) {
        deleteOpen.value = false
        mutationErrorToast(error)
    } finally {
        deleting.value = false
    }
}

/* ------------------------------------------------------------------ *
 *  Access editor (roles + direct permissions transfer lists)
 * ------------------------------------------------------------------ */

const savingAccess = ref(false)

function sameSet (a, b) {
    return a.length === b.length && [...a].sort().join(',') === [...b].sort().join(',')
}

const accessDirty = computed(() => user.value !== null && (
    !sameSet(selectedRoleIds.value, user.value.roles.map(role => role.id))
    || !sameSet(selectedPermissionIds.value, user.value.direct_permissions.map(permission => permission.id))
))

function resetAccess () {
    applyGrants(user.value)
}

async function saveAccess () {
    savingAccess.value = true

    /* Held outside the try: the two halves are separate requests, so the catch needs whatever the
     * server last returned - a roles sync that landed before the permissions sync was refused is
     * committed state, not something to roll back on screen. */
    let data = null

    try {
        /* Only the halves that changed are sent - an unchanged sync would
         * be a no-op request the audit trail rightly ignores. */
        if (!sameSet(selectedRoleIds.value, user.value.roles.map(role => role.id))) {
            data = await accessService.syncUserRoles(user.value.id, selectedRoleIds.value)
        }
        if (!sameSet(selectedPermissionIds.value, user.value.direct_permissions.map(permission => permission.id))) {
            data = await accessService.syncUserPermissions(user.value.id, selectedPermissionIds.value)
        }
        if (data) {
            applyGrants(data.user)
        }

        toast.add({
            title: t('messages.access.users.saved'),
            color: 'success',
        })

        // Editing yourself changes what the shell may show - refresh grants.
        if (user.value.id === authStore.user?.id) {
            await authStore.fetchUser()
        }
    } catch (error) {
        /* The refused sync rolled back server-side, so the editor must snap back to what the account
         * actually holds - leaving the attempted edit on screen reads as a save that worked. */
        applyGrants(data?.user ?? user.value)
        mutationErrorToast(error)
    } finally {
        savingAccess.value = false
    }
}
</script>

<template>
    <UDashboardPanel id="access-user-detail">
        <template #header>
            <UDashboardNavbar :title="user ? fullName(user) : t('messages.access.users.title')">
                <template #leading>
                    <UDashboardSidebarCollapse/>
                    <UButton
                        icon="i-tabler-arrow-left"
                        color="neutral"
                        variant="ghost"
                        to="/app/access/users"
                        :aria-label="t('messages.access.users.back')"
                    />
                </template>
            </UDashboardNavbar>
        </template>

        <template #body>
            <AccessDenied
                v-if="!can('users.view')"
                variant="page"
            />

            <UAlert
                v-else-if="failed"
                :title="t('messages.access.users.not_found')"
                :description="t('messages.access.users.not_found_description')"
                color="warning"
                variant="subtle"
                icon="i-tabler-user-question"
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
                    <USkeleton v-for="n in 5" :key="n" class="h-9 w-32"/>
                </div>

                <!-- Mirrors the profile tab: inputs, fact lines, action cards. -->
                <div class="flex flex-col divide-y divide-default">
                    <div v-for="section in 3" :key="section" class="grid gap-x-8 gap-y-4 lg:grid-cols-[16rem_1fr] py-6">
                        <div class="flex flex-col gap-2">
                            <USkeleton class="h-5 w-32"/>
                            <USkeleton class="h-4 w-48"/>
                        </div>
                        <div class="min-w-0">
                            <div v-if="section === 1" class="grid gap-4 sm:grid-cols-2">
                                <USkeleton class="h-8"/>
                                <USkeleton class="h-8"/>
                            </div>
                            <div v-else-if="section === 2" class="flex flex-col gap-3">
                                <USkeleton class="h-4 w-full"/>
                                <USkeleton class="h-4 w-2/3"/>
                            </div>
                            <div v-else class="flex flex-col gap-4">
                                <USkeleton v-for="card in 2" :key="card" class="h-16 w-full"/>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else-if="user" class="flex flex-col gap-4">
                <UAlert
                    v-if="isDeleted"
                    :title="t('messages.access.users.deleted_banner_title')"
                    :description="t('messages.access.users.deleted_banner_description', { date: formatDate(user.deleted_at) })"
                    color="neutral"
                    variant="subtle"
                    icon="i-tabler-user-x"
                />

                <!-- Profile banner: a static grid pattern (cheap CSS, theme-aware), identity below. -->
                <div class="border border-default rounded-lg overflow-hidden">
                    <GridBanner/>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 sm:px-6 pb-4">
                        <UAvatar
                            :alt="fullName(user)"
                            size="3xl"
                            class="-mt-10 ring-4 ring-(--ui-bg)"
                        />
                        <div class="flex-1 min-w-48">
                            <h2 class="text-xl font-semibold text-highlighted">{{ fullName(user) }}</h2>
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-1 text-sm text-muted">
                                <!-- A tombstoned address is a meaningless uuid - omit it. -->
                                <span v-if="!isDeleted" class="flex items-center gap-1.5">
                                    <UIcon name="i-tabler-mail" class="size-4"/>
                                    {{ user.email }}
                                </span>
                                <span class="flex items-center gap-1.5">
                                    <UIcon name="i-tabler-calendar" class="size-4"/>
                                    {{ formatDate(user.created_at) ?? '—' }}
                                </span>
                                <span class="flex items-center gap-1.5">
                                    <UIcon name="i-tabler-shield-check" class="size-4"/>
                                    {{ user.roles.length }} {{ t('messages.access.users.col_roles').toLowerCase() }}
                                </span>
                                <span class="flex items-center gap-1.5">
                                    <UIcon name="i-tabler-key" class="size-4"/>
                                    {{
                                        t('messages.access.users.direct_count',
                                            { count: user.direct_permissions.length })
                                    }}
                                </span>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <UBadge
                                :label="statusOf(user).label"
                                :color="statusOf(user).color"
                                variant="subtle"
                            />
                            <UBadge
                                v-if="user.require_password_reset"
                                :label="t('messages.access.users.reset_required')"
                                color="warning"
                                variant="outline"
                            />
                            <UBadge
                                v-if="!targetManageable"
                                :label="t('messages.access.users.above_tier')"
                                color="warning"
                                variant="subtle"
                            />
                        </div>
                    </div>
                </div>

                <UTabs :items="tabItems" variant="link">
                    <template #profile>
                        <div class="flex flex-col divide-y divide-default">
                            <AccessSection
                                :title="t('messages.access.users.profile')"
                                :description="t('messages.access.users.profile_description')"
                            >
                                <div class="flex flex-col gap-4">
                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <UFormField :label="t('messages.access.users.first_name')">
                                            <UInput v-model="firstName" class="w-full"
                                                    :disabled="!canManage || isDeleted"/>
                                        </UFormField>
                                        <UFormField :label="t('messages.access.users.last_name')">
                                            <UInput v-model="lastName" class="w-full"
                                                    :disabled="!canManage || isDeleted"/>
                                        </UFormField>
                                    </div>
                                    <div v-if="canManage && !isDeleted" class="flex justify-end">
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
                                :title="t('messages.access.users.details')"
                                :description="t('messages.access.users.details_description')"
                            >
                                <dl class="flex flex-col gap-3 text-sm">
                                    <div class="flex items-baseline justify-between gap-4">
                                        <dt class="text-muted">{{ t('messages.access.users.email_verified') }}</dt>
                                        <dd class="text-highlighted text-right">
                                            {{
                                                user.email_verified
                                                    ? t('messages.access.users.verified')
                                                    : t('messages.access.users.unverified')
                                            }}
                                        </dd>
                                    </div>
                                    <div class="flex items-baseline justify-between gap-4">
                                        <dt class="text-muted">{{ t('messages.access.users.member_since') }}</dt>
                                        <dd class="text-highlighted text-right">{{
                                                formatDate(user.created_at) ?? '—'
                                            }}
                                        </dd>
                                    </div>
                                </dl>
                            </AccessSection>

                            <AccessSection
                                :title="t('messages.access.users.tab_security')"
                                :description="t('messages.access.users.security_description')"
                            >
                                <dl class="flex flex-col gap-3 text-sm">
                                    <div v-if="twoFactorAvailable" class="flex items-baseline justify-between gap-4">
                                        <dt class="text-muted">{{ t('messages.access.users.two_factor') }}</dt>
                                        <dd class="text-highlighted text-right">
                                            {{
                                                user.two_factor_enabled
                                                    ? t('messages.access.users.enabled')
                                                    : t('messages.access.users.disabled')
                                            }}
                                        </dd>
                                    </div>
                                    <div v-if="twoFactorAvailable" class="flex items-baseline justify-between gap-4">
                                        <dt class="text-muted">{{ t('messages.access.users.two_factor_mandated') }}</dt>
                                        <dd class="text-highlighted text-right">
                                            {{
                                                user.two_factor_required
                                                    ? t('messages.access.users.yes')
                                                    : t('messages.access.users.no')
                                            }}
                                        </dd>
                                    </div>
                                    <div class="flex items-baseline justify-between gap-4">
                                        <dt class="text-muted">{{ t('messages.access.users.password_changed') }}</dt>
                                        <dd class="text-highlighted text-right">
                                            {{ formatDateTime(user.password_changed_at) ?? '—' }}
                                        </dd>
                                    </div>
                                    <div class="flex items-baseline justify-between gap-4">
                                        <dt class="text-muted">{{ t('messages.access.users.reset_required') }}</dt>
                                        <dd class="text-highlighted text-right">
                                            {{
                                                user.require_password_reset
                                                    ? t('messages.access.users.yes')
                                                    : t('messages.access.users.no')
                                            }}
                                        </dd>
                                    </div>
                                    <div class="flex items-baseline justify-between gap-4">
                                        <dt class="text-muted">{{ t('messages.access.users.col_last_login') }}</dt>
                                        <dd class="text-highlighted text-right">
                                            {{ formatDateTime(user.last_login_at) ?? t('messages.access.users.never') }}
                                        </dd>
                                    </div>
                                    <div class="flex items-baseline justify-between gap-4">
                                        <dt class="text-muted">{{ t('messages.access.users.last_login_ip') }}</dt>
                                        <dd class="text-highlighted text-right">{{ user.last_login_ip ?? '—' }}</dd>
                                    </div>
                                    <div class="flex items-baseline justify-between gap-4">
                                        <dt class="text-muted">{{ t('messages.access.users.inactivity_prenotice') }}</dt>
                                        <dd class="text-highlighted text-right">
                                            {{ user.inactivity_notice_sent_at ? formatDateTime(user.inactivity_notice_sent_at) : '—' }}
                                        </dd>
                                    </div>
                                    <div v-if="user.banned_at" class="flex items-baseline justify-between gap-4">
                                        <dt class="text-muted">{{ t('messages.access.users.banned_at') }}</dt>
                                        <dd class="text-highlighted text-right">{{
                                                formatDateTime(user.banned_at)
                                            }}
                                        </dd>
                                    </div>
                                    <div v-if="user.ban_reason" class="flex items-baseline justify-between gap-4">
                                        <dt class="text-muted">{{ t('messages.access.users.ban_reason') }}</dt>
                                        <dd class="text-highlighted text-right">{{ user.ban_reason }}</dd>
                                    </div>
                                </dl>
                            </AccessSection>

                            <AccessSection
                                v-if="identityProvidersAvailable"
                                :title="t('messages.access.users.identities_title')"
                                :description="t('messages.access.users.identities_description')"
                            >
                                <div class="flex flex-wrap items-center gap-2">
                                    <template v-if="user.identities?.length">
                                        <div
                                            v-for="identity in user.identities"
                                            :key="identity.provider"
                                            class="flex items-center gap-2 border border-default rounded-lg px-3 py-1.5 text-sm"
                                            :title="identity.linked_at
                                                ? t('messages.access.users.identity_linked_at', { date: formatDate(identity.linked_at) })
                                                : undefined"
                                        >
                                            <UIcon
                                                :name="providerMeta[identity.provider]?.icon ?? 'i-tabler-id-badge-2'"
                                                class="size-4"
                                            />
                                            <span class="font-medium text-highlighted">{{
                                                    providerName(identity.provider)
                                                }}</span>
                                        </div>
                                    </template>
                                    <span v-else class="text-sm text-muted">{{
                                            t('messages.access.users.identities_empty')
                                        }}</span>
                                </div>
                            </AccessSection>

                            <AccessSection
                                v-if="canImpersonate"
                                :title="t('messages.access.users.impersonate')"
                                :description="t('messages.access.users.impersonate_description')"
                            >
                                <div class="flex flex-wrap items-center gap-4 border border-default rounded-lg p-4">
                                    <div class="flex-1 min-w-48">
                                        <p class="text-sm font-medium text-highlighted">
                                            {{ t('messages.access.users.impersonate') }}
                                        </p>
                                        <p class="text-sm text-muted mt-1">
                                            {{ t('messages.access.users.impersonate_description') }}
                                        </p>
                                    </div>
                                    <UButton
                                        :label="t('messages.access.users.impersonate')"
                                        icon="i-tabler-user-shield"
                                        color="neutral"
                                        variant="outline"
                                        size="sm"
                                        class="text-violet-600 dark:text-violet-400"
                                        @click="impersonateOpen = true"
                                    />
                                </div>
                            </AccessSection>

                            <AccessSection
                                v-if="canManage && !isDeleted"
                                :title="t('messages.access.users.danger_title')"
                                :description="t('messages.access.users.danger_description')"
                            >
                                <div class="flex flex-col gap-4">
                                    <div
                                        v-if="!user.email_verified"
                                        class="flex flex-wrap items-center gap-4 border border-default rounded-lg p-4"
                                    >
                                        <div class="flex-1 min-w-48">
                                            <p class="text-sm font-medium text-highlighted">
                                                {{ t('messages.access.users.mark_verified') }}
                                            </p>
                                            <p class="text-sm text-muted mt-1">
                                                {{ t('messages.access.users.mark_verified_description') }}
                                            </p>
                                        </div>
                                        <UButton
                                            :label="t('messages.access.users.mark_verified')"
                                            icon="i-tabler-rosette-discount-check"
                                            color="neutral"
                                            variant="outline"
                                            size="sm"
                                            :loading="updatingAction === 'verify'"
                                            :disabled="updatingAction !== null && updatingAction !== 'verify'"
                                            @click="patchAccount({ email_verified: true }, 'verify')"
                                        />
                                    </div>

                                    <div class="flex flex-wrap items-center gap-4 border border-default rounded-lg p-4">
                                        <div class="flex-1 min-w-48">
                                            <p class="text-sm font-medium text-highlighted">
                                                {{
                                                    user.is_active
                                                        ? t('messages.access.users.deactivate')
                                                        : t('messages.access.users.activate')
                                                }}
                                            </p>
                                            <p class="text-sm text-muted mt-1">
                                                {{
                                                    user.is_active
                                                        ? t('messages.access.users.deactivate_description')
                                                        : t('messages.access.users.activate_description')
                                                }}
                                            </p>
                                        </div>
                                        <UButton
                                            :label="user.is_active
                                                ? t('messages.access.users.deactivate')
                                                : t('messages.access.users.activate')"
                                            :icon="user.is_active ? 'i-tabler-user-off' : 'i-tabler-user-check'"
                                            :color="user.is_active ? 'warning' : 'success'"
                                            variant="outline"
                                            size="sm"
                                            :loading="updatingAction === 'active'"
                                            :disabled="updatingAction !== null && updatingAction !== 'active'"
                                            @click="patchAccount({ is_active: !user.is_active }, 'active')"
                                        />
                                    </div>

                                    <div class="flex flex-wrap items-center gap-4 border border-default rounded-lg p-4">
                                        <div class="flex-1 min-w-48">
                                            <p class="text-sm font-medium text-highlighted">
                                                {{ t('messages.access.users.force_reset') }}
                                            </p>
                                            <p class="text-sm text-muted mt-1">
                                                {{ t('messages.access.users.force_reset_description') }}
                                            </p>
                                        </div>
                                        <UButton
                                            :label="t('messages.access.users.force_reset')"
                                            icon="i-tabler-key"
                                            color="warning"
                                            variant="outline"
                                            size="sm"
                                            :disabled="updatingAction !== null"
                                            @click="openResetModal()"
                                        />
                                    </div>

                                    <div
                                        v-if="user.invitable"
                                        class="flex flex-wrap items-center gap-4 border border-default rounded-lg p-4"
                                    >
                                        <div class="flex-1 min-w-48">
                                            <p class="text-sm font-medium text-highlighted">
                                                {{ t('messages.access.users.resend_invitation') }}
                                            </p>
                                            <p class="text-sm text-muted mt-1">
                                                {{ t('messages.access.users.resend_invitation_description') }}
                                            </p>
                                        </div>
                                        <UButton
                                            :label="t('messages.access.users.resend_invitation')"
                                            icon="i-tabler-mail-forward"
                                            color="neutral"
                                            variant="outline"
                                            size="sm"
                                            :loading="updatingAction === 'resend-invitation'"
                                            :disabled="updatingAction !== null && updatingAction !== 'resend-invitation'"
                                            @click="resendInvitation"
                                        />
                                    </div>

                                    <div
                                        v-if="twoFactorAvailable"
                                        class="flex flex-wrap items-center gap-4 border border-default rounded-lg p-4"
                                    >
                                        <div class="flex-1 min-w-48">
                                            <p class="text-sm font-medium text-highlighted">
                                                {{
                                                    user.two_factor_required
                                                        ? t('messages.access.users.lift_two_factor')
                                                        : t('messages.access.users.require_two_factor')
                                                }}
                                            </p>
                                            <p class="text-sm text-muted mt-1">
                                                {{
                                                    user.two_factor_required
                                                        ? t('messages.access.users.lift_two_factor_description')
                                                        : (user.two_factor_enabled
                                                            ? t('messages.access.users.require_two_factor_enrolled_description')
                                                            : t('messages.access.users.require_two_factor_description'))
                                                }}
                                            </p>
                                        </div>
                                        <UButton
                                            :label="user.two_factor_required
                                                ? t('messages.access.users.lift_two_factor')
                                                : t('messages.access.users.require_two_factor')"
                                            :icon="user.two_factor_required ? 'i-tabler-shield-off' : 'i-tabler-shield-lock'"
                                            :color="user.two_factor_required ? 'neutral' : 'warning'"
                                            variant="outline"
                                            size="sm"
                                            :loading="updatingAction === 'two-factor-required'"
                                            :disabled="updatingAction !== null && updatingAction !== 'two-factor-required'"
                                            @click="patchAccount({ two_factor_required: !user.two_factor_required }, 'two-factor-required')"
                                        />
                                    </div>

                                    <div
                                        v-if="twoFactorAvailable && user.two_factor_enabled"
                                        class="flex flex-wrap items-center gap-4 border border-default rounded-lg p-4"
                                    >
                                        <div class="flex-1 min-w-48">
                                            <p class="text-sm font-medium text-highlighted">
                                                {{ t('messages.access.users.reset_two_factor') }}
                                            </p>
                                            <p class="text-sm text-muted mt-1">
                                                {{ t('messages.access.users.reset_two_factor_description') }}
                                            </p>
                                        </div>
                                        <UButton
                                            :label="t('messages.access.users.reset_two_factor')"
                                            icon="i-tabler-shield-x"
                                            color="warning"
                                            variant="outline"
                                            size="sm"
                                            :loading="updatingAction === 'two-factor-reset'"
                                            :disabled="updatingAction !== null && updatingAction !== 'two-factor-reset'"
                                            @click="resetTwoFactor()"
                                        />
                                    </div>

                                    <div class="flex flex-wrap items-center gap-4 border border-default rounded-lg p-4">
                                        <div class="flex-1 min-w-48">
                                            <p class="text-sm font-medium text-highlighted">
                                                {{
                                                    user.banned_at
                                                        ? t('messages.access.users.unban')
                                                        : t('messages.access.users.ban')
                                                }}
                                            </p>
                                            <p class="text-sm text-muted mt-1">
                                                {{
                                                    user.banned_at
                                                        ? t('messages.access.users.unban_description')
                                                        : t('messages.access.users.ban_description')
                                                }}
                                            </p>
                                        </div>
                                        <UButton
                                            :label="user.banned_at
                                                ? t('messages.access.users.unban')
                                                : t('messages.access.users.ban')"
                                            :icon="user.banned_at ? 'i-tabler-lock-open' : 'i-tabler-ban'"
                                            :color="user.banned_at ? 'success' : 'error'"
                                            variant="outline"
                                            size="sm"
                                            :loading="updatingAction === 'ban'"
                                            :disabled="updatingAction !== null && updatingAction !== 'ban'"
                                            @click="user.banned_at
                                                ? patchAccount({ banned: false }, 'ban')
                                                : (banOpen = true)"
                                        />
                                    </div>

                                    <div class="flex flex-wrap items-center gap-4 border border-default rounded-lg p-4">
                                        <div class="flex-1 min-w-48">
                                            <p class="text-sm font-medium text-highlighted">
                                                {{ t('messages.access.users.delete_account') }}
                                            </p>
                                            <p class="text-sm text-muted mt-1">
                                                {{ t('messages.access.users.delete_description') }}
                                            </p>
                                        </div>
                                        <UButton
                                            :label="t('messages.access.users.delete_account')"
                                            icon="i-tabler-trash"
                                            color="error"
                                            variant="outline"
                                            size="sm"
                                            @click="deleteOpen = true"
                                        />
                                    </div>
                                </div>
                            </AccessSection>
                        </div>
                    </template>

                    <template #access>
                        <!-- Deleted users fall through to the read-only badge view below. -->
                        <div v-if="canPickGrants && !isDeleted && dictionariesLoading" class="py-6">
                            <ListSkeleton :rows="4"/>
                        </div>

                        <div v-else-if="canPickGrants && !isDeleted" class="flex flex-col divide-y divide-default">
                            <AccessSection
                                :title="t('messages.access.users.roles')"
                                :description="t('messages.access.users.roles_description')"
                            >
                                <AccessTransferList
                                    v-model="selectedRoleIds"
                                    :items="roles"
                                    :available-label="t('messages.access.users.available_roles')"
                                    :assigned-label="t('messages.access.users.assigned_roles')"
                                    :locked-names="protectedRoleNames"
                                    :ungrantable-names="ungrantableRoleNames"
                                />
                            </AccessSection>

                            <AccessSection
                                :title="t('messages.access.users.direct_permissions')"
                                :description="t('messages.access.users.permissions_description')"
                            >
                                <AccessTransferList
                                    v-model="selectedPermissionIds"
                                    :items="permissions"
                                    :available-label="t('messages.access.users.available_permissions')"
                                    :assigned-label="t('messages.access.users.assigned_permissions')"
                                    :inherited-names="inheritedPermissions"
                                    :ungrantable-names="ungrantablePermissionNames"
                                />
                                <p class="text-xs text-muted mt-2">
                                    {{ t('messages.access.users.transfer_inherited_hint') }}
                                </p>
                            </AccessSection>

                            <div class="flex items-center justify-end gap-2 py-6">
                                <UButton
                                    :label="t('messages.access.users.reset_changes')"
                                    color="neutral"
                                    variant="ghost"
                                    :disabled="!accessDirty || savingAccess"
                                    @click="resetAccess"
                                />
                                <UButton
                                    :label="t('messages.access.users.save_access')"
                                    :disabled="!accessDirty"
                                    :loading="savingAccess"
                                    @click="saveAccess"
                                />
                            </div>
                        </div>

                        <!-- Read-only fallback: grants shown from the user payload, no dictionaries needed. -->
                        <div v-else class="flex flex-col divide-y divide-default">
                            <AccessSection
                                :title="t('messages.access.users.roles')"
                                :description="t('messages.access.users.roles_description')"
                            >
                                <div class="flex flex-wrap gap-1">
                                    <template v-if="user.roles.length">
                                        <UBadge
                                            v-for="role in user.roles"
                                            :key="role.id"
                                            :label="role.name"
                                            variant="subtle"
                                        />
                                    </template>
                                    <span v-else class="text-sm text-muted">—</span>
                                </div>
                            </AccessSection>

                            <AccessSection
                                :title="t('messages.access.users.direct_permissions')"
                                :description="t('messages.access.users.permissions_description')"
                            >
                                <div class="flex flex-wrap gap-1">
                                    <template v-if="user.direct_permissions.length">
                                        <UBadge
                                            v-for="permission in user.direct_permissions"
                                            :key="permission.id"
                                            :label="permission.name"
                                            color="neutral"
                                            variant="subtle"
                                        />
                                    </template>
                                    <span v-else class="text-sm text-muted">—</span>
                                </div>
                            </AccessSection>
                        </div>
                    </template>

                    <template #sessions>
                        <AccessSection
                            :title="t('messages.access.users.tab_sessions')"
                            :description="t('messages.access.users.sessions_description')"
                        >
                            <AccessUserSessions :user-id="user.id"/>
                        </AccessSection>
                    </template>

                    <template #log>
                        <AccessSection
                            :title="t('messages.access.users.tab_log')"
                            :description="t('messages.access.users.log_description')"
                        >
                            <AccessUserAuthenticationLog :user-id="user.id"/>
                        </AccessSection>
                    </template>

                    <template #audit>
                        <AccessSection
                            :title="t('messages.access.users.audit.tab')"
                            :description="t('messages.access.users.audit.description')"
                        >
                            <AccessUserAuditLog :user-id="user.id"/>
                        </AccessSection>
                    </template>
                </UTabs>
            </div>

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
                :title="user ? t('messages.access.users.impersonate_confirm_title', { name: fullName(user) }) : ''"
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
                            @click="startImpersonation"
                        />
                    </div>
                </template>
            </UModal>

            <UModal
                v-model:open="banOpen"
                :title="t('messages.access.users.ban')"
                :description="t('messages.access.users.ban_description')"
            >
                <template #body>
                    <UFormField :label="t('messages.access.users.ban_reason')">
                        <UInput
                            v-model="banReason"
                            :placeholder="t('messages.access.users.ban_reason_placeholder')"
                            class="w-full"
                        />
                    </UFormField>
                </template>

                <template #footer>
                    <div class="flex w-full justify-end gap-2">
                        <UButton
                            :label="t('messages.access.cancel')"
                            color="neutral"
                            variant="ghost"
                            @click="banOpen = false"
                        />
                        <UButton
                            :label="t('messages.access.users.ban')"
                            color="error"
                            :loading="updatingAction === 'ban'"
                            @click="banAccount"
                        />
                    </div>
                </template>
            </UModal>

            <UModal
                v-model:open="deleteOpen"
                :title="user ? t('messages.access.users.delete_title', { name: fullName(user) }) : ''"
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
        </template>
    </UDashboardPanel>
</template>
