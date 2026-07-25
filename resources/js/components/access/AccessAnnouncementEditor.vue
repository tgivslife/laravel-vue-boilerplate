<script setup>
/*
 * Structured editor for the `announcement` app setting:
 * { enabled, level, message: { <locale>: text } }.
 * Emits a fresh normalized object on every change; the panel owns the draft
 * and the save/reset lifecycle.
 */
const props = defineProps({
    modelValue: { type: Object, default: null },
    /** Character limit per locale message (from the registry's max rule); null hides the counter. */
    messageLimit: { type: Number, default: null },
})

const emit = defineEmits(['update:modelValue'])

const { t, availableLocales } = useI18n()

const current = computed(() => ({
    enabled: props.modelValue?.enabled === true,
    level: props.modelValue?.level ?? 'info',
    message: props.modelValue?.message ?? {},
}))

/**
 * @param {Object} changes - Partial announcement fields to merge over the current value.
 */
function patch (changes) {
    emit('update:modelValue', { ...current.value, ...changes })
}

const enabled = computed({
    get: () => current.value.enabled,
    set: (value) => patch({ enabled: value }),
})

const level = computed({
    get: () => current.value.level,
    set: (value) => patch({ level: value }),
})

/**
 * @param {string} code - The locale the textarea edits.
 * @param {string} text - The new message text for that locale.
 */
function setMessage (code, text) {
    patch({ message: { ...current.value.message, [code]: text } })
}

const levelOptions = computed(() => [
    { label: t('messages.access.settings.announcement.level_info'), value: 'info' },
    { label: t('messages.access.settings.announcement.level_warning'), value: 'warning' },
    { label: t('messages.access.settings.announcement.level_error'), value: 'error' },
])
</script>

<template>
    <div class="flex flex-col gap-4">
        <USwitch
            v-model="enabled"
            :label="t('messages.access.settings.announcement.enabled_label')"
        />

        <UFormField :label="t('messages.access.settings.announcement.level_label')">
            <USelect
                v-model="level"
                :items="levelOptions"
                class="w-48"
            />
        </UFormField>

        <UFormField
            v-for="code in availableLocales"
            :key="code"
            :label="t('messages.access.settings.announcement.message_label', { locale: code.toUpperCase() })"
        >
            <template #hint>
                <span
                    v-if="messageLimit"
                    class="text-xs tabular-nums"
                    :class="(current.message[code] ?? '').length >= messageLimit ? 'text-error' : 'text-muted'"
                >
                    {{ (current.message[code] ?? '').length }} / {{ messageLimit }}
                </span>
            </template>

            <UTextarea
                :model-value="current.message[code] ?? ''"
                :rows="3"
                :maxlength="messageLimit ?? undefined"
                class="w-full"
                @update:model-value="setMessage(code, $event)"
            />
        </UFormField>
    </div>
</template>
