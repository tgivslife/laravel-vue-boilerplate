<script setup>
import * as v from 'valibot'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/AuthStore.js'
import AuthService from '@/services/AuthService.js'
import { resolveRedirectTarget } from '@/plugins/1.router/redirect.js'

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
const router = useRouter()
const route = useRoute()

const authService = new AuthService()

/* ------------------------------------------------------------------ *
 *  View state
 * ------------------------------------------------------------------ */

const LOGIN_METHOD = {
    PASSWORD: 'password',
    MAGIC_LINK: 'magic_link',
}

const method = ref(LOGIN_METHOD.PASSWORD)
const loading = ref(false)

const methodTabs = computed(() => [
    { label: t('messages.auth.fields.password'), value: LOGIN_METHOD.PASSWORD },
    { label: t('messages.auth.magic_link.label'), value: LOGIN_METHOD.MAGIC_LINK },
])

const passwordSchema = computed(() => v.object({
    email: v.pipe(
        v.string(t('messages.auth.validation.invalid_email')),
        v.email(t('messages.auth.validation.invalid_email')),
    ),
    password: v.pipe(
        v.string(t('messages.auth.validation.invalid_password')),
        v.minLength(1, t('messages.auth.validation.password_required')),
    ),
    remember: v.boolean(),
}))

const magicSchema = computed(() => v.object({
    email: v.pipe(
        v.string(t('messages.auth.validation.invalid_email')),
        v.email(t('messages.auth.validation.invalid_email')),
    ),
}))

const schema = computed(() => method.value === LOGIN_METHOD.MAGIC_LINK ? magicSchema.value : passwordSchema.value)

/* ------------------------------------------------------------------ *
 *  Available sign-in methods
 *
 *  The page adapts to security.php: password and magic-link tabs only
 *  when enabled, provider buttons per enabled OIDC provider, and a
 *  provider-only or unavailable layout when credentials are off.
 *  Provider failures land back here with an `error` query -> toast.
 * ------------------------------------------------------------------ */

const providerIcons = {
    roeid: 'i-tabler-roeid',
    id: 'i-tabler-id-badge-2',
}

// null while loading; on fetch failure fall back to the classic layout so a transient API hiccup can never brick the login page.
const authMethods = ref(null)

onMounted(async () => {
    surfaceIdentityError()

    try {
        authMethods.value = await authService.fetchAuthMethods()
    } catch {
        authMethods.value = {
            password: true,
            magic_link: true,
            magic_link_provision: false,
            providers: [],
            captcha_doors: [],
        }
    }

    // Land on a method that is actually available.
    if (!passwordEnabled.value && magicEnabled.value) {
        method.value = LOGIN_METHOD.MAGIC_LINK
    }
})

const methodsLoading = computed(() => authMethods.value === null)
const passwordEnabled = computed(() => authMethods.value?.password ?? true)
const magicEnabled = computed(() => authMethods.value?.magic_link ?? true)
const magicProvisionEnabled = computed(() => authMethods.value?.magic_link_provision ?? false)

/* ------------------------------------------------------------------ *
 *  Captcha (anti-abuse hook)
 *
 *  Each tab maps to its backend door; the widget only renders when the
 *  deployment guards that door and shipped a site key. Tokens are
 *  single-use, so the widget resets after every submit.
 * ------------------------------------------------------------------ */

const captchaToken = ref('')
const captchaWidget = useTemplateRef('captchaWidget')

const captchaDoor = computed(() => method.value === LOGIN_METHOD.MAGIC_LINK ? 'magic_link' : 'login')
const showCaptcha = computed(() => Boolean(authMethods.value?.captcha_site_key)
    && (authMethods.value?.captcha_doors ?? []).includes(captchaDoor.value))
const hasCredentialForm = computed(() => passwordEnabled.value || magicEnabled.value)
const showTabs = computed(() => passwordEnabled.value && magicEnabled.value)
const hasProviders = computed(() => (authMethods.value?.providers ?? []).length > 0)
const nothingAvailable = computed(() => !hasCredentialForm.value && !hasProviders.value)

const providers = computed(() => (authMethods.value?.providers ?? []).map(provider => ({
    label: t('messages.auth.login.continue_with', {
        provider: t(`messages.settings.security.provider_names.${provider}`),
    }),
    icon: providerIcons[provider] ?? 'i-tabler-id-badge-2',
    // The redirect rides along so the OIDC callback can land the user
    // back on the page they originally wanted, like the other doors do.
    onClick: () => window.location.assign(typeof route.query.redirect === 'string'
        ? `/auth/${provider}/redirect?redirect=${encodeURIComponent(route.query.redirect)}`
        : `/auth/${provider}/redirect`),
})))

function surfaceIdentityError () {
    const { error, ...rest } = route.query
    const known = ['identity_failed', 'identity_not_linked', 'identity_unavailable']

    if (!error) {
        return
    }

    toast.add({
        title: t('messages.auth.login.failed'),
        description: t(`messages.auth.login.identity_errors.${known.includes(error) ? error : 'identity_failed'}`),
        color: 'error',
    })

    // Strip the outcome from the URL so a refresh does not re-toast.
    router.replace({ query: rest })
}

const fields = computed(() => {
    const emailField = {
        name: 'email',
        type: 'email',
        label: t('messages.auth.fields.email'),
        placeholder: t('messages.auth.fields.email_placeholder'),
        required: true,
        icon: 'i-tabler-mail',
    }
    const passwordField = {
        name: 'password',
        label: t('messages.auth.fields.password'),
        type: 'password',
        placeholder: t('messages.auth.fields.password_placeholder'),
        required: true,
        icon: 'i-tabler-lock',
    }
    const rememberField = {
        name: 'remember',
        label: t('messages.auth.fields.remember_me'),
        type: 'checkbox',
        defaultValue: false,
    }

    switch (method.value) {
        case LOGIN_METHOD.PASSWORD:
            return [emailField, passwordField, rememberField]
        case LOGIN_METHOD.MAGIC_LINK:
            return [emailField]
    }
})

