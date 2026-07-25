<script setup>
import AnnouncementBanner from '@/components/common/AnnouncementBanner.vue'
import { useAuthStore } from '@/stores/AuthStore.js'
import { useNavigationItems } from '@/composables/useNavigationItems.js'
import { useUserMenuItems } from '@/composables/useUserMenuItems.js'

const authStore = useAuthStore()

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
                <UNavigationMenu
                    :collapsed="collapsed"
                    :items="navigationItems"
                    orientation="vertical"
                    tooltip
                    popover
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

        <!-- The dashboard group is a fixed, viewport-filling flex row, so the
             banner lives inside the content column, pinned just below the
             page's navbar: the navbar (--ui-header-height) and the banner
             (h-12) both have theme-fixed heights, and the main.css
             app-announcement rule makes the panel body yield the banner's
             height so content never starts hidden beneath it. -->
        <div class="app-content-column relative flex min-w-0 flex-1 flex-col">
            <AnnouncementBanner class="app-announcement absolute inset-x-0 top-(--ui-header-height)"/>
            <RouterView/>
        </div>
    </UDashboardGroup>
</template>
