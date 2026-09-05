import { setupLayouts } from 'virtual:meta-layouts'
import { createRouter, createWebHistory } from 'vue-router'
import { routes } from 'vue-router/auto-routes'
import { additionalRedirects, additionalRoutes } from '@/plugins/1.router/additional-routes.js'
import { setupGuards } from '@/plugins/1.router/guards.js'

function recursiveLayouts (route) {
    if (route.children) {
        for (let i = 0; i < route.children.length; i++) {
            route.children[i] = recursiveLayouts(route.children[i])
        }
        return route
    }

    return setupLayouts([route])[0]
}

const router = createRouter({
    // Not import.meta.env.BASE_URL: that is Vite's asset base ('/build/' once built), while the SPA is always served from the domain root.
    history: createWebHistory('/'),
    scrollBehavior (to) {
        if (to.hash) {
            return { el: to.hash, behavior: 'smooth', top: 60 }
        }
        return { top: 0 }
    },
    routes: [
        ...additionalRedirects,
        ...[
            ...routes,
            ...additionalRoutes,
        ].map(route => recursiveLayouts(route)),
    ],
})

setupGuards(router)

export { router }

export default function (app) {
    app.use(router)
}
