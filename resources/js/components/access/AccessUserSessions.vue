<script setup>
import AccessService from '@/services/AccessService.js'

/**
 * Read-only admin view of a user's live sessions - the counterpart of the
 * Settings sessions tab, without the revoke actions.
 */

const props = defineProps({
    userId: { type: [Number, String], required: true },
})

const { t, locale } = useI18n()

const accessService = new AccessService()

const sessions = ref([])
const total = ref(0)
const loading = ref(true)
const loadError = ref('')

async function load () {
    loading.value = true
    loadError.value = ''
    try {
        const data = await accessService.fetchUserSessions(props.userId)
        sessions.value = data.sessions
        total.value = data.total
    } catch (error) {
        loadError.value = error.detail ?? t('messages.settings.sessions.loading_failed')
    } finally {
        loading.value = false
    }
}

onMounted(load)

function deviceIcon (session) {
    return /Mobile|iPhone|Android/i.test(session.user_agent)
        ? 'i-tabler-device-mobile'
        : 'i-tabler-device-desktop'
}

function formatLastActivity (iso) {
    return new Date(iso).toLocaleString(locale.value)
}
</script>

<template>
    <UCard>
        <ListSkeleton v-if="loading"/>

        <UAlert
            v-else-if="loadError"
            :description="loadError"
            color="error"
            variant="subtle"
            icon="i-tabler-alert-triangle"
        />

        <template v-else>
            <p v-if="sessions.length === 0" class="text-sm text-muted">
                {{ t('messages.access.users.no_sessions') }}
            </p>

            <div v-else class="flex flex-col divide-y divide-default">
                <div
                    v-for="session in sessions"
                    :key="session.id"
                    class="flex items-center gap-4 py-4 first:pt-0 last:pb-0"
                >
                    <UIcon :name="deviceIcon(session)" class="size-6 shrink-0 text-muted"/>

                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium text-highlighted truncate">
                                {{ session.device_name }}
                            </span>
                            <UBadge
                                v-if="session.is_current"
                                :label="t('messages.settings.sessions.current')"
                                color="primary"
                                variant="subtle"
                                size="sm"
                            />
                        </div>
                        <p class="text-xs text-muted truncate" :title="session.user_agent || undefined">
                            {{ session.ip_address }} · {{ t('messages.settings.sessions.last_active') }}
                            {{ formatLastActivity(session.last_activity_at) }}
                        </p>
                    </div>
                </div>
            </div>

            <p v-if="total > sessions.length" class="text-xs text-muted mt-4">
                {{ t('messages.settings.sessions.showing_capped', { shown: sessions.length, total }) }}
            </p>
        </template>
    </UCard>
</template>
