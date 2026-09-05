<script setup>
import AccessService from '@/services/AccessService.js'
import { useAccessUserDisplay } from '@/composables/useAccessUserDisplay.js'

/**
 * Read-only view of the role-surface audit feed: creations, renames, permission syncs and deletions,
 * with their actor and the changed before/after facts.
 *
 * Roles hard-delete, so this feed is the durable record - a deleted role's entries keep rendering from
 * their snapshots, flagged with a deleted badge instead of a link.
 * Without a roleId the whole surface streams (the History tab on the roles index); with one, only that
 * role's entries load and the redundant role chip hides (the role detail page).
 */

const props = defineProps({
    roleId: { type: [Number, String], default: null },
})

const { t } = useI18n()
const { fullName, formatDateTime } = useAccessUserDisplay()

const accessService = new AccessService()

const entries = ref([])
const hasMore = ref(false)
const page = ref(1)
const loading = ref(true)
const loadingMore = ref(false)
const loadError = ref('')

/* The feed names each entry's role; the per-role mount already has the role on screen. */
const showRole = computed(() => props.roleId === null)

async function load (nextPage = 1) {
    const isFirstPage = nextPage === 1

    if (isFirstPage) {
        loading.value = true
    } else {
        loadingMore.value = true
    }
    loadError.value = ''

    try {
        const data = await accessService.fetchRoleAuditLog(nextPage, props.roleId)
        entries.value = isFirstPage ? data.entries : [...entries.value, ...data.entries]
        hasMore.value = data.has_more
        page.value = nextPage
    } catch (error) {
        loadError.value = error.detail ?? t('messages.access.roles.audit.loading_failed')
    } finally {
        loading.value = false
        loadingMore.value = false
    }
}

onMounted(() => load())

function formatTimestamp (iso) {
    return formatDateTime(iso) ?? '-'
}

/**
 * Actions written by AccessControlService with a role subject;
 * Unknown values render their raw action string so new audit points degrade gracefully instead of disappearing.
 */
const actionMeta = {
    'role.created': { icon: 'i-tabler-shield-plus' },
    'role.renamed': { icon: 'i-tabler-pencil' },
    'role.permissions_synced': { icon: 'i-tabler-key' },
    'role.deleted': { icon: 'i-tabler-trash' },
}

function actionLabel (entry) {
    return actionMeta[entry.action]
        ? t(`messages.access.roles.audit.actions.${entry.action.replaceAll('.', '_')}`)
        : entry.action
}

function actionIcon (entry) {
    return actionMeta[entry.action]?.icon ?? 'i-tabler-history'
}

function actorLabel (entry) {
    /* An actor outside this admin's record scope: the server withholds the identity and sends only the
     * marker, so there is no name to format. Distinct from a null actor, which is genuinely gone. */
    if (entry.actor?.restricted) {
        return t('messages.access.roles.audit.actor_restricted')
    }

    if (!entry.actor) {
        return t('messages.access.roles.audit.actor_deleted')
    }

    return fullName(entry.actor)
}

/* A name survives deletion through the snapshots; the id is the last resort for rows that predate them. */
function roleLabel (entry) {
    return entry.role?.name ?? `#${entry.role?.id}`
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
 * Values stay raw: the grid renders list-valued facts (permissions) as chips and everything else as formatted text.
 */
function changesOf (entry) {
    const before = entry.before ?? {}
    const after = entry.after ?? {}

    return [...new Set([...Object.keys(before), ...Object.keys(after)])].filter(
        key => JSON.stringify(before[key] ?? null) !== JSON.stringify(after[key] ?? null)).map(key => ({
        key,
        from: before[key],
        to: after[key]
    }))
}

function isListChange (change) {
    return Array.isArray(change.from) || Array.isArray(change.to)
}

/**
 * UTimeline items: the raw entry plus the keys the indicator and date render by default.
 */
const timelineItems = computed(() => entries.value.map(entry => ({
    ...entry,
    icon: actionIcon(entry),
    date: formatTimestamp(entry.created_at),
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
                {{ t('messages.access.roles.audit.empty') }}
            </p>

            <UTimeline
                v-else
                :items="timelineItems"
                color="neutral"
                size="sm"
                class="w-full"
            >
                <template #title="{ item: entry }">
                    <span class="flex items-center gap-2 flex-wrap">
                        {{ actionLabel(entry) }}
                        <template v-if="showRole && entry.role">
                            <!-- A live role links to its page; a deleted one has no page left and says so. -->
                            <UButton
                                v-if="!entry.role.deleted"
                                :label="roleLabel(entry)"
                                :to="`/app/access/roles/${entry.role.id}`"
                                color="neutral"
                                variant="subtle"
                                size="xs"
                            />
                            <template v-else>
                                <UBadge
                                    :label="roleLabel(entry)"
                                    color="neutral"
                                    variant="subtle"
                                    size="sm"
                                />
                                <UBadge
                                    :label="t('messages.access.roles.audit.deleted_badge')"
                                    color="neutral"
                                    variant="outline"
                                    size="sm"
                                />
                            </template>
                        </template>
                    </span>
                </template>

                <template #description="{ item: entry }">
                    <div class="min-w-0">
                        <p class="text-xs text-muted flex items-center gap-1.5 flex-wrap">
                            <span>
                                {{ t('messages.access.roles.audit.by', { name: actorLabel(entry) }) }}
                                <template v-if="entry.ip_address"> · {{ entry.ip_address }}</template>
                            </span>
                            <!-- The actor's account was since retired; the name survives the tombstone. -->
                            <UBadge
                                v-if="entry.actor?.deleted"
                                :label="t('messages.access.roles.audit.deleted_badge')"
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
                                    {{ t('messages.access.roles.audit.col_field') }}
                                </span>
                                <span class="text-[11px] font-medium text-muted uppercase tracking-wide text-center">
                                    {{ t('messages.access.roles.audit.col_before') }}
                                </span>
                                <span/>
                                <span class="text-[11px] font-medium text-muted uppercase tracking-wide text-center">
                                    {{ t('messages.access.roles.audit.col_after') }}
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
                    :label="t('messages.access.roles.audit.load_more')"
                    color="neutral"
                    variant="subtle"
                    :loading="loadingMore"
                    @click="load(page + 1)"
                />
            </div>
        </template>
    </UCard>
</template>
