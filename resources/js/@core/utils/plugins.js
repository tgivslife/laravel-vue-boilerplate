/**
 * This is helper function to register plugins like a nuxt
 * To register a plugin just export a const function `defineVuePlugin` that takes `app` as argument and call `app.use`
 * For Scanning plugins it will include all files in `js/plugins` and `js/plugins/**\/index.js`
 *
 *
 * @param {App} app Vue app instance
 *
 * @example
 * ```ts
 * // File: src/plugins/i18n/index.js
 *
 * import type { App } from 'vue'
 * import { createI18n } from 'vue-i18n'
 *
 * const i18n = createI18n({ ... })
 *
 * export default function (app: App) {
 *   app.use(i18n)
 * }
 * ```
 *
 * All you have to do is use this helper function in `main.js` file like below:
 * ```ts
 * // File: src/main.js
 * import { registerPlugins } from '@core/utils/plugins'
 * import { createApp } from 'vue'
 * import App from '@/App.vue'
 *
 * // Create vue app
 * const app = createApp(App)
 *
 * // Register plugins
 * registerPlugins(app) // [!code focus]
 *
 * // Mount vue app
 * app.mount('#app')
 * ```
 */
export const registerPlugins = app => {
    const imports = import.meta.glob(
        ['../../plugins/*.{js,ts}', '../../plugins/*/index.{js,ts}'],
        { eager: true })
    const importPaths = Object.keys(imports).sort()

    importPaths.forEach(path => {
        const pluginImportModule = imports[path]
        pluginImportModule.default?.(app)
    })
}
