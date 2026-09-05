<script setup>
/**
 * Skeleton rows for a data table's initial load, rendered through UTable's #loading slot.
 * The point is to hold the real row's height - a shorter skeleton makes the page jump when the data lands,
 * which is the shift the placeholder exists to prevent.
 */
const props = defineProps({
    rows: { type: Number, default: 5 },
    avatar: { type: Boolean, default: false },
    /* Two-line identity next to the avatar (name over email), matching tables whose first column stacks. */
    stacked: { type: Boolean, default: false },
    /* A leading checkbox, for tables with row selection. */
    select: { type: Boolean, default: false },
    /* Bars between the identity block and the trailing actions bar: a number of single-line
     * columns, or an array of per-column line counts for tables whose cells stack. */
    columns: { type: [Number, Array], default: 2 },
})

/**
 * Line count per middle column, with the number form widened to all-single-line.
 *
 * @returns {number[]}
 */
const columnLines = computed(() => (Array.isArray(props.columns)
    ? props.columns
    : Array.from({ length: props.columns }, () => 1)))

/**
 * The original positional widths: first column wide, second a badge, the rest narrow.
 *
 * @param {number} index zero-based column position
 * @returns {string}
 */
function widthFor (index) {
    if (index === 0) {
        return 'w-1/4'
    }

    return index === 1 ? 'w-24' : 'w-16'
}
</script>

<template>
    <div class="flex flex-col divide-y divide-default">
        <div
            v-for="row in rows"
            :key="row"
            class="flex items-center gap-4 px-4 py-4"
        >
            <USkeleton v-if="select" class="size-4 rounded-sm shrink-0"/>
            <USkeleton v-if="avatar" :class="stacked ? 'size-10' : 'size-8'" class="rounded-full shrink-0"/>
            <div v-if="stacked" class="w-1/5 shrink-0 flex flex-col gap-2">
                <USkeleton class="h-4 w-full"/>
                <USkeleton class="h-3 w-3/4"/>
            </div>
            <USkeleton v-else class="h-4 w-1/5"/>

            <!-- Single-line columns keep the original bar exactly; only a stacking column
                 grows a wrapper, so the untouched tables render as they did. -->
            <template v-for="(lines, index) in columnLines" :key="index">
                <USkeleton
                    v-if="lines === 1"
                    :class="{ 'h-4 w-1/4': index === 0, 'h-5 w-24': index === 1, 'h-4 w-16': index > 1 }"
                />
                <div v-else class="shrink-0 flex flex-col gap-2" :class="widthFor(index)">
                    <USkeleton v-for="line in lines" :key="line" class="h-4 w-full"/>
                </div>
            </template>

            <USkeleton class="h-4 w-16 ms-auto"/>
        </div>
    </div>
</template>
