<script setup>
/**
 * Dual-pane transfer list: available items on the left, assigned on the right, each pane independently searchable and client-side paginated.
 * The selection is the array of assigned ids (v-model); items flagged in `inheritedNames` carry an "inherited" badge so
 * admins can tell a direct grant apart from one a role already provides.
 */

const props = defineProps({
    items: { type: Array, default: () => [] },
    availableLabel: { type: String, required: true },
    assignedLabel: { type: String, required: true },
    inheritedNames: { type: Array, default: () => [] },
    /** Items that display but cannot be moved (e.g. the super-admin role). */
    lockedNames: { type: Array, default: () => [] },
    /**
     * Items above the admin's own grant ceiling: not addable, but - unlike lockedNames - still removable, mirroring the server's added-delta semantics.
     */
    ungrantableNames: { type: Array, default: () => [] },
})

const selected = defineModel({ type: Array, default: () => [] })

const { t } = useI18n()

const PAGE_SIZE = 8

const availableSearch = ref('')
const assignedSearch = ref('')
const availablePage = ref(1)
const assignedPage = ref(1)

function matches (item, term) {
    return item.name.toLowerCase().includes(term.trim().toLowerCase())
}

const availableItems = computed(() => props.items
    .filter(item => !selected.value.includes(item.id) && matches(item, availableSearch.value)))

const assignedItems = computed(() => props.items
    .filter(item => selected.value.includes(item.id) && matches(item, assignedSearch.value)))

function pageOf (items, page) {
    return items.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE)
}

const availablePageItems = computed(() => pageOf(availableItems.value, availablePage.value))
const assignedPageItems = computed(() => pageOf(assignedItems.value, assignedPage.value))

/* Keep the page in range when a search or a move shrinks the list. */
watch([availableItems, availableSearch], () => {
    availablePage.value = Math.min(availablePage.value, Math.max(1, Math.ceil(availableItems.value.length / PAGE_SIZE)))
})
watch([assignedItems, assignedSearch], () => {
    assignedPage.value = Math.min(assignedPage.value, Math.max(1, Math.ceil(assignedItems.value.length / PAGE_SIZE)))
})

function add (item) {
    selected.value = [...selected.value, item.id]
}

function remove (item) {
    selected.value = selected.value.filter(id => id !== item.id)
}

function isInherited (item) {
    return props.inheritedNames.includes(item.name)
}

function isLocked (item) {
    return props.lockedNames.includes(item.name)
}

function isUngrantable (item) {
    return props.ungrantableNames.includes(item.name)
}
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="border border-default rounded-lg p-3 flex flex-col gap-3">
            <div class="flex items-center justify-between gap-2">
                <span class="text-sm font-semibold text-highlighted">{{ availableLabel }}</span>
                <UBadge :label="String(availableItems.length)" color="neutral" variant="outline" size="sm"/>
            </div>

            <UInput
                v-model="availableSearch"
                :placeholder="t('messages.access.search')"
                icon="i-tabler-search"
                size="sm"
            />

            <div class="flex flex-col divide-y divide-default min-h-40">
                <div
                    v-for="item in availablePageItems"
                    :key="item.id"
                    class="flex items-center justify-between gap-2 py-1.5"
                >
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="text-sm truncate">{{ item.name }}</span>
                        <UBadge
                            v-if="isInherited(item)"
                            :label="t('messages.access.users.inherited')"
                            color="neutral"
                            variant="outline"
                            size="sm"
                        />
                    </div>
                    <UBadge
                        v-if="isLocked(item)"
                        :label="t('messages.access.roles.protected')"
                        color="warning"
                        variant="subtle"
                        size="sm"
                    />
                    <UBadge
                        v-else-if="isUngrantable(item)"
                        :label="t('messages.access.not_grantable')"
                        color="warning"
                        variant="subtle"
                        size="sm"
                    />
                    <UButton
                        v-else
                        icon="i-tabler-plus"
                        color="primary"
                        variant="ghost"
                        size="xs"
                        :aria-label="`${t('messages.access.users.assign')} ${item.name}`"
                        @click="add(item)"
                    />
                </div>
                <p v-if="availableItems.length === 0" class="text-sm text-muted py-2">
                    {{ t('messages.access.users.transfer_empty') }}
                </p>
            </div>

            <UPagination
                v-if="availableItems.length > PAGE_SIZE"
                v-model:page="availablePage"
                :total="availableItems.length"
                :items-per-page="PAGE_SIZE"
                size="xs"
                class="self-center"
            />
        </div>

        <div class="border border-default rounded-lg p-3 flex flex-col gap-3">
            <div class="flex items-center justify-between gap-2">
                <span class="text-sm font-semibold text-highlighted">{{ assignedLabel }}</span>
                <UBadge :label="String(assignedItems.length)" color="primary" variant="subtle" size="sm"/>
            </div>

            <UInput
                v-model="assignedSearch"
                :placeholder="t('messages.access.search')"
                icon="i-tabler-search"
                size="sm"
            />

            <div class="flex flex-col divide-y divide-default min-h-40">
                <div
                    v-for="item in assignedPageItems"
                    :key="item.id"
                    class="flex items-center justify-between gap-2 py-1.5"
                >
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="text-sm truncate">{{ item.name }}</span>
                        <UBadge
                            v-if="isInherited(item)"
                            :label="t('messages.access.users.inherited')"
                            color="neutral"
                            variant="outline"
                            size="sm"
                        />
                    </div>
                    <UBadge
                        v-if="isLocked(item)"
                        :label="t('messages.access.roles.protected')"
                        color="warning"
                        variant="subtle"
                        size="sm"
                    />
                    <UButton
                        v-else
                        icon="i-tabler-x"
                        color="error"
                        variant="ghost"
                        size="xs"
                        :aria-label="`${t('messages.access.users.unassign')} ${item.name}`"
                        @click="remove(item)"
                    />
                </div>
                <p v-if="assignedItems.length === 0" class="text-sm text-muted py-2">
                    {{ t('messages.access.users.transfer_empty') }}
                </p>
            </div>

            <UPagination
                v-if="assignedItems.length > PAGE_SIZE"
                v-model:page="assignedPage"
                :total="assignedItems.length"
                :items-per-page="PAGE_SIZE"
                size="xs"
                class="self-center"
            />
        </div>
    </div>
</template>
