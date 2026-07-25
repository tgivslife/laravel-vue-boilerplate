import { can, canAny } from '@/plugins/rbac'

export function useNavigationItems (onSelect) {
    const { t } = useI18n()

    return computed(() => [
        {
            label: t('messages.app.nav.home'),
            icon: 'i-tabler-home',
            to: '/app',
            onSelect,
        },
        // UI hiding only - the access API authorizes every request itself.
        ...(canAny('users.view', 'roles.view', 'settings.manage') ? [{
            label: t('messages.app.nav.access'),
            icon: 'i-tabler-shield-lock',
            defaultOpen: true,
            type: 'trigger',
            children: [
                ...(can('users.view')
                    ? [{ label: t('messages.access.nav.users'), to: '/app/access/users', onSelect }]
                    : []),
                ...(can('roles.view')
                    ? [
                        { label: t('messages.access.nav.roles'), to: '/app/access/roles', onSelect },
                        { label: t('messages.access.nav.permissions'), to: '/app/access/permissions', onSelect },
                    ]
                    : []),
                ...(can('settings.manage')
                    ? [{ label: t('messages.access.nav.settings'), to: '/app/access/settings', onSelect }]
                    : []),
            ],
        }] : []),
    ])
}