const submitButton = computed(() => method.value === LOGIN_METHOD.MAGIC_LINK
    ? { label: t('messages.auth.magic_link.send') }
    : { label: t('messages.auth.login.continue') },
)

async function onSubmit (payload) {
    loading.value = true
    try {
        switch (method.value) {
            case LOGIN_METHOD.PASSWORD: {
                const { twoFactorRequired } = await authStore.login({
                    ...payload.data,
                    ...(showCaptcha.value ? { captcha_token: captchaToken.value } : {}),
                })

                // The credentials passed but a second factor is pending;
                // the redirect rides along so it survives the extra hop.
                if (twoFactorRequired) {
                    await router.push({
                        path: '/auth/two-factor',
                        query: typeof route.query.redirect === 'string' ? { redirect: route.query.redirect } : {},
                    })
                    break
                }

                await router.push(resolveRedirectTarget(route.query.redirect))
                break
            }
            case LOGIN_METHOD.MAGIC_LINK: {
                // The redirect query rides along so the emailed link brings
                // the user back to the page they originally wanted.
                await authStore.requestMagicLink({
                    email: payload.data.email,
                    redirect: typeof route.query.redirect === 'string' ? route.query.redirect : undefined,
                    captcha_token: showCaptcha.value ? captchaToken.value : undefined,
                })
                toast.add({
                    title: t('messages.auth.magic_link.sent_title'),
                    description: t('messages.auth.magic_link.sent_description'),
                    icon: 'i-tabler-mail-forward',
                    color: 'success',
                })
                break
            }
        }
    } catch (error) {
        toast.add({
            title: error.isNetworkError ? t('messages.common.errors.network_title') : t('messages.auth.login.failed'),
            description: error.detail ?? (error.isNetworkError
                ? t('messages.common.errors.network_description')
                : t('messages.auth.login.invalid_credentials')),
            color: 'error',
        })
    } finally {
        // Captcha tokens are single-use: whatever the outcome, a retry (or a second magic-link send) needs a fresh challenge.
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
                        <USkeleton class="h-9 w-full"/>
                        <div class="flex items-center gap-3">
                            <USkeleton class="h-px grow"/>
                            <USkeleton class="h-4 w-28 shrink-0"/>
                            <USkeleton class="h-px grow"/>
                        </div>
                        <div class="space-y-2">
                            <USkeleton class="h-4 w-16"/>
                            <USkeleton class="h-9 w-full"/>
                        </div>
                        <div class="space-y-2">
                            <USkeleton class="h-4 w-20"/>
                            <USkeleton class="h-9 w-full"/>
                        </div>
                        <div class="flex items-center gap-2">
                            <USkeleton class="size-4 rounded"/>
                            <USkeleton class="h-4 w-28"/>
                        </div>
                        <USkeleton class="h-9 w-full"/>
                    </div>

                    <UAlert
                        v-else-if="nothingAvailable"
                        :description="t('messages.auth.login.unavailable')"
                        color="warning"
                        variant="subtle"
                        icon="i-tabler-alert-triangle"
                    />

                    <div v-else-if="!hasCredentialForm" class="flex flex-col gap-4">
                        <h1 class="text-xl font-semibold text-center">
                            {{ t('messages.auth.login.welcome') }}
                        </h1>

                        <LoginIllustration class="w-44 h-auto mx-auto text-highlighted"/>

                        <p class="text-sm text-muted text-center">
                            {{ t('messages.auth.login.with_provider') }}
                        </p>

                        <UButton
                            v-for="provider in providers"
                            :key="provider.label"
                            :label="provider.label"
                            :icon="provider.icon"
                            color="neutral"
                            variant="subtle"
                            block
                            @click="provider.onClick()"
                        />
                    </div>

                    <UAuthForm
                        v-else
                        :title="t('messages.auth.login.welcome')"
                        :ui="{ title: '', description: 'mb-2' }"
                        :schema="schema"
                        :providers="providers"
                        :fields="fields"
                        :submit="submitButton"
                        :loading="loading"
                        @submit="onSubmit"
                    >
                        <template #description>
                            <LoginIllustration class="w-44 h-auto mx-auto my-4 text-highlighted"/>
                            <span v-if="hasProviders" class="block">
                            {{ t('messages.auth.login.with_provider') }}
                        </span>
                        </template>

                        <template #separator>
                            <USeparator
                                :label="hasProviders
                                ? t('messages.auth.login.with_credentials')
                                : t('messages.auth.login.credentials')"
                            />

                            <UTabs
                                v-if="showTabs"
                                v-model="method"
                                :items="methodTabs"
                                :content="false"
                                :ui="{ trigger: 'grow' }"
                                variant="link"
                                class="gap-4 w-full"
                            />
                        </template>

                        <template #password-hint>
                            <ULink
                                to="/auth/forgot-password"
                                class="text-muted hover:text-highlighted transition-colors"
                                tabindex="-1"
                            >
                                {{ t('messages.auth.forgot_password.label') }}
                            </ULink>
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

                        <template #footer>
                            <p
                                v-if="method === LOGIN_METHOD.MAGIC_LINK && magicProvisionEnabled"
                                class="text-sm text-muted text-center w-full"
                            >
                                {{ t('messages.auth.magic_link.provision_hint') }}
                            </p>
                        </template>
                    </UAuthForm>
                </UPageCard>
            </div>
        </UContainer>
    </UMain>
</template>
