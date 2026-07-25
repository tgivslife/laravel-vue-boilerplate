<script setup>
/**
 * Placeholder rows for the session, authentication-log and identity
 * lists: an icon circle, two text lines and a trailing block, mirroring
 * the loaded row shape so the panel keeps its silhouette while fetching.
 */
defineProps({
    rows: { type: Number, default: 3 },
})
</script>

<template>
    <div class="flex flex-col divide-y divide-default">
        <div
            v-for="row in rows"
            :key="row"
            class="flex items-center gap-4 py-4 first:pt-0 last:pb-0"
        >
            <USkeleton class="size-6 rounded-full shrink-0"/>
            <div class="flex-1 min-w-0 flex flex-col gap-2">
                <USkeleton class="h-4 w-40 max-w-full"/>
                <USkeleton class="h-3 w-64 max-w-full"/>
            </div>
            <USkeleton class="h-8 w-24 shrink-0"/>
        </div>
    </div>
</template>
