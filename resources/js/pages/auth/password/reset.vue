<script setup>
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/AuthStore.js'
import { identityTerms, usePasswordSchema } from '@/composables/usePasswordSchema.js'

definePage({
    meta: {
        layout: 'blank',

        requiresGuest: true,
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
const route = useRoute()
const router = useRouter()

/* ------------------------------------------------------------------ *
 *  View state
 *
 *  Token and email arrive in the emailed link's query string; the pair
 *  is only judged by the API on submit, so a stale link fails with the
 *  same "invalid" message no matter which part is wrong.
 * ------------------------------------------------------------------ */

const token = computed(() => typeof route.query.token === 'string' ? route.query.token : '')
const email = computed(() => typeof route.query.email === 'string' ? route.query.email : '')

const hasUsableLink = token.value !== '' && email.value !== ''

const loading = ref(false)
const errorMessage = ref(hasUsableLink ? '' : t('messages.auth.reset_password.invalid'))

const { passwordFormSchema } = usePasswordSchema()

const schema = computed(() => passwordFormSchema({
    terms: () => identityTerms({ email: email.value }),
}))

const authForm = useTemplateRef('authForm')

const fields = computed(() => [
    {
        name: 'password',
        type: 'password',
        label: t('messages.auth.reset_password.new_password'),
        placeholder: t('messages.auth.reset_password.new_password_placeholder'),
        required: true,
        icon: 'i-tabler-lock',
    },
    {
        name: 'password_confirmation',
        type: 'password',
        label: t('messages.auth.reset_password.confirm_password'),
        placeholder: t('messages.auth.reset_password.confirm_password_placeholder'),
        required: true,
        icon: 'i-tabler-lock-check',
    },
])

async function onSubmit (payload) {
    loading.value = true
    errorMessage.value = ''
    try {
        await authStore.resetPassword({
            token: token.value,
            email: email.value,
            password: payload.data.password,
            password_confirmation: payload.data.password_confirmation,
        })
        toast.add({
            title: t('messages.auth.reset_password.success_title'),
            description: t('messages.auth.reset_password.success_description'),
            color: 'success',
        })
        await router.replace('/auth/login')
    } catch (error) {
        // Policy rejections from the server (e.g. the common-password list, which the client mirror cannot know) land on the field they name.
        if (error.isValidationError && error.errors?.length) {
            authForm.value?.formRef?.setErrors(error.errors.map(fieldError => ({
                name: fieldError.name,
                message: fieldError.detail,
            })))

            return
        }

        errorMessage.value = error.isNetworkError
            ? t('messages.common.errors.network_description')
            : (error.detail ?? t('messages.auth.reset_password.invalid'))
    } finally {
        loading.value = false
    }
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

                    <template v-if="hasUsableLink">
                        <UAuthForm
                            ref="authForm"
                            :title="t('messages.auth.reset_password.title')"
                            :ui="{ title: '', description: 'mb-2' }"
                            :schema="schema"
                            :fields="fields"
                            :submit="{ label: t('messages.auth.reset_password.submit') }"
                            :loading="loading"
                            @submit="onSubmit"
                        >
                            <template #description>
                                <ResetPasswordIllustration class="w-44 h-auto mx-auto my-4 text-highlighted"/>
                                <span class="text-muted text-sm">
                                {{ t('messages.auth.reset_password.description') }}
                            </span>
                            </template>

                            <template #validation>
                                <UAlert
                                    v-if="errorMessage"
                                    :description="errorMessage"
                                    color="error"
                                    variant="subtle"
                                    icon="i-tabler-alert-triangle"
                                />
                            </template>
                        </UAuthForm>
                    </template>

                    <template v-else>
                        <div class="flex flex-col gap-4 text-center">
                            <ResetPasswordIllustration class="w-44 h-auto mx-auto text-highlighted"/>

                            <UAlert
                                :description="errorMessage"
                                color="error"
                                variant="subtle"
                                icon="i-tabler-alert-triangle"
                            />

                            <UButton
                                :label="t('messages.auth.reset_password.back_to_login')"
                                color="neutral"
                                variant="link"
                                to="/auth/login"
                                leading-icon="i-tabler-chevron-left"
                                class="self-center"
                            />
                        </div>
                    </template>
                </UPageCard>
            </div>
        </UContainer>
    </UMain>
</template>
