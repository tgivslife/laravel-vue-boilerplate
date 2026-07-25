<script setup>
import { useAuthStore } from '@/stores/AuthStore.js'
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

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const toast = useAppToast()

/* ------------------------------------------------------------------ *
 *  View state
 *
 *  This page is deliberately inert on load: the token is only spent
 *  when the user clicks continue, so mail scanners and prefetchers
 *  that open the emailed link cannot burn it.
 * ------------------------------------------------------------------ */

const token = computed(() => typeof route.query.token === 'string' ? route.query.token : '')

// Cosmetic markers carried by provisioning links (the click creates the account) and admin invitations (first sign-in);
// consumption ignores them, so tampering changes copy only.
const isSignup = computed(() => route.query.signup === '1')
const isInvite = computed(() => route.query.invite === '1')

const loading = ref(false)
const errorMessage = ref(token.value ? '' : t('messages.auth.magic_link.invalid'))

async function confirm () {
    loading.value = true
    errorMessage.value = ''
    try {
        const { twoFactorRequired, provisioned } = await authStore.loginWithMagicLink(token.value)

        // The link proved the mailbox but a second factor is pending; the
        // redirect rides along so it survives the extra hop.
        if (twoFactorRequired) {
            await router.replace({
                path: '/auth/two-factor',
                query: typeof route.query.redirect === 'string' ? { redirect: route.query.redirect } : {},
            })
            return
        }

        // This login just created the account (self-provisioning).
        if (provisioned) {
            toast.add({
                title: t('messages.auth.magic_link.welcome_title'),
                description: t('messages.auth.magic_link.welcome_description'),
                icon: 'i-tabler-confetti',
                color: 'success',
            })
        }

        await router.replace(resolveRedirectTarget(route.query.redirect))
    } catch (error) {
        errorMessage.value = error.isNetworkError
            ? t('messages.common.errors.network_description')
            : (error.detail ?? t('messages.auth.magic_link.invalid'))
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

                    <div class="flex flex-col gap-4 text-center">
                        <h1 class="text-xl font-semibold">
                            {{
                                t(isInvite
                                    ? 'messages.auth.magic_link.verify_title_invite'
                                    : 'messages.auth.magic_link.verify_title')
                            }}
                        </h1>

                        <MagicLinkIllustration class="w-44 h-auto mx-auto text-highlighted"/>

                        <template v-if="!errorMessage">
                            <p class="text-muted text-sm mt-2 mb-8">
                                {{
                                    t(isInvite
                                        ? 'messages.auth.magic_link.verify_description_invite'
                                        : (isSignup
                                            ? 'messages.auth.magic_link.verify_description_signup'
                                            : 'messages.auth.magic_link.verify_description'))
                                }}
                            </p>

                            <UButton
                                :label="t(isInvite
                                    ? 'messages.auth.magic_link.continue_invite'
                                    : (isSignup
                                        ? 'messages.auth.magic_link.continue_signup'
                                        : 'messages.auth.magic_link.continue'))"
                                :loading="loading || authStore.isLoading"
                                icon="i-tabler-wand"
                                size="lg"
                                block
                                @click="confirm"
                            />
                        </template>

                        <template v-else>
                            <UAlert
                                :description="errorMessage"
                                color="error"
                                variant="subtle"
                                icon="i-tabler-alert-triangle"
                            />

                            <UButton
                                :label="t('messages.auth.magic_link.back_to_login')"
                                color="neutral"
                                variant="link"
                                to="/auth/login"
                                leading-icon="i-tabler-chevron-left"
                                class="self-center"
                            />
                        </template>
                    </div>
                </UPageCard>
            </div>
        </UContainer>
    </UMain>
</template>
