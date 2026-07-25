<script setup>
import AccessService from '@/services/AccessService.js'
import { useAppSettingsStore } from '@/stores/AppSettingsStore.js'

const { t, te } = useI18n()
const toast = useAppToast()

const accessService = new AccessService()
const appSettings = useAppSettingsStore()

/* ------------------------------------------------------------------ *
 *  Editable registry settings (config/settings.php `app`)
 *
 *  Self-fetching tab content: mounts (and loads) only when its tab
 *  first activates. Each setting is an AccessSection row; labels come
 *  from i18n when a translation exists and fall back to the raw
 *  registry key, so a freshly registered setting is editable before it
 *  is translated.
 * ------------------------------------------------------------------ */

const settings = ref([])
const loading = ref(true)
const savingKey = ref(null)
const drafts = reactive({})

/**
 * JSON-safe deep clone. Setting values arrive as JSON so this loses nothing,
 * and unlike structuredClone it also accepts the reactive proxies the
 * template hands out (structuredClone throws DataCloneError on proxies).
 *
 * @param {*} value
 * @returns {*}
 */
function cloneValue (value) {
    return value === undefined || value === null ? null : JSON.parse(JSON.stringify(value))
}

async function loadSettings () {
    loading.value = true
    try {
        const data = await accessService.fetchAppSettings()
        settings.value = data.settings

        for (const entry of data.settings) {
            drafts[entry.key] = cloneValue(entry.value)
        }
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

function labelOf (entry) {
    const key = `messages.access.settings.keys.${entry.key}.label`
    return te(key) ? t(key) : entry.key
}

function descriptionOf (entry) {
    const key = `messages.access.settings.keys.${entry.key}.description`
    return te(key) ? t(key) : undefined
}

const inputTypes = { url: 'url', email: 'email' }

/**
 * The announcement message's character limit, read from the registry's nested
 * rules (max:N on message.*) so the counter can never drift from the backend.
 *
 * @param {Object} entry - The settings entry as the index endpoint returns it.
 * @returns {number|null}
 */
function messageLimitOf (entry) {
    const rule = (entry.nested?.['message.*'] ?? []).find(rule => /^max:\d+$/.test(rule))
    return rule ? Number(rule.slice(4)) : null
}

/*
 * The draft as the API expects it: empty inputs mean "no value" (the nullable
 * rules expect null, not ''), and the announcement drops locales whose
 * message is blank instead of persisting empty strings.
 */
function draftValue (entry) {
    const value = drafts[entry.key]

    if (entry.type === 'announcement') {
        return {
            enabled: value?.enabled === true,
            level: value?.level ?? 'info',
            message: Object.fromEntries(
                Object.entries(value?.message ?? {})
                    .filter(([, text]) => typeof text === 'string' && text.trim() !== '')
            ),
        }
    }

    return value === '' ? null : value
}

/* JSON comparison so object-valued settings (announcement) compare by content. */
function sameValue (a, b) {
    return JSON.stringify(a ?? null) === JSON.stringify(b ?? null)
}

function isDirty (entry) {
    return !sameValue(draftValue(entry), entry.value)
}

function isOverridden (entry) {
    return !sameValue(entry.value, entry.default)
}

async function save (entry) {
    savingKey.value = entry.key
    try {
        const data = await accessService.updateAppSetting(entry.key, draftValue(entry))
        entry.value = data.value
        drafts[entry.key] = cloneValue(data.value)

        // Public settings feed live consumers (the announcement banner);
        // refresh the bootstrap store so the page reflects the save at once.
        if (entry.public) {
            appSettings.refresh()
        }

        toast.add({
            title: t('messages.access.settings.saved'),
            color: 'success',
        })
    } catch (error) {
        // Validation failures carry the useful text per field (e.g. the
        // message length limit); the envelope's detail is only a fallback.
        toast.add({
            title: t('messages.access.save_failed'),
            description: error.errors?.[0]?.detail
                ?? error.detail
                ?? t('messages.common.errors.network_description'),
            color: 'error',
        })
    } finally {
        savingKey.value = null
    }
}

function resetToDefault (entry) {
    drafts[entry.key] = cloneValue(entry.default)
    return save(entry)
}

onMounted(loadSettings)
</script>

<template>
    <div v-if="loading" class="flex flex-col divide-y divide-default">
        <div v-for="section in 2" :key="section" class="grid gap-x-8 gap-y-4 lg:grid-cols-[16rem_1fr] py-6">
            <div class="flex flex-col gap-2">
                <USkeleton class="h-5 w-32"/>
                <USkeleton class="h-4 w-48"/>
            </div>
            <div class="min-w-0 flex flex-col gap-3">
                <USkeleton class="h-8 w-full"/>
                <USkeleton class="h-20 w-full"/>
                <USkeleton class="h-8 w-32 self-end"/>
            </div>
        </div>
    </div>

    <div v-else class="flex flex-col divide-y divide-default">
        <AccessSection
            v-for="entry in settings"
            :key="entry.key"
            :title="labelOf(entry)"
            :description="descriptionOf(entry)"
        >
            <div class="flex flex-col gap-4">
                <AccessAnnouncementEditor
                    v-if="entry.type === 'announcement'"
                    v-model="drafts[entry.key]"
                    :message-limit="messageLimitOf(entry)"
                />

                <UInput
                    v-else
                    v-model="drafts[entry.key]"
                    :type="inputTypes[entry.type] ?? 'text'"
                    :placeholder="entry.default ?? t('messages.access.settings.empty_placeholder')"
                    class="w-72"
                />

                <div class="flex flex-wrap items-center gap-2">
                    <UBadge
                        v-if="entry.public"
                        :label="t('messages.access.settings.public_badge')"
                        color="neutral"
                        variant="subtle"
                    />

                    <UButton
                        v-if="isOverridden(entry)"
                        :label="t('messages.access.settings.reset')"
                        color="neutral"
                        variant="ghost"
                        class="ms-auto"
                        :disabled="savingKey === entry.key"
                        @click="resetToDefault(entry)"
                    />

                    <UButton
                        :label="t('messages.access.save')"
                        :loading="savingKey === entry.key"
                        :disabled="!isDirty(entry)"
                        :class="!isOverridden(entry) && 'ms-auto'"
                        @click="save(entry)"
                    />
                </div>
            </div>
        </AccessSection>
    </div>
</template>
