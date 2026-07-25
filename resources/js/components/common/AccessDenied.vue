<script setup>
/**
 * The one place a missing permission is rendered, so every guarded page says the same thing.
 * `alert` is the inline banner used when the denial only covers part of a screen;
 * `page` is the full empty state, with the illustration, for when the whole panel body is off limits.
 */
defineProps({
    variant: {
        type: String,
        default: 'alert',
        validator: value => ['alert', 'page'].includes(value),
    },
    title: { type: String, default: null },
    description: { type: String, default: null },
})

const { t } = useI18n()
</script>

<template>
    <UAlert
        v-if="variant === 'alert'"
        :title="title ?? t('messages.access.forbidden_title')"
        :description="description ?? t('messages.access.forbidden_description')"
        color="warning"
        variant="subtle"
        icon="i-tabler-lock"
    />

    <div v-else class="flex flex-col items-center justify-center text-center py-12 px-4">
        <ForbiddenIllustration class="w-52 h-auto mb-8 text-highlighted"/>

        <h2 class="text-xl font-semibold text-highlighted">
            {{ title ?? t('messages.access.forbidden_title') }}
        </h2>

        <p class="mt-2 max-w-md text-muted">
            {{ description ?? t('messages.access.forbidden_description') }}
        </p>

        <div v-if="$slots.actions" class="mt-8 flex flex-wrap items-center justify-center gap-3">
            <slot name="actions"/>
        </div>
    </div>
</template>
