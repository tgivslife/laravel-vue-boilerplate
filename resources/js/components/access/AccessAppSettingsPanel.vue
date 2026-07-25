<script setup>
/* ------------------------------------------------------------------ *
 *  App settings: container tabs, mirroring the role detail page.
 *
 *  Pure shell: each tab's content is a self-fetching component, and
 *  UTabs only mounts a tab when it first activates - so the settings
 *  editor, the environment report and the config report each load
 *  lazily behind their own skeleton, and nothing is fetched for tabs
 *  the admin never opens.
 * ------------------------------------------------------------------ */

const { t } = useI18n()

const tabItems = computed(() => [
    { label: t('messages.access.settings.tab_settings'), icon: 'i-tabler-adjustments', slot: 'settings' },
    { label: t('messages.access.settings.environment.title'), icon: 'i-tabler-server-cog', slot: 'environment' },
    { label: t('messages.access.settings.config.title'), icon: 'i-tabler-file-settings', slot: 'config' },
])
</script>

<template>
    <UTabs :items="tabItems" variant="link">
        <template #settings>
            <AccessAppSettingsEditor/>
        </template>

        <template #environment>
            <AccessRuntimeReportTab report="environment"/>
        </template>

        <template #config>
            <AccessRuntimeReportTab report="config"/>
        </template>
    </UTabs>
</template>
