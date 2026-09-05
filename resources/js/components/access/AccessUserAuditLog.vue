<script setup>
import AccessService from '@/services/AccessService.js'
import { useAccessUserDisplay } from '@/composables/useAccessUserDisplay.js'

/**
 * Read-only admin view of the access audit trail for one account;
 * Every admin mutation with the target as subject (profile facts, state changes, grant syncs), with its actor
 * and the changed before/after facts.
 */

const props = defineProps({
    userId: { type: [Number, String], required: true },
})

const { t, locale } = useI18n()
const { fullName } = useAccessUserDisplay()

const accessService = new AccessService()

const entries = ref([])
const hasMore = ref(false)
const page = ref(1)
const loading = ref(true)
const loadingMore = ref(false)
const loadError = ref('')

async function load (nextPage = 1) {
    const isFirstPage = nextPage === 1

    if (isFirstPage) {
        loading.value = true
    } else {
        loadingMore.value = true
    }
    loadError.value = ''

    try {
        const data = await accessService.fetchUserAuditLog(props.userId, nextPage)
        entries.value = isFirstPage ? data.entries : [...entries.value, ...data.entries]
        hasMore.value = data.has_more
        page.value = nextPage
    } catch (error) {
        loadError.value = error.detail ?? t('messages.access.users.audit.loading_failed')
    } finally {
        loading.value = false
        loadingMore.value = false
    }
}

onMounted(() => load())

function formatDate (iso) {
    return iso ? new Date(iso).toLocaleString(locale.value) : '-'
}

/* Violet is the impersonation accent (see the sidebar user zone and the impersonate actions). */
const IMPERSONATION_ACCENT = 'text-violet-600 dark:text-violet-400'

/**
 * Actions written by AccessControlService with a user subject;
 * Unknown values render their raw action string so new audit points degrade gracefully instead of disappearing.
 */
const actionMeta = {
    'user.created': { icon: 'i-tabler-user-plus' },
    'user.invited': { icon: 'i-tabler-mail-plus' },
    'user.invitation_resent': { icon: 'i-tabler-mail-forward' },
    'user.account_updated': { icon: 'i-tabler-pencil' },
    'user.roles_synced': { icon: 'i-tabler-shield-check' },
    'user.permissions_synced': { icon: 'i-tabler-key' },
    'user.password_changed': { icon: 'i-tabler-lock' },
    'user.password_reset_forced': { icon: 'i-tabler-lock-exclamation' },
    'user.two_factor_reset': { icon: 'i-tabler-shield-x' },
    'user.two_factor_enabled': { icon: 'i-tabler-shield-plus' },
    'user.two_factor_disabled': { icon: 'i-tabler-shield-off' },
    'user.identity_linked': { icon: 'i-tabler-link' },
    'user.identity_unlinked': { icon: 'i-tabler-unlink' },
    'user.self_provisioned': { icon: 'i-tabler-user-check' },
    'user.self_deleted': { icon: 'i-tabler-user-x' },
    'user.deleted': { icon: 'i-tabler-trash' },
    'user.impersonation_started': { icon: 'i-tabler-user-shield', accent: IMPERSONATION_ACCENT },
    'user.impersonation_ended': { icon: 'i-tabler-logout', accent: IMPERSONATION_ACCENT },
}

function actionLabel (entry) {
    return actionMeta[entry.action]
        ? t(`messages.access.users.audit.actions.${entry.action.replaceAll('.', '_')}`)
        : entry.action
}

function actionIcon (entry) {
    return actionMeta[entry.action]?.icon ?? 'i-tabler-history'
}

function actorLabel (entry) {
    /* An actor outside this admin's record scope: the server withholds the identity and sends only the
     * marker, so there is no name to format. Distinct from a null actor, which is genuinely gone. */
    if (entry.actor?.restricted) {
        return t('messages.access.users.audit.actor_restricted')
    }

    if (!entry.actor) {
        return t('messages.access.users.audit.actor_deleted')
    }

    return fullName(entry.actor)
}

function formatValue (value) {
    if (value === null || value === undefined || value === '') {
        return '—'
    }
    if (value === true) {
        return t('messages.access.users.yes')
    }
    if (value === false) {
        return t('messages.access.users.no')
    }

    return String(value)
}

/**
 * Only the facts that actually changed between the snapshots.
 * Values stay raw: the grid renders list-valued facts (roles, permissions) as chips and everything else as formatted text.
 */
function changesOf (entry) {
    const before = entry.before ?? {}
    const after = entry.after ?? {}

    return [...new Set([...Object.keys(before), ...Object.keys(after)])].filter(
        key => JSON.stringify(before[key] ?? null) !== JSON.stringify(after[key] ?? null)).
        map(key => ({ key, from: before[key], to: after[key] }))
}

function isListChange (change) {
    return Array.isArray(change.from) || Array.isArray(change.to)
}

