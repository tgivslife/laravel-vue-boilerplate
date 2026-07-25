<script setup>
import { useLocalStorage } from '@vueuse/core'
import { useAppSettingsStore } from '@/stores/AppSettingsStore.js'

const { locale, fallbackLocale } = useI18n()
const appSettings = useAppSettingsStore()

onMounted(() => {
    appSettings.fetch()
})

/*
 * The site-wide announcement (app setting `announcement`): { enabled, level,
 * message: { <locale>: text } }. Rendered for everyone, signed in or not -
 * each layout mounts the banner where its chrome allows (the public layout
 * at the very top, the dashboard inside the content column), so the banner
 * only appears as part of a resolved page, never above an empty one.
 */
const announcement = computed(() => appSettings.settings?.announcement)

/*
 * Localized-value convention: the active locale wins, then the fallback
 * locale, then any locale that has text - a half-translated announcement
 * beats no announcement.
 */
const message = computed(() => {
    const messages = announcement.value?.message ?? {}
    const fallback = typeof fallbackLocale.value === 'string' ? fallbackLocale.value : 'en'

    return messages[locale.value] || messages[fallback] || Object.values(messages).find(text => !!text) || ''
})

const color = computed(() => (
    ['info', 'warning', 'error'].includes(announcement.value?.level) ? announcement.value.level : 'info'
))

const icons = {
    info: 'i-tabler-info-circle',
    warning: 'i-tabler-alert-triangle',
    error: 'i-tabler-alert-octagon',
}

/*
 * UBanner only ships solid color fills with inverted text; these per-color
 * overrides restyle it to the design system's subtle alert look (tinted
 * background, colored text) so the announcement reads as chrome, not alarm.
 * Spelled out per color: Tailwind only picks up literal class strings.
 */
const subtleUi = {
    info: {
        root: 'bg-info/10 border-b border-info/25',
        icon: 'text-info',
        title: 'text-info',
        close: 'text-info hover:bg-info/10 focus-visible:bg-info/10',
    },
    warning: {
        root: 'bg-warning/10 border-b border-warning/25',
        icon: 'text-warning',
        title: 'text-warning',
        close: 'text-warning hover:bg-warning/10 focus-visible:bg-warning/10',
    },
    error: {
        root: 'bg-error/10 border-b border-error/25',
        icon: 'text-error',
        title: 'text-error',
        close: 'text-error hover:bg-error/10 focus-visible:bg-error/10',
    },
}

/*
 * Dismissal is handled here rather than through UBanner's own id mechanism:
 * UBanner only v-show-hides itself, and a display:none banner still matches
 * the :has() rule that reserves its space in the dashboard column - the
 * element must leave the DOM (v-if). The dismissed content hash persists in
 * local storage, so an edited announcement reappears for everyone who
 * dismissed the previous one.
 */
const bannerId = computed(() => {
    const json = JSON.stringify(announcement.value ?? {})
    let hash = 0

    for (let index = 0; index < json.length; index++) {
        hash = ((hash * 31) + json.charCodeAt(index)) | 0
    }

    return `announcement-${(hash >>> 0).toString(36)}`
})

const dismissedId = useLocalStorage('dismissed-announcement', '')

function dismiss () {
    dismissedId.value = bannerId.value
}

const visible = computed(() => (
    announcement.value?.enabled === true
    && message.value !== ''
    && dismissedId.value !== bannerId.value
))
</script>

<template>
    <UBanner
        v-if="visible"
        :title="message"
        :color="color"
        :icon="icons[color]"
        :ui="subtleUi[color]"
        close
        @close="dismiss"
    />
</template>
