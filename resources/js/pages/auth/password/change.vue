<script setup>
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/AuthStore.js'
import SettingsService from '@/services/SettingsService.js'
import { identityTerms, usePasswordSchema } from '@/composables/usePasswordSchema.js'

definePage({
    meta: {
        layout: 'blank',

        requiresAuth: true,
    },
})

const { t } = useI18n()
const { locale, availableLocales, messages } = useI18n()

const locales = computed(() => availableLocales.map(l => ({
    ...messages.value[l],
    label: messages.value[l]?.name || l.toUpperCase(),
    code: l,
})))

const toast = useAppToast()
const authStore = useAuthStore()
const router = useRouter()

const settingsService = new SettingsService()

/* ------------------------------------------------------------------ *
 *  Forced password change
 *
 *  Admin-flagged accounts land here from the router guard; the API
 *  blocks everything except this change until the flag clears.
 * ------------------------------------------------------------------ */

const hasPassword = computed(() => authStore.user?.has_password !== false)

const state = reactive({
    current_password: '',
    password: '',
    password_confirmation: '',
})

const { passwordFormSchema } = usePasswordSchema()

const schema = computed(() => passwordFormSchema({
    terms: () => identityTerms({
        email: authStore.user?.email,
        firstName: authStore.user?.first_name,
        lastName: authStore.user?.last_name,
    }),
    requireCurrent: hasPassword.value,
}))

const changing = ref(false)
const changeForm = useTemplateRef('changeForm')

async function onSubmit () {
    changing.value = true
    try {
        await settingsService.updatePassword(hasPassword.value
            ? { ...state }
            : { password: state.password, password_confirmation: state.password_confirmation })

        // The flag is cleared server-side; refresh so the guard lets us in.
        await authStore.fetchUser()

        toast.add({
            title: t('messages.settings.security.password_updated_title'),
            description: t('messages.settings.security.password_updated_description'),
            color: 'success',
        })

        await router.replace('/app')
    } catch (error) {
        if (error.isValidationError && error.errors?.length) {
            changeForm.value?.setErrors(error.errors.map(fieldError => ({
                name: fieldError.name,
                message: fieldError.detail,
            })))

            return
        }

        toast.add({
            title: t('messages.settings.security.password_update_failed'),
            description: error.detail ?? t('messages.common.errors.network_description'),
            color: 'error',
        })
    } finally {
        changing.value = false
    }
}

async function onLogout () {
    await authStore.logout()
    await router.push('/auth/login')
}
</script>

<template>
    <UHeader :toggle="false">
        <template #left>
            <UButton
                :label="$t('messages.landing.home')"
                color="neutral"
                to="/"
                leading-icon="i-tabler:chevron-left"
                variant="link"
            />
        </template>

        <template #right>
            <UColorModeButton/>

            <ULocaleSelect v-model="locale"
                           :locales="locales"
                           class="hidden lg:inline-flex"/>
        </template>
    </UHeader>

    <UMain class="relative">
        <Particles
            class="absolute inset-0"
            color="#666666"
            :ease="20"
            :quantity="120"
        />

        <UContainer
            class="flex min-h-[calc(100vh-4rem)] items-center justify-center"
        >
            <div class="w-full max-w-sm">
                <div class="flex items-center justify-center mb-6">
                    <AppLogo class="w-auto h-7 shrink-0"/>
                </div>

                <UPageCard
                    variant="subtle"
                    class="w-full"
                >

                    <div class="flex flex-col gap-y-6">
                        <div class="text-center">
                            <h1 class="text-xl font-semibold">
                                {{ t('messages.auth.force_change.title') }}
                            </h1>
                            <ChangePasswordIllustration class="w-44 h-auto mx-auto my-4 text-highlighted"/>
                            <p class="text-muted text-sm">
                                {{ t('messages.auth.force_change.description') }}
                            </p>
                        </div>

                        <UForm
                            ref="changeForm"
                            :schema="schema"
                            :state="state"
                            class="flex flex-col gap-4"
                            @submit="onSubmit"
                        >
                            <UFormField
                                v-if="hasPassword"
                                :label="t('messages.settings.security.current_password')"
                                name="current_password"
                                required
                            >
                                <PasswordInput v-model="state.current_password" class="w-full"/>
                            </UFormField>

                            <UFormField
                                :label="t('messages.settings.security.new_password')"
                                name="password"
                                required
                            >
                                <PasswordInput v-model="state.password" class="w-full"/>
                            </UFormField>

                            <UFormField
                                :label="t('messages.settings.security.confirm_password')"
                                name="password_confirmation"
                                required
                            >
                                <PasswordInput v-model="state.password_confirmation" class="w-full"/>
                            </UFormField>

                            <UButton
                                type="submit"
                                :label="hasPassword
                                ? t('messages.settings.security.change')
                                : t('messages.settings.security.set_password')"
                                :loading="changing"
                                block
                            />
                        </UForm>

                        <div class="text-center">
                            <UButton
                                :label="t('messages.app.nav.logout')"
                                color="neutral"
                                variant="link"
                                leading-icon="i-tabler-logout"
                                @click="onLogout"
                            />
                        </div>
                    </div>
                </UPageCard>
            </div>
        </UContainer>
    </UMain>
</template>
