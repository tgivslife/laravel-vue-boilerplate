<script setup>
import AccessService from '@/services/AccessService.js'

/*
 * Self-fetching tab content for one read-only runtime report: mounts (and
 * loads) only when its tab first activates. The report name selects the
 * endpoint and the i18n branch (messages.access.settings.<report>).
 */
const props = defineProps({
    report: {
        type: String,
        required: true,
        validator: (value) => ['environment', 'config'].includes(value),
    },
})

const { t } = useI18n()
const toast = useAppToast()

const accessService = new AccessService()

const categories = ref([])
const loading = ref(true)

async function load () {
    loading.value = true
    try {
        const data = props.report === 'environment'
            ? await accessService.fetchEnvironment()
            : await accessService.fetchConfigReport()

        categories.value = data.categories
    } catch (error) {
        toast.add({
            title: t('messages.access.load_failed'),
            description: error.detail ?? t('messages.common.errors.network_description'),
            color: 'error',
        })
    } finally {
        loading.value = false
    }
}

onMounted(load)
</script>

<template>
    <div class="flex flex-col divide-y divide-default">
        <AccessSection
            :title="t(`messages.access.settings.${report}.title`)"
            :description="t(`messages.access.settings.${report}.description`)"
        >
            <!-- Key/value row skeleton, mirroring the loaded report's silhouette. -->
            <div v-if="loading" class="flex flex-col gap-8">
                <div v-for="category in 2" :key="category">
                    <USkeleton class="h-4 w-28 mb-3"/>
                    <div
                        v-for="row in 4"
                        :key="row"
                        class="flex items-center justify-between gap-4 border-b border-default py-2 last:border-0"
                    >
                        <USkeleton class="h-3 w-44 max-w-full"/>
                        <USkeleton class="h-3 w-24 shrink-0"/>
                    </div>
                </div>
            </div>

            <AccessRuntimeReport
                v-else
                :categories="categories"
                :label-base="`messages.access.settings.${report}.categories`"
            />
        </AccessSection>
    </div>
</template>
