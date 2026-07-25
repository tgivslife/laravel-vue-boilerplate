<script setup>
import * as v from 'valibot'
import { useAuthStore } from '@/stores/AuthStore.js'
import SettingsService from '@/services/SettingsService.js'

const { t } = useI18n()
const toast = useAppToast()
const authStore = useAuthStore()
const router = useRouter()

const settingsService = new SettingsService()

const state = reactive({
    first_name: authStore.user?.first_name ?? '',
    last_name: authStore.user?.last_name ?? '',
})

const schema = computed(() => v.object({
    first_name: v.pipe(
        v.string(t('messages.settings.account.validation_required')),
        v.minLength(1, t('messages.settings.account.validation_required')),
        v.maxLength(255),
    ),
    last_name: v.pipe(
        v.string(t('messages.settings.account.validation_required')),
        v.minLength(1, t('messages.settings.account.validation_required')),
        v.maxLength(255),
    ),
}))

const fullName = computed(() => `${authStore.user?.first_name ?? ''} ${authStore.user?.last_name ?? ''}`.trim())
const saving = ref(false)

async function onSubmit () {
    saving.value = true
    try {
        await settingsService.updateProfile({ ...state })
        await authStore.fetchUser()
        toast.add({
            title: t('messages.settings.account.saved_title'),
            description: t('messages.settings.account.saved_description'),
            color: 'success',
        })
    } catch (error) {
        toast.add({
            title: t('messages.settings.account.save_failed'),
            description: error.detail ?? t('messages.common.errors.network_description'),
            color: 'error',
        })
    } finally {
        saving.value = false
    }
}

/* ------------------------------------------------------------------ *
 *  Account deletion
 *
 *  Password accounts confirm with their password; passwordless ones
 *  type their email - mirroring what the API validates.
 * ------------------------------------------------------------------ */

const hasPassword = computed(() => authStore.user?.has_password !== false)

const deleteModalOpen = ref(false)
const deleteConfirmation = ref('')
const deleting = ref(false)

function submitDelete () {
    if (deleteConfirmation.value === '' || deleting.value) {
        return
    }

    onDelete()
}

async function onDelete () {
    deleting.value = true
    try {
        await settingsService.deleteAccount(hasPassword.value
            ? { password: deleteConfirmation.value }
            : { email: deleteConfirmation.value })

        deleteModalOpen.value = false
        authStore.clearSession()
        toast.add({
            title: t('messages.settings.account.deleted_title'),
            description: t('messages.settings.account.deleted_description'),
            color: 'success',
        })
        await router.replace('/auth/login')
    } catch (error) {
        toast.add({
            title: t('messages.settings.account.delete_failed'),
            // Field-level validation messages ("The password is incorrect.")
            // beat the envelope's generic "the data was invalid" detail.
            description: error.errors?.[0]?.detail ?? error.detail ?? t('messages.common.errors.network_description'),
            color: 'error',
        })
    } finally {
        deleting.value = false
    }
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <UPageCard
            :title="t('messages.settings.nav.account')"
            :description="t('messages.settings.account.description')"
            variant="subtle"
        >
            <div class="flex items-center gap-4 mb-8">
                <UAvatar :alt="fullName" size="3xl"/>

                <div class="min-w-0">
                    <p class="font-medium text-highlighted truncate">{{ fullName }}</p>
                    <p class="text-sm text-muted truncate">{{ authStore.user?.email }}</p>
                </div>
            </div>

            <UForm
                :schema="schema"
                :state="state"
                class="flex flex-col gap-4 max-w-3xl"
                @submit="onSubmit"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <UFormField
                        :label="t('messages.settings.account.first_name')"
                        name="first_name"
                        required
                    >
                        <UInput v-model="state.first_name" class="w-full"/>
                    </UFormField>

                    <UFormField
                        :label="t('messages.settings.account.last_name')"
                        name="last_name"
                        required
                    >
                        <UInput v-model="state.last_name" class="w-full"/>
                    </UFormField>
                </div>

                <UFormField
                    :label="t('messages.settings.account.email')"
                    :hint="t('messages.settings.account.email_hint')"
                >
                    <UInput
                        :model-value="authStore.user?.email"
                        disabled
                        icon="i-tabler-mail"
                        class="w-full"
                    />
                </UFormField>

                <div>
                    <UButton
                        type="submit"
                        :label="t('messages.settings.account.save')"
                        :loading="saving"
                    />
                </div>
            </UForm>
        </UPageCard>

        <UPageCard
            :title="t('messages.settings.account.danger_title')"
            :description="t('messages.settings.account.danger_description')"
            variant="subtle"
        >
            <div>
                <UButton
                    :label="t('messages.settings.account.delete')"
                    color="error"
                    variant="soft"
                    icon="i-tabler-trash"
                    @click="deleteModalOpen = true"
                />
            </div>
        </UPageCard>

        <UModal
            v-model:open="deleteModalOpen"
            :title="t('messages.settings.account.delete_confirm_title')"
            :description="t('messages.settings.account.delete_confirm_description')"
        >
            <template #body>
                <UFormField
                    :label="hasPassword
                        ? t('messages.settings.account.delete_password_label')
                        : t('messages.settings.account.delete_email_label')"
                >
                    <PasswordInput
                        v-if="hasPassword"
                        v-model="deleteConfirmation"
                        class="w-full"
                        :disabled="deleting"
                        @keyup.enter="submitDelete()"
                    />
                    <UInput
                        v-else
                        v-model="deleteConfirmation"
                        type="email"
                        class="w-full"
                        :disabled="deleting"
                        @keyup.enter="submitDelete()"
                    />
                </UFormField>
            </template>

            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton
                        :label="t('messages.settings.account.delete_cancel')"
                        color="neutral"
                        variant="ghost"
                        @click="deleteModalOpen = false"
                    />
                    <UButton
                        :label="t('messages.settings.account.delete_confirm')"
                        color="error"
                        :loading="deleting"
                        :disabled="deleteConfirmation === ''"
                        @click="submitDelete()"
                    />
                </div>
            </template>
        </UModal>
    </div>
</template>
