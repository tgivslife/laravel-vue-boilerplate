<script setup>
import * as v from 'valibot'
import { useAuthStore } from '@/stores/AuthStore.js'
import SettingsService from '@/services/SettingsService.js'
import { useCopyText } from '@/composables/useCopyText.js'
import { identityTerms, usePasswordSchema } from '@/composables/usePasswordSchema.js'
import { formatDate } from '@/utils/datetime.js'

const { t } = useI18n()
const toast = useAppToast()
const authStore = useAuthStore()

const settingsService = new SettingsService()

/* ------------------------------------------------------------------ *
 *  Password change
 *
 *  Passwordless accounts set their first password here: no current
 *  password exists, so the field is hidden and the API skips it too.
 * ------------------------------------------------------------------ */

const hasPassword = computed(() => authStore.user?.has_password !== false)

const passwordState = reactive({
    current_password: '',
    password: '',
    password_confirmation: '',
    revoke_other_sessions: true,
})

const { passwordFormSchema } = usePasswordSchema()

const passwordSchema = computed(() => passwordFormSchema({
    terms: () => identityTerms({
        email: authStore.user?.email,
        firstName: authStore.user?.first_name,
        lastName: authStore.user?.last_name,
    }),
    requireCurrent: hasPassword.value,
    extraShape: { revoke_other_sessions: v.boolean() },
}))

const changingPassword = ref(false)
const passwordForm = useTemplateRef('passwordForm')

