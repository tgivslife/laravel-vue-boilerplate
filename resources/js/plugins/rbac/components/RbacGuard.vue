<script setup>
import { computed, watchEffect } from 'vue'
import { useAuthStore } from '@/stores/AuthStore.js'
import { checkAccess } from '../access.js'

defineOptions({ name: 'RbacGuard' })

const props = defineProps({
    /** At least one of the given roles. */
    role: { type: [String, Array], default: undefined },
    /** Every given permission (alias of `all`). */
    permission: { type: [String, Array], default: undefined },
    /** At least one of the given permissions. */
    any: { type: [String, Array], default: undefined },
    /** Every given permission. */
    all: { type: [String, Array], default: undefined },
    /** Denied when any given value matches a role or a permission. */
    not: { type: [String, Array], default: undefined },
})

const CHECK_PROPS = ['role', 'permission', 'any', 'all', 'not']

const authStore = useAuthStore()

const providedChecks = computed(() => CHECK_PROPS.filter(name => props[name] !== undefined))

watchEffect(() => {
    if (providedChecks.value.length > 1) {
        console.warn(`[RbacGuard] Multiple access props set (${providedChecks.value.join(', ')}); only "${providedChecks.value[0]}" is evaluated.`)
    }
})

// Fail closed: no access prop means nothing is ever rendered.
const isAllowed = computed(() => {
    const type = providedChecks.value[0]

    return type !== undefined && checkAccess(type, props[type])
})
</script>

<template>
    <slot v-if="!authStore.isInitialized" name="loading"/>
    <slot v-else-if="isAllowed"/>
    <slot v-else name="fallback"/>
</template>
