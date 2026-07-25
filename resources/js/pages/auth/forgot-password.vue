<script setup>
import * as v from 'valibot'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/AuthStore.js'
import AuthService from '@/services/AuthService.js'

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
const authService = new AuthService()

const loading = ref(false)

/* ------------------------------------------------------------------ *
 *  Captcha (anti-abuse hook)
 *
 *  The widget only renders when the deployment guards this door and shipped a site key;
 *  A failed methods fetch degrades to no widget (the server still refuses if it expects a token).
 * ------------------------------------------------------------------ */

const authMethods = ref(null)
const captchaToken = ref('')
const captchaWidget = useTemplateRef('captchaWidget')

/* The form waits for the methods fetch (like the login page does): rendering it
 * earlier would mount the captcha area late and shove the layout around. */
const methodsLoading = computed(() => authMethods.value === null)

const showCaptcha = computed(() => Boolean(authMethods.value?.captcha_site_key)
    && (authMethods.value?.captcha_doors ?? []).includes('password_reset'))

onMounted(async () => {
    try {
        authMethods.value = await authService.fetchAuthMethods()
    } catch {
        authMethods.value = { captcha_doors: [] }
    }
})

const schema = computed(() => v.object({
    email: v.pipe(
        v.string(t('messages.auth.validation.invalid_email')),
        v.email(t('messages.auth.validation.invalid_email')),
    ),
}))

const fields = computed(() => [{
    name: 'email',
    type: 'email',
    label: t('messages.auth.fields.email'),
    placeholder: t('messages.auth.fields.email_placeholder'),
    required: true,
    icon: 'i-tabler-mail',
}])

/**
 * The API responds identically whether or not the email has an account,
 * so success here only ever means "the request was accepted".
 */
async function onSubmit (payload) {
    loading.value = true
    try {
        await authStore.requestPasswordReset({
            email: payload.data.email,
            captcha_token: showCaptcha.value ? captchaToken.value : undefined,
        })
        toast.add({
            title: t('messages.auth.forgot_password.sent_title'),
            description: t('messages.auth.forgot_password.sent_description'),
            icon: 'i-tabler-mail-forward',
            color: 'success',
        })
    } catch (error) {
        toast.add({
            title: error.isNetworkError ? t('messages.common.errors.network_title') : t('messages.auth.forgot_password.failed'),
            description: error.detail ?? t('messages.common.errors.network_description'),
            color: 'error',
        })
    } finally {
        // Captcha tokens are single-use: a resend needs a fresh challenge.
        if (showCaptcha.value) {
            captchaWidget.value?.reset()
        }

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
                    <div v-if="methodsLoading" class="flex flex-col gap-4">
                        <USkeleton class="h-6 w-44 mx-auto"/>
                        <USkeleton class="w-44 h-28 mx-auto my-4"/>
                        <USkeleton class="h-4 w-full"/>
                        <div class="space-y-2">
                            <USkeleton class="h-4 w-16"/>
                            <USkeleton class="h-9 w-full"/>
                        </div>
                        <USkeleton class="h-9 w-full"/>
                    </div>

                    <UAuthForm
                        v-else
                        :title="t('messages.auth.forgot_password.title')"
                        :ui="{ title: '', description: 'mb-2' }"
                        :schema="schema"
                        :fields="fields"
                        :submit="{ label: t('messages.auth.forgot_password.send') }"
                        :loading="loading"
                        @submit="onSubmit"
                    >
                        <template #description>
                            <ForgotPasswordIllustration class="w-44 h-auto mx-auto my-4 text-highlighted"/>
                            <span class="text-muted text-sm">
                            {{ t('messages.auth.forgot_password.description') }}
                        </span>
                        </template>

                        <template #validation>
                            <CaptchaWidget
                                v-if="showCaptcha"
                                ref="captchaWidget"
                                v-model="captchaToken"
                                :site-key="authMethods.captcha_site_key"
                                :script-url="authMethods.captcha_script_url"
                                :provider="authMethods.captcha_provider ?? 'turnstile'"
                                class="flex justify-center"
                            />
                        </template>
                    </UAuthForm>

                    <div class="text-center">
                        <UButton
                            :label="t('messages.auth.forgot_password.back_to_login')"
                            color="neutral"
                            variant="link"
                            to="/auth/login"
                            leading-icon="i-tabler-chevron-left"
                        />
                    </div>
                </UPageCard>
            </div>
        </UContainer>
    </UMain>
</template>
