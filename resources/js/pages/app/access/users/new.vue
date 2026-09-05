<script setup>
import { useRbac } from '@/plugins/rbac/composables/useRbac.js'
import AccessService from '@/services/AccessService.js'
import AuthService from '@/services/AuthService.js'
import { useCopyText } from '@/composables/useCopyText.js'

definePage({
    meta: {
        layout: 'protected',

        requiresAuth: true,
    },
})

const { t } = useI18n()
const { can } = useRbac()
const router = useRouter()
const toast = useAppToast()

const accessService = new AccessService()
const authService = new AuthService()

/* ------------------------------------------------------------------ *
 *  User creation page
 *
 *  The detail page's layout without the server-backed facts: identity fields, initial roles and the onboarding delivery stacked as sections.
 *  Creation swaps the form for the delivery's follow-up: the temporary password revealed exactly once, or the invitation-sent
 *  confirmation; "done" continues to the new user's detail page.
 * ------------------------------------------------------------------ */

/* The role picker needs the roles dictionary (roles.view). */
const canPickGrants = computed(() => can('roles.view'))

const roles = ref([])

const form = reactive({ first_name: '', last_name: '', email: '' })
const selectedRoleIds = ref([])
const creating = ref(false)

const createdUser = ref(null)
const createdPassword = ref('')

/* ------------------------------------------------------------------ *
 *  Onboarding delivery
 *
 *  The options mirror the deployment's doors (GET /api/auth/methods): an invitation link when invitations are enabled,
 *  a temporary password when password login exists.
 *  The invitation hint adapts - with password login as the sole door, the link leads to a choose-your-password step.
 *  A lone option hides the selector.
 * ------------------------------------------------------------------ */

// null while loading; on fetch failure fall back to the classic temp-password-only flow.
const authMethods = ref(null)

/* Skeleton over the delivery choice while the methods are on the wire,
 * so the section doesn't pop in and shove the roles picker down. */
const methodsLoading = computed(() => authMethods.value === null)

const delivery = ref('')

const deliveryOptions = computed(() => {
    const options = []

    if (authMethods.value?.invitations) {
        options.push({
            value: 'invitation',
            label: t('messages.access.users.delivery_invitation'),
            description: authMethods.value?.password && !authMethods.value?.magic_link
                ? t('messages.access.users.delivery_invitation_password_hint')
                : t('messages.access.users.delivery_invitation_hint'),
        })
    }

    if (authMethods.value?.password ?? true) {
        options.push({
            value: 'temporary_password',
            label: t('messages.access.users.delivery_temp_password'),
            description: t('messages.access.users.delivery_temp_password_hint'),
        })
    }

    return options
})

async function loadAuthMethods () {
    try {
        authMethods.value = await authService.fetchAuthMethods()
    } catch {
        authMethods.value = { password: true, magic_link: false, invitations: false }
    }

    delivery.value = deliveryOptions.value[0]?.value ?? 'temporary_password'
}

/* Password login and invitations both disabled: no way to hand an account over, so creation is blocked with an explanation. */
const noDeliveryAvailable = computed(() => authMethods.value !== null && deliveryOptions.value.length === 0)

/* The super-admin role is assignable only outside the API. */
const protectedRoleNames = computed(() => roles.value
    .filter(role => role.protected)
    .map(role => role.name))

/* Roles carrying a permission above the creator's own ceiling are refused by the server;
 * the protected check runs first (the super-admin role carries no attached permissions). */
const ungrantableRoleNames = computed(() => roles.value
    .filter(role => !role.protected && (role.permissions ?? []).some(permission => !can(permission.name)))
    .map(role => role.name))

const formComplete = computed(() => form.first_name.trim() !== ''
    && form.last_name.trim() !== ''
    && form.email.trim() !== '')

/* Skeleton over an empty transfer list while the dictionary is on the wire. */
const rolesLoading = ref(true)

