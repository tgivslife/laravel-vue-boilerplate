<script setup>
/**
 * Skeleton rows for a data table's initial load, rendered through
 * UTable's #loading slot so the body shows a placeholder grid instead of
 * flashing empty under the loading bar.
 */
defineProps({
    rows: { type: Number, default: 5 },
    avatar: { type: Boolean, default: false },
    /* Two-line identity next to the avatar (name over email), matching
     * tables whose first column stacks. */
    stacked: { type: Boolean, default: false },
})
</script>

<template>
    <div class="flex flex-col divide-y divide-default">
        <div
            v-for="row in rows"
            :key="row"
            class="flex items-center gap-4 px-4 py-3"
        >
            <USkeleton v-if="avatar" :class="stacked ? 'size-10' : 'size-8'" class="rounded-full shrink-0"/>
            <div v-if="stacked" class="w-1/5 shrink-0 flex flex-col gap-1.5">
                <USkeleton class="h-4 w-full"/>
                <USkeleton class="h-3 w-3/4"/>
            </div>
            <USkeleton v-else class="h-4 w-1/5"/>
            <USkeleton class="h-4 w-1/4"/>
            <USkeleton class="h-5 w-24"/>
            <USkeleton class="h-4 w-16 ms-auto"/>
        </div>
    </div>
</template>
