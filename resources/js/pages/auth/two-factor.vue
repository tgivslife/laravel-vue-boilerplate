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

const toast = useAppToast()
const authStore = useAuthStore()
const router = useRouter()
const route = useRoute()

/* ------------------------------------------------------------------ *
 *  Two-factor challenge
 *
 *  The pending login lives server-side in the session (parked by the
 *  login endpoint); this page only collects the second factor. The
 *  recovery toggle swaps the 6-digit pin for a recovery-code input.
 *  A 410 means the pending login expired or was never parked - the
 *  only fix is signing in again, so the user is sent back with the
 *  redirect query intact.
 * ------------------------------------------------------------------ */

const code = ref([])
const recoveryCode = ref('')
const useRecovery = ref(false)
const submitting = ref(false)
const codePin = useTemplateRef('codePin')

// Clearing the pin leaves focus on the last cell; typing again should start from the first one.
function resetCode () {
    code.value = []

    nextTick(() => {
        codePin.value?.$el?.querySelector('input')?.focus()
    })
}

const canSubmit = computed(() => useRecovery.value
    ? recoveryCode.value.trim().length > 0
    : code.value.join('').length === 6)

function toggleRecovery () {
    useRecovery.value = !useRecovery.value
    code.value = []
    recoveryCode.value = ''
}

async function submit () {
    if (!canSubmit.value || submitting.value) {
        return
    }

    submitting.value = true
    try {
        await authStore.challengeTwoFactor(useRecovery.value
            ? { recovery_code: recoveryCode.value.trim() }
            : { code: code.value.join('') })

        await router.push(resolveRedirectTarget(route.query.redirect))
    } catch (error) {
        if (error.status === 410) {
            toast.add({
                title: t('messages.auth.two_factor.expired_title'),
                description: error.detail ?? t('messages.auth.two_factor.expired_description'),
                color: 'error',
            })

            await router.push({ path: '/auth/login', query: route.query })
            return
        }

        toast.add({
            title: error.isNetworkError ? t('messages.common.errors.network_title') : t('messages.auth.two_factor.failed'),
            description: error.detail ?? (error.isNetworkError
                ? t('messages.common.errors.network_description')
                : t('messages.auth.two_factor.invalid_code')),
            color: 'error',
        })

        resetCode()
    } finally {
        submitting.value = false
    }
}
</script>

<template>
    <UHeader :toggle="false">
        <template #left>
            <UButton
                :label="t('messages.auth.two_factor.back_to_login')"
                color="neutral"
                :to="{ path: '/auth/login', query: route.query }"
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
                    <div class="flex flex-col gap-4">
                        <h1 class="text-xl font-semibold text-center">
                            {{ t('messages.auth.two_factor.title') }}
                        </h1>

                        <TwoFactorIllustration class="w-56 h-auto mx-auto my-4 text-highlighted"/>

                        <p class="text-sm text-muted text-center">
                            {{
                                useRecovery
                                    ? t('messages.auth.two_factor.recovery_description')
                                    : t('messages.auth.two_factor.description')
                            }}
                        </p>

                        <div v-if="!useRecovery" class="flex justify-center">
                            <UPinInput
                                ref="codePin"
                                v-model="code"
                                :length="6"
                                otp
                                type="number"
                                autofocus
                                :disabled="submitting"
                                @complete="submit"
                            />
                        </div>

                        <UInput
                            v-else
                            v-model="recoveryCode"
                            :placeholder="t('messages.auth.two_factor.recovery_placeholder')"
                            icon="i-tabler-lifebuoy"
                            autofocus
                            :disabled="submitting"
                            @keyup.enter="submit"
                        />

                        <UButton
                            :label="t('messages.auth.two_factor.verify')"
                            :loading="submitting"
                            :disabled="!canSubmit"
                            block
                            @click="submit()"
                        />

                        <UButton
                            :label="useRecovery
                                ? t('messages.auth.two_factor.use_totp')
                                : t('messages.auth.two_factor.use_recovery')"
                            color="neutral"
                            variant="link"
                            block
                            @click="toggleRecovery()"
                        />
                    </div>
                </UPageCard>
            </div>
        </UContainer>
    </UMain>
</template>
