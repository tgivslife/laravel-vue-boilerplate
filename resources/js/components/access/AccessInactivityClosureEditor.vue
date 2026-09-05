<script setup>
/*
 * Structured editor for the `inactivity_closure` app setting: { enabled, inactive_days, notice_days }.
 * Emits a fresh normalized object on every change; the panel owns the draft and the save/reset lifecycle.
 */
const props = defineProps({
    modelValue: { type: Object, default: null },
})

const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const current = computed(() => ({
    enabled: props.modelValue?.enabled === true,
    inactive_days: props.modelValue?.inactive_days ?? null,
    notice_days: props.modelValue?.notice_days ?? null,
}))

/**
 * @param {Object} changes - Partial policy fields to merge over the current value.
 */
function patch (changes) {
    emit('update:modelValue', { ...current.value, ...changes })
}

const enabled = computed({
    get: () => current.value.enabled,
    set: (value) => patch({ enabled: value }),
})

/**
 * Two-way binding for a day-count field: number inputs hand back strings, and an emptied input must become null
 * (the required rule speaks) rather than 0 or ''.
 *
 * @param {string} field - The policy key the input edits.
 */
function dayCountModel (field) {
    return computed({
        get: () => current.value[field],
        set: (value) => patch({ [field]: value === '' || value === null ? null : Number(value) }),
    })
}

const inactiveDays = dayCountModel('inactive_days')
const noticeDays = dayCountModel('notice_days')
</script>

<template>
    <div class="flex flex-col gap-4">
        <USwitch
            v-model="enabled"
            :label="t('messages.access.settings.inactivity_closure.enabled_label')"
        />

        <UFormField
            :label="t('messages.access.settings.inactivity_closure.inactive_days_label')"
            :help="t('messages.access.settings.inactivity_closure.inactive_days_help')"
        >
            <UInput
                v-model="inactiveDays"
                type="number"
                class="w-48"
            />
        </UFormField>

        <UFormField
            :label="t('messages.access.settings.inactivity_closure.notice_days_label')"
            :help="t('messages.access.settings.inactivity_closure.notice_days_help')"
        >
            <UInput
                v-model="noticeDays"
                type="number"
                class="w-48"
            />
        </UFormField>
    </div>
</template>
