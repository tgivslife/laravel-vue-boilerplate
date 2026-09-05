<script setup>
/*
 * UInput with a show/hide toggle for password entry. Attributes (class, placeholder, autocomplete, ...) fall through to the UInput root.
 * The toggle is kept out of the tab order so keyboard users go straight from field to field; it stays reachable by pointer
 * and screen readers get the aria state.
 */
const model = defineModel({ type: String, default: '' })

const { t } = useI18n()

const revealed = ref(false)
</script>

<template>
    <UInput
        v-model="model"
        :type="revealed ? 'text' : 'password'"
        icon="i-tabler-lock"
        :ui="{ trailing: 'pe-1' }"
    >
        <template #trailing>
            <UTooltip
                :text="revealed
                    ? t('messages.common.password.hide')
                    : t('messages.common.password.show')"
            >
                <UButton
                    color="neutral"
                    variant="link"
                    size="sm"
                    :icon="revealed ? 'i-tabler-eye-off' : 'i-tabler-eye'"
                    :aria-label="revealed
                        ? t('messages.common.password.hide')
                        : t('messages.common.password.show')"
                    :aria-pressed="revealed"
                    tabindex="-1"
                    @click="revealed = !revealed"
                />
            </UTooltip>
        </template>
    </UInput>
</template>