/**
 * UTimeline items: the raw entry plus the keys the indicator and date render by default.
 * Accented actions (impersonation) carry their color onto the indicator and title.
 */
const timelineItems = computed(() => entries.value.map(entry => ({
    ...entry,
    icon: actionIcon(entry),
    date: formatDate(entry.created_at),
    ...(actionMeta[entry.action]?.accent
        ? { ui: { indicator: actionMeta[entry.action].accent, title: actionMeta[entry.action].accent } }
        : {}),
})))
</script>

<template>
    <UCard>
        <ListSkeleton v-if="loading" :rows="4"/>

        <UAlert
            v-else-if="loadError"
            :description="loadError"
            color="error"
            variant="subtle"
            icon="i-tabler-alert-triangle"
        />

        <template v-else>
            <p v-if="entries.length === 0" class="text-sm text-muted">
                {{ t('messages.access.users.audit.empty') }}
            </p>

            <UTimeline
                v-else
                :items="timelineItems"
                color="neutral"
                size="sm"
                class="w-full"
            >
                <template #title="{ item: entry }">
                    {{ actionLabel(entry) }}
                </template>

                <template #description="{ item: entry }">
                    <div class="min-w-0">
                        <p class="text-xs text-muted flex items-center gap-1.5 flex-wrap">
                            <span>
                                {{ t('messages.access.users.audit.by', { name: actorLabel(entry) }) }}
                                <template v-if="entry.ip_address"> · {{ entry.ip_address }}</template>
                            </span>
                            <!-- The actor's account was since retired; the name survives the tombstone. -->
                            <UBadge
                                v-if="entry.actor?.deleted"
                                :label="t('messages.access.users.status_deleted')"
                                color="neutral"
                                variant="outline"
                                size="sm"
                            />
                        </p>

                        <!-- Changed facts as a comparison grid: field, before, after. -->
                        <div
                            v-if="changesOf(entry).length"
                            class="mt-3 border border-default rounded-lg divide-y divide-default overflow-hidden"
                        >
                            <div
                                class="grid grid-cols-[minmax(7rem,1.2fr)_1fr_1.25rem_1fr] items-center gap-x-2 px-3 py-1.5 bg-elevated/50"
                            >
                                <span class="text-[11px] font-medium text-muted uppercase tracking-wide">
                                    {{ t('messages.access.users.audit.col_field') }}
                                </span>
                                <span class="text-[11px] font-medium text-muted uppercase tracking-wide text-center">
                                    {{ t('messages.access.users.audit.col_before') }}
                                </span>
                                <span/>
                                <span class="text-[11px] font-medium text-muted uppercase tracking-wide text-center">
                                    {{ t('messages.access.users.audit.col_after') }}
                                </span>
                            </div>
                            <div
                                v-for="change in changesOf(entry)"
                                :key="change.key"
                                class="grid grid-cols-[minmax(7rem,1.2fr)_1fr_1.25rem_1fr] items-center gap-x-2 px-3 py-2 text-xs"
                            >
                                <span class="text-muted font-mono truncate" :title="change.key">
                                    {{ change.key }}
                                </span>

                                <template v-if="isListChange(change)">
                                    <div class="flex flex-wrap items-center justify-center gap-1">
                                        <template v-if="(change.from ?? []).length">
                                            <UBadge
                                                v-for="name in change.from"
                                                :key="name"
                                                :label="name"
                                                color="neutral"
                                                variant="subtle"
                                                size="sm"
                                            />
                                        </template>
                                        <span v-else class="text-muted">—</span>
                                    </div>
                                    <UIcon
                                        name="i-tabler-arrow-right"
                                        class="size-3.5 text-muted justify-self-center"
                                    />
                                    <div class="flex flex-wrap items-center justify-center gap-1">
                                        <template v-if="(change.to ?? []).length">
                                            <UBadge
                                                v-for="name in change.to"
                                                :key="name"
                                                :label="name"
                                                variant="subtle"
                                                size="sm"
                                            />
                                        </template>
                                        <span v-else class="text-muted">—</span>
                                    </div>
                                </template>

                                <template v-else>
                                    <span class="text-muted text-center break-words">
                                        {{ formatValue(change.from) }}
                                    </span>
                                    <UIcon
                                        name="i-tabler-arrow-right"
                                        class="size-3.5 text-muted justify-self-center"
                                    />
                                    <span class="font-medium text-highlighted text-center break-words">
                                        {{ formatValue(change.to) }}
                                    </span>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </UTimeline>

            <div v-if="hasMore" class="mt-6 flex justify-center">
                <UButton
                    :label="t('messages.access.users.audit.load_more')"
                    color="neutral"
                    variant="subtle"
                    :loading="loadingMore"
                    @click="load(page + 1)"
                />
            </div>
        </template>
    </UCard>
</template>