async function loadRoles () {
    try {
        const data = await accessService.fetchRoles()
        roles.value = data.roles
    } catch (error) {
        toast.add({
            title: t('messages.access.load_failed'),
            description: error.detail ?? t('messages.common.errors.network_description'),
            color: 'error',
        })
    } finally {
        rolesLoading.value = false
    }
}

onMounted(() => {
    if (can('users.manage')) {
        loadAuthMethods()

        if (canPickGrants.value) {
            loadRoles()
        }
    }
})

function mutationErrorToast (error) {
    toast.add({
        title: t('messages.access.save_failed'),
        description: error.errors?.[0]?.detail ?? error.detail ?? t('messages.common.errors.network_description'),
        color: 'error',
    })
}

async function createUser () {
    if (!formComplete.value || noDeliveryAvailable.value) {
        return
    }

    creating.value = true
    try {
        const data = await accessService.createUser({
            first_name: form.first_name.trim(),
            last_name: form.last_name.trim(),
            email: form.email.trim(),
            // Omitted while the methods fetch is still on the wire; the server then applies its default.
            delivery: delivery.value || undefined,
            role_ids: selectedRoleIds.value,
        })
        createdUser.value = data.user
        createdPassword.value = data.temporary_password ?? ''
        toast.add({
            title: delivery.value === 'invitation'
                ? t('messages.access.users.invited')
                : t('messages.access.users.created'),
            color: 'success',
        })
    } catch (error) {
        mutationErrorToast(error)
    } finally {
        creating.value = false
    }
}

const { copied: passwordCopied, copy: copyText } = useCopyText()

async function copyCreatedPassword () {
    if (!await copyText(createdPassword.value)) {
        toast.add({
            title: t('messages.access.users.copy_failed'),
            color: 'error',
        })
    }
}

async function finish () {
    const userId = createdUser.value.id
    createdPassword.value = ''
    await router.push(`/app/access/users/${userId}`)
}

const bannerName = computed(() => [form.first_name.trim(), form.last_name.trim()]
    .filter(Boolean).join(' ') || form.email.trim())
</script>

