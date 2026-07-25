<script setup>
/*
 * Shared renderer for the read-only runtime reports (environment variables,
 * effective config): categories stacked in the content column, each a small
 * heading over name/value rows. Secret rows arrive value-less from the server
 * and render as a set/not-set lock badge.
 */
const props = defineProps({
    /** Report categories as the API returns them: { key, variables: [{ name, value, set, secret }] }. */
    categories: { type: Array, required: true },
    /** i18n prefix resolving `${labelBase}.${category.key}` to a category label; raw key fallback. */
    labelBase: { type: String, required: true },
})

const { t, te } = useI18n()

function categoryLabel (category) {
    const key = `${props.labelBase}.${category.key}`
    return te(key) ? t(key) : category.key
}

/**
 * @param {Object} variable - One report row.
 * @returns {string}
 */
function displayValue (variable) {
    if (variable.value !== null && typeof variable.value === 'object') {
        return JSON.stringify(variable.value)
    }

    return variable.value === '' ? '""' : String(variable.value)
}
</script>

<template>
    <div class="flex flex-col gap-8">
        <div v-for="category in categories" :key="category.key">
            <h4 class="text-sm font-semibold text-highlighted mb-2">
                {{ categoryLabel(category) }}
            </h4>

            <div
                v-for="variable in category.variables"
                :key="variable.name"
                class="flex items-center justify-between gap-4 border-b border-default py-1.5 last:border-0"
            >
                <span class="font-mono text-xs text-muted">{{ variable.name }}</span>

                <UBadge
                    v-if="variable.secret"
                    :label="variable.set
                        ? t('messages.access.settings.report.set')
                        : t('messages.access.settings.report.not_set')"
                    :color="variable.set ? 'neutral' : 'warning'"
                    variant="subtle"
                    icon="i-tabler-lock"
                />

                <span
                    v-else-if="!variable.set"
                    class="text-xs italic text-dimmed"
                >
                    {{ t('messages.access.settings.report.not_set') }}
                </span>

                <span
                    v-else
                    class="break-all text-right font-mono text-xs text-highlighted"
                >
                    {{ displayValue(variable) }}
                </span>
            </div>
        </div>
    </div>
</template>
