<script setup>
import { useAuthStore } from '@/stores/AuthStore.js'
import SettingsService from '@/services/SettingsService.js'

const { t, locale } = useI18n()
const toast = useAppToast()
const authStore = useAuthStore()

const settingsService = new SettingsService()

const sessions = ref([])
const total = ref(0)
const loading = ref(true)
const loadError = ref('')

async function load () {
    loading.value = true
    loadError.value = ''
    try {
        const data = await settingsService.fetchSessions()
        sessions.value = data.sessions
        total.value = data.total
    } catch (error) {
        loadError.value = error.detail ?? t('messages.settings.sessions.loading_failed')
    } finally {
        loading.value = false
    }
}

onMounted(load)

const hasPassword = computed(() => authStore.user?.has_password !== false)
const hasOtherSessions = computed(() => sessions.value.some(session => !session.is_current))

function deviceIcon (session) {
    return /Mobile|iPhone|Android/i.test(session.user_agent)
        ? 'i-tabler-device-mobile'
        : 'i-tabler-device-desktop'
}

function formatLastActivity (iso) {
    return new Date(iso).toLocaleString(locale.value)
}

const expandedIds = ref(new Set())

function isExpanded (session) {
    return expandedIds.value.has(session.id)
}

function toggleDetails (session) {
    const next = new Set(expandedIds.value)

    if (next.has(session.id)) {
        next.delete(session.id)
    } else {
        next.add(session.id)
    }

    expandedIds.value = next
}

async function revoke (session) {
    try {
        await settingsService.revokeSession(session.id)
        toast.add({
            title: t('messages.settings.sessions.revoked_title'),
            color: 'success',
        })
        await load()
    } catch (error) {
        toast.add({
            title: t('messages.settings.sessions.failed'),
            description: error.detail ?? t('messages.common.errors.network_description'),
            color: 'error',
        })
    }
}

const revokeOthersOpen = ref(false)
const password = ref('')
const revokingOthers = ref(false)

function submitRevokeOthers () {
    if ((hasPassword.value && password.value === '') || revokingOthers.value) {
        return
    }

    revokeOthers()
}

async function revokeOthers () {
    revokingOthers.value = true
    try {
        await settingsService.revokeOtherSessions(hasPassword.value ? { password: password.value } : {})
        revokeOthersOpen.value = false
        password.value = ''
        toast.add({
            title: t('messages.settings.sessions.others_revoked_title'),
            color: 'success',
        })
        await load()
    } catch (error) {
        toast.add({
            title: t('messages.settings.sessions.failed'),
            // Field-level validation messages ("The password is incorrect.")
            // beat the envelope's generic "the data was invalid" detail.
            description: error.errors?.[0]?.detail ?? error.detail ?? t('messages.common.errors.network_description'),
            color: 'error',
        })
    } finally {
        revokingOthers.value = false
    }
}
</script>

<template>
    <UPageCard
        :description="t('messages.settings.sessions.description')"
        variant="subtle"
        :ui="{ body: 'w-full' }"
    >
        <template #title>
            <div class="flex items-center justify-between gap-4">
                <span>{{ t('messages.settings.nav.sessions') }}</span>

                <UButton
                    :label="t('messages.settings.sessions.revoke_others')"
                    color="error"
                    variant="soft"
                    size="sm"
                    icon="i-tabler-logout-2"
                    :disabled="!hasOtherSessions"
                    @click="revokeOthersOpen = true"
                />
            </div>
        </template>

        <ListSkeleton v-if="loading"/>

        <UAlert
            v-else-if="loadError"
            :description="loadError"
            color="error"
            variant="subtle"
            icon="i-tabler-alert-triangle"
        />

        <template v-else>
            <div class="flex flex-col divide-y divide-default">
                <div
                    v-for="session in sessions"
                    :key="session.id"
                    class="py-4 first:pt-0 last:pb-0"
                >
                    <div class="flex items-center gap-4">
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
                            <p class="text-xs text-muted truncate">
                                {{ session.ip_address }} · {{ t('messages.settings.sessions.last_active') }}
                                {{ formatLastActivity(session.last_activity_at) }}
                            </p>
                        </div>

                        <UButton
                            :label="t('messages.settings.sessions.details')"
                            :trailing-icon="isExpanded(session) ? 'i-tabler-chevron-up' : 'i-tabler-chevron-down'"
                            color="neutral"
                            variant="ghost"
                            size="sm"
                            @click="toggleDetails(session)"
                        />

                        <UButton
                            v-if="!session.is_current"
                            :label="t('messages.settings.sessions.revoke')"
                            color="error"
                            variant="soft"
                            size="sm"
                            @click="revoke(session)"
                        />
                    </div>

                    <dl
                        v-if="isExpanded(session)"
                        class="mt-4 ml-10 flex flex-col gap-3 rounded-md bg-elevated/50 p-4 text-sm"
                    >
                        <div>
                            <dt class="font-medium text-highlighted">{{ t('messages.settings.sessions.device') }}</dt>
                            <dd class="text-muted">{{ session.device_name }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-highlighted">{{ t('messages.settings.sessions.ip') }}</dt>
                            <dd class="text-muted">{{ session.ip_address }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-highlighted">{{
                                    t('messages.settings.sessions.last_active')
                                }}
                            </dt>
                            <dd class="text-muted">{{ formatLastActivity(session.last_activity_at) }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-highlighted">{{
                                    t('messages.settings.sessions.user_agent')
                                }}
                            </dt>
                            <dd class="text-muted break-all">{{ session.user_agent }}</dd>
                        </div>
                    </dl>
                </div>
            </div>

            <p v-if="total > sessions.length" class="text-xs text-muted mt-4">
                {{ t('messages.settings.sessions.showing_capped', { shown: sessions.length, total }) }}
            </p>
        </template>
    </UPageCard>

    <UModal
        v-model:open="revokeOthersOpen"
        :title="t('messages.settings.sessions.revoke_others')"
        :description="t('messages.settings.sessions.revoke_others_description')"
    >
        <template #body>
            <UFormField
                v-if="hasPassword"
                :label="t('messages.settings.sessions.password_label')"
            >
                <PasswordInput
                    v-model="password"
                    class="w-full"
                    :disabled="revokingOthers"
                    @keyup.enter="submitRevokeOthers()"
                />
            </UFormField>
        </template>

        <template #footer>
            <div class="flex w-full justify-end gap-2">
                <UButton
                    :label="t('messages.settings.sessions.cancel')"
                    color="neutral"
                    variant="ghost"
                    @click="revokeOthersOpen = false"
                />
                <UButton
                    :label="t('messages.settings.sessions.confirm')"
                    color="error"
                    :loading="revokingOthers"
                    :disabled="hasPassword && password === ''"
                    @click="submitRevokeOthers()"
                />
            </div>
        </template>
    </UModal>
</template>
