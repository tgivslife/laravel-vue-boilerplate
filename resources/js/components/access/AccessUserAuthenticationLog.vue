<script setup>
import AccessService from '@/services/AccessService.js'
import { formatDate, formatDateTime } from '@/utils/datetime.js'

/**
 * Read-only admin view of a user's authentication history - the
 * counterpart of the Settings authentication log tab.
 */

const props = defineProps({
    userId: { type: [Number, String], required: true },
})

const { t } = useI18n()

const accessService = new AccessService()

const entries = ref([])
const hasMore = ref(false)
const page = ref(1)
const loading = ref(true)
const loadingMore = ref(false)
const loadError = ref('')

// CalendarDate from UCalendar (toString() gives YYYY-MM-DD); null = no filter.
const filterDate = shallowRef(null)

async function load (nextPage = 1) {
    const isFirstPage = nextPage === 1

    if (isFirstPage) {
        loading.value = true
    } else {
        loadingMore.value = true
    }
    loadError.value = ''

    try {
        const data = await accessService.fetchUserAuthenticationLog(
            props.userId,
            nextPage,
            filterDate.value ? filterDate.value.toString() : null,
        )
        entries.value = isFirstPage ? data.entries : [...entries.value, ...data.entries]
        hasMore.value = data.has_more
        page.value = nextPage
    } catch (error) {
        loadError.value = error.detail ?? t('messages.settings.authentication_log.loading_failed')
    } finally {
        loading.value = false
        loadingMore.value = false
    }
}

onMounted(() => load())

watch(filterDate, () => load())

function formatTimestamp (iso) {
    return formatDateTime(iso) ?? '-'
}

/*
 * Legacy rows and recaller re-logins carry no method; unknown values simply render no badge.
 */
const knownMethods = ['password', 'magic_link', 'invitation', 'roeid', 'id']

function methodLabel (entry) {
    return knownMethods.includes(entry.login_method)
        ? t(`messages.settings.authentication_log.method.${entry.login_method}`)
        : null
}

/*
 * Built from the calendar's date parts instead of parsing the ISO string:
 * new Date('YYYY-MM-DD') is UTC midnight, which formats as the previous day in negative-offset timezones.
 */
function formatFilterDate (calendarDate) {
    return formatDate(new Date(calendarDate.year, calendarDate.month - 1, calendarDate.day))
}
</script>

<template>
    <UCard>
        <div class="flex items-center justify-end gap-1 mb-4">
            <UPopover>
                <UButton
                    color="neutral"
                    variant="subtle"
                    size="sm"
                    icon="i-tabler-calendar"
                    :label="filterDate
                        ? formatFilterDate(filterDate)
                        : t('messages.settings.authentication_log.filter_by_day')"
                />

                <template #content>
                    <UCalendar v-model="filterDate" class="p-2"/>
                </template>
            </UPopover>

            <UTooltip
                v-if="filterDate"
                :text="t('messages.settings.authentication_log.clear_filter')"
            >
                <UButton
                    icon="i-tabler-x"
                    color="neutral"
                    variant="ghost"
                    size="sm"
                    :aria-label="t('messages.settings.authentication_log.clear_filter')"
                    @click="filterDate = null"
                />
            </UTooltip>
        </div>

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
                {{
                    filterDate
                        ? t('messages.settings.authentication_log.empty_filtered')
                        : t('messages.settings.authentication_log.empty')
                }}
            </p>

            <div v-else class="flex flex-col divide-y divide-default">
                <div
                    v-for="(entry, index) in entries"
                    :key="index"
                    class="flex items-start gap-4 py-4 first:pt-0 last:pb-0"
                >
                    <UIcon
                        :name="entry.login_successful ? 'i-tabler-login-2' : 'i-tabler-shield-x'"
                        :class="['size-6 shrink-0 mt-0.5', entry.login_successful ? 'text-muted' : 'text-error']"
                    />

                    <div class="flex-1 min-w-0">
                        <span class="block text-sm font-medium text-highlighted truncate">
                            {{ entry.device_name || '-' }}
                        </span>
                        <p
                            class="text-xs text-muted break-all line-clamp-2"
                            :title="entry.user_agent || undefined"
                        >
                            {{ entry.ip_address || '-' }} · {{ entry.user_agent || '-' }}
                        </p>
                    </div>

                    <div class="flex flex-col items-end gap-1 shrink-0 text-right">
                        <span class="text-xs text-muted">{{ formatTimestamp(entry.login_at) }}</span>
                        <div class="flex items-center gap-1">
                            <UBadge
                                v-if="methodLabel(entry)"
                                :label="methodLabel(entry)"
                                color="neutral"
                                variant="subtle"
                                size="sm"
                            />
                            <UBadge
                                :label="entry.login_successful
                                    ? t('messages.settings.authentication_log.successful')
                                    : t('messages.settings.authentication_log.failed')"
                                :color="entry.login_successful ? 'success' : 'error'"
                                variant="subtle"
                                size="sm"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="hasMore" class="mt-6 flex justify-center">
                <UButton
                    :label="t('messages.settings.authentication_log.load_more')"
                    color="neutral"
                    variant="subtle"
                    :loading="loadingMore"
                    @click="load(page + 1)"
                />
            </div>
        </template>
    </UCard>
</template>
