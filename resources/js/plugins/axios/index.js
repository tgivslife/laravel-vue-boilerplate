import axios from 'axios'
import { getI18n } from '@/plugins/i18n/index.js'

const api = axios.create({
    baseURL: import.meta.env.VITE_API_URL ?? '',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
})

api.interceptors.request.use(config => {
    config.headers['Accept-Language'] = getI18n().global.locale.value
    return config
})

api.interceptors.response.use(
    response => response,
    async error => {
        const { config, response } = error

        if (response?.status === 419 && config && !config._retried) {
            config._retried = true
            await api.get('/sanctum/csrf-cookie')
            return api(config)
        }

        if (response?.status === 401) {
            const { useAuthStore } = await import('@/stores/AuthStore.js')
            const authStore = useAuthStore()

            /* A 401 while the store believed a session existed means the session died server-side
             * (expiry, auth:flush-sessions, revocation from another device). The router guard only
             * redirects on navigation, so without a push here the user would stay on a page whose
             * data calls all fail. Guest bootstraps and failed sign-in attempts never enter this
             * branch - the store was not logged in when their 401 arrived. */
            const sessionDied = authStore.isLoggedIn

            authStore.clearSession()

            if (sessionDied) {
                const { router } = await import('@/plugins/1.router/index.js')
                const current = router.currentRoute.value

                if (!current.path.startsWith('/auth')) {
                    const { t } = getI18n().global
                    useAppToast().add({
                        title: t('messages.auth.session_expired_title'),
                        description: t('messages.auth.session_expired_description'),
                        color: 'warning',
                    })

                    router.push({ path: '/auth/login', query: { redirect: current.fullPath } })
                }
            }
        }

        return Promise.reject(error)
    },
)

export { api }

export default function (app) {
    // no app.use() needed, axios doesn't register as a Vue plugin
}
