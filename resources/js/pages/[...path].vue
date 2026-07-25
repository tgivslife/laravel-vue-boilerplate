<script setup>
definePage({
    meta: {
        layout: 'blank',
    },
})

const { t } = useI18n()
const { locale, availableLocales, messages } = useI18n()

const locales = computed(() => availableLocales.map(l => ({
    ...messages.value[l],
    label: messages.value[l]?.name || l.toUpperCase(),
    code: l,
})))
</script>

<template>
    <UHeader :toggle="false">
        <template #left>
            <UButton
                :label="$t('messages.landing.home')"
                color="neutral"
                to="/"
                leading-icon="i-tabler:chevron-left"
                variant="link"
            />
        </template>

        <template #right>
            <UColorModeButton/>

            <ULocaleSelect v-model="locale"
                           :locales="locales"
                           class="hidden lg:inline-flex"/>
        </template>
    </UHeader>

    <UError
        :error="{
            statusCode: 404,
            statusMessage: t('messages.common.errors.not_found_title'),
            message: t('messages.common.errors.not_found_description'),
        }"
        :clear="false"
    >
        <template #leading>
            <NotFoundIllustration class="w-full max-w-md h-auto mx-auto mb-4 text-highlighted"/>
        </template>

        <template #links>
            <UButton
                :label="t('messages.common.errors.back_home')"
                leading-icon="i-tabler-home"
                size="lg"
                to="/"
            />
        </template>
    </UError>
</template>