<template>
    <UDashboardPanel id="access-user-new">
        <template #header>
            <UDashboardNavbar :title="t('messages.access.users.add_user')">
                <template #leading>
                    <UDashboardSidebarCollapse/>
                    <UTooltip :text="t('messages.access.users.back')">
                        <UButton
                            icon="i-tabler-arrow-left"
                            color="neutral"
                            variant="ghost"
                            to="/app/access/users"
                            :aria-label="t('messages.access.users.back')"
                        />
                    </UTooltip>
                </template>
            </UDashboardNavbar>
        </template>

        <template #body>
            <AccessDenied
                v-if="!can('users.manage')"
                variant="page"
            />

            <div v-else class="flex flex-col gap-4">
                <!-- The detail page's identity card, echoing the form as it is filled in. -->
                <div class="border border-default rounded-lg overflow-hidden">
                    <GridBanner/>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 sm:px-6 pb-4">
                        <UAvatar
                            :alt="bannerName || t('messages.access.users.add_user')"
                            size="3xl"
                            class="-mt-10 ring-4 ring-(--ui-bg)"
                        />
                        <div class="flex-1 min-w-48">
                            <h2 class="text-xl font-semibold text-highlighted">
                                {{ bannerName || t('messages.access.users.add_user') }}
                            </h2>
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-1 text-sm text-muted">
                                <span v-if="form.email.trim()" class="flex items-center gap-1.5">
                                    <UIcon name="i-tabler-mail" class="size-4"/>
                                    {{ form.email.trim() }}
                                </span>
                                <span class="flex items-center gap-1.5">
                                    <UIcon name="i-tabler-shield-check" class="size-4"/>
                                    {{ selectedRoleIds.length }}
                                    {{ t('messages.access.users.col_roles').toLowerCase() }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Created: the delivery's follow-up - the temporary password shown exactly once, or the invitation-sent confirmation. -->
                <div v-if="createdUser" class="flex flex-col gap-4 max-w-lg">
                    <template v-if="createdPassword">
                        <UFormField :label="t('messages.access.users.temp_password')">
                            <div class="flex items-center gap-2">
                                <UInput
                                    :model-value="createdPassword"
                                    readonly
                                    class="flex-1 font-mono"
                                />
                                <UTooltip
                                    :text="passwordCopied
                                        ? t('messages.access.users.password_copied')
                                        : t('messages.access.users.copy_password')"
                                >
                                    <UButton
                                        :icon="passwordCopied ? 'i-tabler-check' : 'i-tabler-copy'"
                                        :color="passwordCopied ? 'success' : 'neutral'"
                                        variant="outline"
                                        :aria-label="passwordCopied
                                            ? t('messages.access.users.password_copied')
                                            : t('messages.access.users.copy_password')"
                                        @click="copyCreatedPassword"
                                    />
                                </UTooltip>
                            </div>
                        </UFormField>

                        <UAlert
                            :description="t('messages.access.users.force_reset_hint')"
                            color="warning"
                            variant="subtle"
                            icon="i-tabler-alert-triangle"
                        />
                    </template>

                    <UAlert
                        v-else
                        :title="t('messages.access.users.invited')"
                        :description="t('messages.access.users.invitation_sent_to', { email: createdUser.email })"
                        color="success"
                        variant="subtle"
                        icon="i-tabler-mail-forward"
                    />

                    <div class="flex justify-end">
                        <UButton
                            :label="t('messages.access.done')"
                            @click="finish"
                        />
                    </div>
                </div>

                <div v-else class="flex flex-col divide-y divide-default">
                    <UAlert
                        v-if="noDeliveryAvailable"
                        :description="t('messages.access.users.no_delivery_available')"
                        color="warning"
                        variant="subtle"
                        icon="i-tabler-alert-triangle"
                        class="mb-4"
                    />

                    <AccessSection
                        :title="t('messages.access.users.profile')"
                        :description="t('messages.access.users.add_user_description')"
                    >
                        <div class="grid gap-4 sm:grid-cols-2">
                            <UFormField :label="t('messages.access.users.first_name')" required>
                                <UInput v-model="form.first_name" class="w-full" autofocus/>
                            </UFormField>
                            <UFormField :label="t('messages.access.users.last_name')" required>
                                <UInput v-model="form.last_name" class="w-full"/>
                            </UFormField>
                            <UFormField :label="t('messages.access.users.col_email')" required class="sm:col-span-2">
                                <UInput v-model="form.email" type="email" class="w-full"/>
                            </UFormField>
                        </div>
                    </AccessSection>

                    <AccessSection
                        v-if="methodsLoading || deliveryOptions.length > 1"
                        :title="t('messages.access.users.delivery_title')"
                        :description="t('messages.access.users.delivery_description')"
                    >
                        <ListSkeleton v-if="methodsLoading" :rows="2"/>
                        <URadioGroup
                            v-else
                            v-model="delivery"
                            :items="deliveryOptions"
                        />
                    </AccessSection>

                    <AccessSection
                        v-if="canPickGrants"
                        :title="t('messages.access.users.roles')"
                        :description="t('messages.access.users.roles_description')"
                    >
                        <ListSkeleton v-if="rolesLoading" :rows="4"/>
                        <AccessTransferList
                            v-else
                            v-model="selectedRoleIds"
                            :items="roles"
                            :available-label="t('messages.access.users.available_roles')"
                            :assigned-label="t('messages.access.users.assigned_roles')"
                            :locked-names="protectedRoleNames"
                            :ungrantable-names="ungrantableRoleNames"
                        />
                    </AccessSection>

                    <div class="flex items-center justify-end gap-2 py-6">
                        <UButton
                            :label="t('messages.access.cancel')"
                            color="neutral"
                            variant="ghost"
                            :disabled="creating"
                            to="/app/access/users"
                        />
                        <UButton
                            :label="t('messages.access.users.create')"
                            :disabled="!formComplete || noDeliveryAvailable"
                            :loading="creating"
                            @click="createUser"
                        />
                    </div>
                </div>
            </div>
        </template>
    </UDashboardPanel>
</template>
