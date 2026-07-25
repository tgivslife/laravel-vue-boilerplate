<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
})
</script>

<!--
    A settings-style section row: heading and description in a fixed-width
    left column (so it doesn't balloon on wide screens), the section's
    content in the remaining space.
-->
<template>
    <div class="grid gap-x-8 gap-y-4 lg:grid-cols-[16rem_1fr] py-6">
        <div>
            <h3 class="text-base font-semibold text-highlighted">{{ title }}</h3>
            <p v-if="description" class="text-sm text-muted mt-1">{{ description }}</p>
        </div>

        <div class="min-w-0">
            <slot/>
        </div>
    </div>
</template>
