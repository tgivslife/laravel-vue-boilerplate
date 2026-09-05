<script setup>
import AnnouncementBanner from '@/components/common/AnnouncementBanner.vue'
import { useAuthStore } from '@/stores/AuthStore.js'
import { useNavigationItems } from '@/composables/useNavigationItems.js'
import { useUserMenuItems } from '@/composables/useUserMenuItems.js'

const authStore = useAuthStore()
const route = useRoute()

const open = ref(false)

const fullName = computed(() => {
    const user = authStore.user
    return user ? `${user.first_name} ${user.last_name}`.trim() : ''
})

function closeSidebarOnMobile () {
    open.value = false
}

const navigationItems = useNavigationItems(closeSidebarOnMobile)
const userMenuItems = useUserMenuItems(fullName)
</script>

<template>
    <UDashboardGroup unit="rem" storage="local">
        <UDashboardSidebar
            id="default"
            v-model:open="open"
            collapsible
            resizable
            class="bg-elevated/25"
            :ui="{ footer: 'lg:border-t lg:border-default' }"
        >
            <template #header="{ collapsed }">
                <RouterLink v-if="!collapsed" to="/app" class="flex items-center px-1">
                    <AppLogo class="w-auto h-6 shrink-0"/>
                </RouterLink>
            </template>

            <template #default="{ collapsed }">
                <!-- Section headers read as quiet dividers, not entries; the chevron turns down as a
                     group opens, so a closed one is legible without reading its icon. -->
                <UNavigationMenu
                    :collapsed="collapsed"
                    :items="navigationItems"
                    orientation="vertical"
                    trailing-icon="i-tabler-chevron-right"
                    tooltip
                    popover
                    :ui="{
                        label: 'text-dimmed font-normal mt-3',
                        linkTrailingIcon: 'group-data-[state=open]:rotate-90',
                    }"
                />
            </template>

            <template #footer="{ collapsed }">
                <UDropdownMenu
                    :items="userMenuItems"
                    :content="{ align: 'center', collisionPadding: 12 }"
                    :ui="{ content: collapsed ? 'w-48' : 'w-(--reka-dropdown-menu-trigger-width)' }"
                >
                    <UButton
                        :avatar="{ alt: fullName }"
                        :label="collapsed ? undefined : fullName"
                        :trailing-icon="collapsed ? undefined : 'i-tabler-selector'"
                        color="neutral"
                        variant="ghost"
                        block
                        :square="collapsed"
                        :class="[
                            'data-[state=open]:bg-elevated',
                            authStore.isImpersonating
                                && 'ring-2 ring-inset ring-violet-500 dark:ring-violet-400 bg-violet-500/10',
                        ]"
                    />
                </UDropdownMenu>
            </template>
        </UDashboardSidebar>

        <!-- The banner pins inside the content column just below the navbar; main.css's
             app-announcement rule makes the panel body yield its height, so content never starts
             hidden beneath it. -->
        <div class="app-content-column relative flex min-w-0 flex-1 flex-col">
            <AnnouncementBanner class="app-announcement absolute inset-x-0 top-(--ui-header-height)"/>

            <!-- Keyed by path so a parameter change remounts: detail screens fetch in onMounted
                 alone, and Vue reusing the component would keep /users/1's data under /users/2's id.
                 Path, never fullPath - keying on the query would remount on every filter keystroke. -->
            <RouterView :key="route.path"/>
        </div>
    </UDashboardGroup>
</template>