async function onPasswordSubmit () {
    changingPassword.value = true
    try {
        await settingsService.updatePassword(hasPassword.value
            ? { ...passwordState }
            : {
                password: passwordState.password,
                password_confirmation: passwordState.password_confirmation,
                revoke_other_sessions: passwordState.revoke_other_sessions,
            })

        passwordState.current_password = ''
        passwordState.password = ''
        passwordState.password_confirmation = ''

        // has_password flips for passwordless accounts setting their first one.
        await authStore.fetchUser()

        toast.add({
            title: t('messages.settings.security.password_updated_title'),
            description: t('messages.settings.security.password_updated_description'),
            color: 'success',
        })
    } catch (error) {
        // Server-side validation lands on the fields it names (e.g. a
        // wrong current password), not in a generic toast.
        if (error.isValidationError && error.errors?.length) {
            passwordForm.value?.setErrors(error.errors.map(fieldError => ({
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
        changingPassword.value = false
    }
}

/* ------------------------------------------------------------------ *
 *  Connected identities
 *
 *  Linking is a browser OIDC flow (full-page redirect with the
 *  `connect` intent); the callback lands back on this tab with a
 *  `linked` or `identity_error` query that becomes a toast.
 * ------------------------------------------------------------------ */

const route = useRoute()
const router = useRouter()

const providerMeta = {
    roeid: { icon: 'i-tabler-roeid' },
    id: { icon: 'i-tabler-id-badge-2' },
}

const identities = ref([])
const identitiesLoading = ref(true)

/* Deployment switch: with identity providers off the API 404s both
 * endpoints, so the whole card disappears rather than listing dead doors. */
const identityProvidersAvailable = computed(() => authStore.user?.identity_providers_available !== false)

async function loadIdentities () {
    // A trapped account (two-factor mandate unmet) can only reach the
    // enrollment endpoints, and a switched-off feature has no endpoints
    // at all; fetching identities would just toast an error.
    if (!identityProvidersAvailable.value || authStore.user?.two_factor_enrollment_required) {
        identitiesLoading.value = false
        return
    }

    identitiesLoading.value = true
    try {
        const data = await settingsService.fetchIdentities()
        identities.value = data.identities
    } catch (error) {
        toast.add({
            title: t('messages.settings.security.identities_failed'),
            description: error.detail ?? t('messages.common.errors.network_description'),
            color: 'error',
        })
    } finally {
        identitiesLoading.value = false
    }
}

onMounted(() => {
    loadIdentities()
    surfaceLinkOutcome()
})

function providerName (provider) {
    return t(`messages.settings.security.provider_names.${provider}`)
}

function connect (provider) {
    window.location.assign(`/auth/${provider}/redirect?intent=connect`)
}

function surfaceLinkOutcome () {
    const { linked, identity_error: identityError, ...rest } = route.query

    if (!linked && !identityError) {
        return
    }

    if (linked) {
        toast.add({
            title: t('messages.settings.security.link_success', { provider: providerName(linked) }),
            color: 'success',
        })
    } else {
        const known = ['taken', 'already_linked', 'failed', 'impersonating']
        const key = known.includes(identityError) ? identityError : 'failed'

        toast.add({
            title: t('messages.settings.security.link_failed'),
            description: t(`messages.settings.security.link_errors.${key}`),
            color: 'error',
        })
    }

    // Strip the outcome from the URL so a refresh does not re-toast.
    router.replace({ query: rest })
}

/* Disconnect (password-confirmed for password accounts) */

const unlinkProvider = ref(null)
const unlinkPassword = ref('')
const unlinking = ref(false)

function submitUnlinkModal () {
    if ((hasPassword.value && unlinkPassword.value === '') || unlinking.value) {
        return
    }

    unlink()
}

async function unlink () {
    unlinking.value = true
    try {
        await settingsService.unlinkIdentity(
            unlinkProvider.value,
            hasPassword.value ? { password: unlinkPassword.value } : {},
        )
        unlinkProvider.value = null
        unlinkPassword.value = ''
        toast.add({
            title: t('messages.settings.security.unlinked_title'),
            color: 'success',
        })
        await loadIdentities()
    } catch (error) {
        toast.add({
            title: t('messages.settings.security.link_failed'),
            // Field-level validation messages ("The password is incorrect.") beat the envelope's generic "the data was invalid" detail.
            description: error.errors?.[0]?.detail ?? error.detail ?? t('messages.common.errors.network_description'),
            color: 'error',
        })
    } finally {
        unlinking.value = false
    }
}

function formatIdentityDate (iso) {
    return formatDate(iso)
}

/* ------------------------------------------------------------------ *
 *  Two-factor authentication
 *
 *  Enable runs a wizard: a password-confirmed start mints the QR and
 *  manual key, a working code activates the factor, and the recovery
 *  codes are shown exactly once. Disable and regenerate reuse the same
 *  password-confirm modal; passwordless accounts skip the modal - the
 *  signed-in session itself is the proof of control.
 * ------------------------------------------------------------------ */

/* Deployment switch: with the feature off the API 404s every two-factor
 * endpoint, so the whole card disappears rather than offering dead doors. */
const twoFactorAvailable = computed(() => authStore.user?.two_factor_available !== false)

const twoFactorEnabled = computed(() => authStore.user?.two_factor_enabled === true)

const enrollment = ref(null)

/* Rendered via an <img> data URI instead of v-html so the server-built SVG
 * never becomes an HTML-injection sink (BaconQrCode output is ASCII-only, so btoa is safe). */
const enrollmentQrUri = computed(() =>
    enrollment.value ? `data:image/svg+xml;base64,${btoa(enrollment.value.qr_svg)}` : null
)

const enrollmentCode = ref([])
const confirmingEnrollment = ref(false)
const recoveryCodes = ref(null)
const enrollmentPin = useTemplateRef('enrollmentPin')

// Clearing the pin leaves focus on the last cell; typing again should start from the first one.
function resetEnrollmentCode () {
    enrollmentCode.value = []

    nextTick(() => {
        enrollmentPin.value?.$el?.querySelector('input')?.focus()
    })
}

const { copied: secretCopied, copy: copySecret } = useCopyText()
const { copied: codesCopied, copy: copyCodes } = useCopyText()

// Password-confirm modal, shared by the three actions.
// The action and the open flag are separate refs on purpose: the action survives the close,
// so the title cannot flash another action's text while the dialog animates out.
const twoFactorAction = ref('enable')
const twoFactorModalOpen = ref(false)
const twoFactorPassword = ref('')
const twoFactorBusy = ref(false)

function requestTwoFactorAction (action) {
    recoveryCodes.value = null
    twoFactorAction.value = action

    if (!hasPassword.value) {
        runTwoFactorAction(action, {})
        return
    }

    twoFactorPassword.value = ''
    twoFactorModalOpen.value = true
}

function submitTwoFactorModal () {
    if (twoFactorPassword.value === '' || twoFactorBusy.value) {
        return
    }

    runTwoFactorAction(twoFactorAction.value, { password: twoFactorPassword.value })
}

async function runTwoFactorAction (action, confirmation) {
    twoFactorBusy.value = true
    try {
        if (action === 'enable') {
            enrollment.value = await settingsService.startTwoFactorEnrollment(confirmation)
            enrollmentCode.value = []
        } else if (action === 'disable') {
            await settingsService.disableTwoFactor(confirmation)
            enrollment.value = null
            await authStore.fetchUser()
            toast.add({
                title: t('messages.settings.security.two_factor_disabled_title'),
                color: 'success',
            })
        } else {
            const data = await settingsService.regenerateTwoFactorRecoveryCodes(confirmation)
            recoveryCodes.value = data.recovery_codes
        }

        twoFactorModalOpen.value = false
        twoFactorPassword.value = ''
    } catch (error) {
        toast.add({
            title: t('messages.settings.security.two_factor_failed'),
            // Field-level validation messages ("The password is incorrect.")
            // beat the envelope's generic "the data was invalid" detail.
            description: error.errors?.[0]?.detail ?? error.detail ?? t('messages.common.errors.network_description'),
            color: 'error',
        })
    } finally {
        twoFactorBusy.value = false
    }
}

async function confirmEnrollment () {
    if (enrollmentCode.value.join('').length !== 6 || confirmingEnrollment.value) {
        return
    }

    confirmingEnrollment.value = true
    try {
        const data = await settingsService.confirmTwoFactorEnrollment(enrollmentCode.value.join(''))

        recoveryCodes.value = data.recovery_codes
        enrollment.value = null
        await authStore.fetchUser()

        // A previously trapped account skipped the identities fetch; the mandate is satisfied now, so backfill it.
        if (identities.value.length === 0) {
            loadIdentities()
        }

        toast.add({
            title: t('messages.settings.security.two_factor_enabled_title'),
            color: 'success',
        })
    } catch (error) {
        toast.add({
            title: t('messages.settings.security.two_factor_failed'),
            description: error.detail ?? t('messages.common.errors.network_description'),
            color: 'error',
        })

        resetEnrollmentCode()
    } finally {
        confirmingEnrollment.value = false
    }
}

function cancelEnrollment () {
    // The pending secret is inert server-side (unconfirmed secrets never challenge anyone); abandoning it client-side is enough.
    enrollment.value = null
    enrollmentCode.value = []
}

function downloadRecoveryCodes () {
    const blob = new Blob([recoveryCodes.value.join('\n') + '\n'], { type: 'text/plain' })

    const url = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = 'recovery-codes.txt'
    anchor.click()
    URL.revokeObjectURL(url)
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <UAlert
            v-if="authStore.user?.two_factor_enrollment_required"
            :title="t('messages.settings.security.two_factor_required_title')"
            :description="t('messages.settings.security.two_factor_required_description')"
            color="warning"
            variant="subtle"
            icon="i-tabler-shield-exclamation"
        />

        <UPageCard
            :title="t('messages.settings.security.password_title')"
            :description="hasPassword
                ? t('messages.settings.security.password_description')
                : t('messages.settings.security.password_set_description')"
            variant="subtle"
        >
            <UForm
                ref="passwordForm"
                :schema="passwordSchema"
                :state="passwordState"
                class="flex flex-col gap-4"
                @submit="onPasswordSubmit"
            >
                <UFormField
                    v-if="hasPassword"
                    :label="t('messages.settings.security.current_password')"
                    name="current_password"
                    class="max-w-md"
                    required
                >
                    <PasswordInput v-model="passwordState.current_password" class="w-full"/>
                </UFormField>

                <UFormField
                    :label="t('messages.settings.security.new_password')"
                    name="password"
                    class="max-w-md"
                    required
                >
                    <PasswordInput v-model="passwordState.password" class="w-full"/>
                </UFormField>

                <UFormField
                    :label="t('messages.settings.security.confirm_password')"
                    name="password_confirmation"
                    class="max-w-md"
                    required
                >
                    <PasswordInput v-model="passwordState.password_confirmation" class="w-full"/>
                </UFormField>

                <UCheckbox
                    v-model="passwordState.revoke_other_sessions"
                    :label="t('messages.settings.security.revoke_other_sessions')"
                    :description="t('messages.settings.security.revoke_other_sessions_description')"
                />

                <div>
                    <UButton
                        type="submit"
                        :label="hasPassword
                            ? t('messages.settings.security.change')
                            : t('messages.settings.security.set_password')"
                        :loading="changingPassword"
                    />
                </div>
            </UForm>
        </UPageCard>

        <UPageCard
            v-if="identityProvidersAvailable"
            :title="t('messages.settings.security.providers_title')"
            :description="t('messages.settings.security.providers_description')"
            variant="subtle"
            :ui="{ body: 'w-full' }"
        >
            <ListSkeleton v-if="identitiesLoading" :rows="2" class="max-w-3xl"/>

            <div v-else class="flex flex-col gap-4 max-w-3xl">
                <div
                    v-for="identity in identities"
                    :key="identity.provider"
                    class="flex items-center justify-between gap-4"
                >
                    <div class="flex items-center gap-3 min-w-0">
                        <UIcon
                            :name="providerMeta[identity.provider]?.icon ?? 'i-tabler-id-badge-2'"
                            class="size-5 shrink-0 text-muted"
                        />

                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-highlighted">
                                    {{ providerName(identity.provider) }}
                                </span>
                                <UBadge
                                    v-if="identity.linked"
                                    :label="t('messages.settings.security.connected')"
                                    color="success"
                                    variant="subtle"
                                    size="sm"
                                />
                            </div>
                            <p class="text-xs text-muted truncate">
                                <template v-if="identity.linked">
                                    {{
                                        t('messages.settings.security.linked_at', { date: formatIdentityDate(identity.linked_at) })
                                    }}
                                    <template v-if="identity.last_used_at">
                                        · {{
                                            t('messages.settings.security.identity_last_used', { date: formatIdentityDate(identity.last_used_at) })
                                        }}
                                    </template>
                                </template>
                                <template v-else-if="!identity.available">
                                    {{ t('messages.settings.security.provider_unavailable') }}
                                </template>
                                <template v-else>
                                    {{ t('messages.settings.security.not_connected') }}
                                </template>
                            </p>
                        </div>
                    </div>

                    <UButton
                        v-if="identity.linked"
                        :label="t('messages.settings.security.disconnect')"
                        color="error"
                        variant="soft"
                        size="sm"
                        @click="unlinkProvider = identity.provider"
                    />
                    <UButton
                        v-else
                        :label="t('messages.settings.security.connect')"
                        color="neutral"
                        variant="subtle"
                        size="sm"
                        :disabled="!identity.available || authStore.isImpersonating"
                        @click="connect(identity.provider)"
                    />
                </div>
            </div>
        </UPageCard>

        <UPageCard
            v-if="twoFactorAvailable"
            :title="t('messages.settings.security.two_factor_title')"
            :description="t('messages.settings.security.two_factor_description')"
            variant="subtle"
            :ui="{ body: 'w-full' }"
        >
            <div class="flex flex-col gap-4 max-w-3xl">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3 min-w-0">
                        <UIcon name="i-tabler-shield-lock" class="size-5 shrink-0 text-muted"/>

                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-highlighted">
                                    {{ t('messages.settings.security.two_factor_totp') }}
                                </span>
                                <UBadge
                                    v-if="twoFactorEnabled"
                                    :label="t('messages.settings.security.two_factor_on')"
                                    color="success"
                                    variant="subtle"
                                    size="sm"
                                />
                            </div>
                            <p class="text-xs text-muted">
                                {{
                                    twoFactorEnabled
                                        ? t('messages.settings.security.two_factor_on_note')
                                        : t('messages.settings.security.two_factor_off_note')
                                }}
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <template v-if="twoFactorEnabled">
                            <UButton
                                :label="t('messages.settings.security.two_factor_regenerate')"
                                color="neutral"
                                variant="subtle"
                                size="sm"
                                @click="requestTwoFactorAction('regenerate')"
                            />
                            <UButton
                                :label="t('messages.settings.security.two_factor_disable')"
                                color="error"
                                variant="soft"
                                size="sm"
                                @click="requestTwoFactorAction('disable')"
                            />
                        </template>
                        <UButton
                            v-else-if="!enrollment"
                            :label="t('messages.settings.security.two_factor_enable')"
                            size="sm"
                            @click="requestTwoFactorAction('enable')"
                        />
                    </div>
                </div>

                <div
                    v-if="enrollment && !twoFactorEnabled"
                    class="flex flex-col gap-4 border border-default rounded-lg p-4"
                >
                    <p class="text-sm text-muted">
                        {{ t('messages.settings.security.two_factor_scan') }}
                    </p>

                    <div class="flex flex-col sm:flex-row items-center gap-4">
                        <!-- The SVG is black-on-transparent; the white chip keeps it scannable in dark mode. -->
                        <img
                            :src="enrollmentQrUri"
                            alt=""
                            class="p-2 bg-white rounded-lg w-fit shrink-0"
                        >

                        <div class="flex flex-col gap-1 min-w-0">
                            <span class="text-xs text-muted">
                                {{ t('messages.settings.security.two_factor_manual') }}
                            </span>
                            <div class="flex items-center gap-2">
                                <code class="text-xs font-mono text-highlighted break-all">{{
                                        enrollment.secret
                                    }}</code>
                                <UButton
                                    :icon="secretCopied ? 'i-tabler-check' : 'i-tabler-copy'"
                                    color="neutral"
                                    variant="ghost"
                                    size="xs"
                                    @click="copySecret(enrollment.secret)"
                                />
                            </div>
                        </div>
                    </div>

                    <USeparator/>

                    <p class="text-sm text-muted">
                        {{ t('messages.settings.security.two_factor_enter_code') }}
                    </p>

                    <div class="flex flex-wrap items-center gap-3">
                        <UPinInput
                            ref="enrollmentPin"
                            v-model="enrollmentCode"
                            :length="6"
                            otp
                            type="number"
                            :disabled="confirmingEnrollment"
                            @complete="confirmEnrollment"
                        />
                        <UButton
                            :label="t('messages.settings.security.two_factor_confirm')"
                            :loading="confirmingEnrollment"
                            :disabled="enrollmentCode.join('').length !== 6"
                            @click="confirmEnrollment()"
                        />
                        <UButton
                            :label="t('messages.settings.sessions.cancel')"
                            color="neutral"
                            variant="ghost"
                            @click="cancelEnrollment()"
                        />
                    </div>
                </div>

                <div
                    v-if="recoveryCodes"
                    class="flex flex-col gap-3 border border-default rounded-lg p-4"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-medium text-highlighted">
                            {{ t('messages.settings.security.two_factor_codes_title') }}
                        </span>
                        <div class="flex items-center gap-2">
                            <UButton
                                :icon="codesCopied ? 'i-tabler-check' : 'i-tabler-copy'"
                                :label="t('messages.settings.security.two_factor_copy_codes')"
                                color="neutral"
                                variant="subtle"
                                size="xs"
                                @click="copyCodes(recoveryCodes.join('\n'))"
                            />
                            <UButton
                                icon="i-tabler-download"
                                :label="t('messages.settings.security.two_factor_download_codes')"
                                color="neutral"
                                variant="subtle"
                                size="xs"
                                @click="downloadRecoveryCodes()"
                            />
                        </div>
                    </div>

                    <p class="text-xs text-muted">
                        {{ t('messages.settings.security.two_factor_codes_note') }}
                    </p>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <code
                            v-for="recoveryCode in recoveryCodes"
                            :key="recoveryCode"
                            class="text-xs font-mono text-highlighted"
                        >{{ recoveryCode }}</code>
                    </div>
                </div>
            </div>
        </UPageCard>

        <UModal
            :open="unlinkProvider !== null"
            :title="t('messages.settings.security.disconnect_title', {
                provider: unlinkProvider ? providerName(unlinkProvider) : ''
            })"
            :description="t('messages.settings.security.disconnect_description')"
            @update:open="(value) => { if (!value) unlinkProvider = null }"
        >
            <template #body>
                <UFormField
                    v-if="hasPassword"
                    :label="t('messages.settings.security.current_password')"
                >
                    <PasswordInput
                        v-model="unlinkPassword"
                        class="w-full"
                        :disabled="unlinking"
                        @keyup.enter="submitUnlinkModal()"
                    />
                </UFormField>
                <p v-else class="text-sm text-muted">
                    {{ t('messages.settings.security.disconnect_passwordless_note') }}
                </p>
            </template>

            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton
                        :label="t('messages.settings.sessions.cancel')"
                        color="neutral"
                        variant="ghost"
                        @click="unlinkProvider = null"
                    />
                    <UButton
                        :label="t('messages.settings.security.disconnect')"
                        color="error"
                        :loading="unlinking"
                        :disabled="hasPassword && unlinkPassword === ''"
                        @click="submitUnlinkModal()"
                    />
                </div>
            </template>
        </UModal>

        <UModal
            :open="twoFactorModalOpen"
            :title="t(`messages.settings.security.two_factor_confirm_${twoFactorAction}`)"
            :description="t('messages.settings.security.two_factor_confirm_description')"
            @update:open="(value) => { if (!value) twoFactorModalOpen = false }"
        >
            <template #body>
                <UFormField :label="t('messages.settings.security.current_password')">
                    <PasswordInput
                        v-model="twoFactorPassword"
                        class="w-full"
                        :disabled="twoFactorBusy"
                        @keyup.enter="submitTwoFactorModal()"
                    />
                </UFormField>
            </template>

            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton
                        :label="t('messages.settings.sessions.cancel')"
                        color="neutral"
                        variant="ghost"
                        @click="twoFactorModalOpen = false"
                    />
                    <UButton
                        :label="t('messages.settings.security.two_factor_continue')"
                        :color="twoFactorAction === 'disable' ? 'error' : 'primary'"
                        :loading="twoFactorBusy"
                        :disabled="twoFactorPassword === ''"
                        @click="submitTwoFactorModal()"
                    />
                </div>
            </template>
        </UModal>
    </div>
</template>
