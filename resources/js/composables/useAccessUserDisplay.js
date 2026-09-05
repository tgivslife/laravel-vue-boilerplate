/**
 * Shared presentation helpers for admin user views (list + detail page).
 */
import { formatDate, formatDateTime } from '@/utils/datetime.js'

export function useAccessUserDisplay () {
    const { t } = useI18n()

    function fullName (user) {
        return [user.first_name, user.last_name].filter(Boolean).join(' ') || user.email
    }

    /**
     * Deleted wins over everything (a tombstoned account has no other state that matters), then banned over inactive:
     * a banned account cannot sign in regardless of its active flag, mirroring User::canAuthenticate().
     */
    function statusOf (user) {
        if (user.deleted_at) {
            return { label: t('messages.access.users.status_deleted'), color: 'neutral' }
        }

        if (user.banned_at) {
            return { label: t('messages.access.users.banned'), color: 'error' }
        }

        return user.is_active
            ? { label: t('messages.access.users.active'), color: 'success' }
            : { label: t('messages.access.users.inactive'), color: 'warning' }
    }

    return { fullName, formatDateTime, formatDate, statusOf }
}
