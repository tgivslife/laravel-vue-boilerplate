import * as v from 'valibot'
import { useI18n } from 'vue-i18n'

/**
 * Mirrors the server's password policy (config/security.php `password_policy` +
 * NotCommonPassword / NotPersonalPassword) for instant client-side feedback.
 *
 * The server stays authoritative: anything the mirror cannot know
 * (the common-passwords denylist) arrives as a 422 mapped onto the field.
 * Keep PASSWORD_MIN_LENGTH in sync with `security.password_policy.min_length`.
 */
export const PASSWORD_MIN_LENGTH = 12

/** Terms shorter than this occur inside too many unrelated words to reject on (matches NotPersonalPassword). */
const MIN_TERM_LENGTH = 4

/**
 * The lowercase identity fragments a password must not contain,
 * from whatever identity is at hand (typed email, signed-in user).
 *
 * @param {{ email?: string, firstName?: string, lastName?: string }} identity
 * @returns {string[]}
 */
export function identityTerms ({ email = '', firstName = '', lastName = '' } = {}) {
    const localPart = String(email).split('@')[0] ?? ''

    return [firstName, lastName, localPart, ...localPart.split(/[._+-]+/)]
        .map(term => String(term).trim().toLowerCase())
        .filter(term => term.length >= MIN_TERM_LENGTH)
}

/**
 * Valibot builders for the password forms (reset, forced change, settings).
 *
 * Call inside a computed so locale switches rebuild the messages:
 *
 *   const schema = computed(() => passwordFormSchema({
 *       terms: () => identityTerms({ email: email.value }),
 *       requireCurrent: hasPassword.value,
 *   }))
 */
export function usePasswordSchema () {
    const { t } = useI18n()

    const passwordPipe = (terms = () => []) => v.pipe(
        v.string(t('messages.auth.validation.invalid_password')),
        v.minLength(PASSWORD_MIN_LENGTH, t('messages.auth.reset_password.password_min', { min: PASSWORD_MIN_LENGTH })),
        v.maxLength(255),
        v.check(password => {
            const lowered = password.toLowerCase()

            return !terms().some(term => lowered.includes(term))
        }, t('messages.auth.validation.password_personal')),
    )

    const passwordFormSchema = ({ terms = () => [], requireCurrent = false, extraShape = {} } = {}) => {
        const shape = {
            password: passwordPipe(terms),
            password_confirmation: v.string(t('messages.auth.validation.invalid_password')),
            ...extraShape,
        }

        if (requireCurrent) {
            shape.current_password = v.pipe(
                v.string(t('messages.settings.security.current_password_required')),
                v.minLength(1, t('messages.settings.security.current_password_required')),
            )
        }

        return v.pipe(
            v.object(shape),
            v.forward(
                v.partialCheck(
                    [['password'], ['password_confirmation']],
                    input => input.password === input.password_confirmation,
                    t('messages.auth.reset_password.password_mismatch'),
                ),
                ['password_confirmation'],
            ),
        )
    }

    return { passwordPipe, passwordFormSchema }
}
