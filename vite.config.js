import VueI18nPlugin from '@intlify/unplugin-vue-i18n/vite'
import ui from '@nuxt/ui/vite'
import vue from "@vitejs/plugin-vue";
import laravel from "laravel-vite-plugin";
import {fileURLToPath} from 'node:url'
import {defineConfig} from 'vite'
import MetaLayouts from "vite-plugin-vue-meta-layouts";
import svgLoader from "vite-svg-loader";
import {getPascalCaseRouteName, VueRouterAutoImports} from "vue-router/unplugin";
import VueRouter from 'vue-router/vite'
import OkuMotionResolver from '@oku-ui/motion/resolver'
import tablerIconSubset from './vite-tabler-icons.js'

export default defineConfig({
    server: {
        // Bind IPv4 explicitly: CSP host-sources cannot express IPv6 literals,
        // so a hot file pointing at [::1] makes SetSecurityHeaders' policy silently block every dev asset and the HMR websocket.
        host: '127.0.0.1',
        watch: {
            // Explicitly ignore Laravel's dynamic folders
            ignored: [
                '**/app/**',
                '**/storage/**',
                '**/vendor/**',
                '**/bootstrap/**',
                '**/.git/**',
                '**/*.sqlite*',
            ],
        },
    },
    plugins: [
        VueRouter({
            getRouteName: routeNode => {
                // Convert pascal case to kebab case
                return getPascalCaseRouteName(routeNode)
                    .replace(/([a-z\d])([A-Z])/g, '$1-$2')
                    .toLowerCase()
            },

            beforeWriteFiles: root => {
            },

            routesFolder: 'resources/js/pages',
        }),
        tablerIconSubset(),
        vue({
            template: {
                compilerOptions: {
                    isCustomElement: tag => tag === 'swiper-container' || tag === 'swiper-slide',
                },

                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        ui({
            ui: {
                colors: {
                    primary: 'rose',
                    neutral: 'neutral'
                },
                theme: {
                    transitions: true
                },
                colorMode: {
                    disableTransition: false
                },
                icons: {
                    arrowDown: 'i-tabler-arrow-down',
                    arrowLeft: 'i-tabler-arrow-left',
                    arrowRight: 'i-tabler-arrow-right',
                    arrowUp: 'i-tabler-arrow-up',
                    caution: 'i-tabler-alert-circle',
                    check: 'i-tabler-check',
                    chevronDoubleLeft: 'i-tabler-chevrons-left',
                    chevronDoubleRight: 'i-tabler-chevrons-right',
                    chevronDown: 'i-tabler-chevron-down',
                    chevronLeft: 'i-tabler-chevron-left',
                    chevronRight: 'i-tabler-chevron-right',
                    chevronUp: 'i-tabler-chevron-up',
                    close: 'i-tabler-x',
                    copy: 'i-tabler-copy',
                    copyCheck: 'i-tabler-copy-check',
                    dark: 'i-tabler-moon',
                    drag: 'i-tabler-grip-vertical',
                    ellipsis: 'i-tabler-dots',
                    error: 'i-tabler-circle-x',
                    external: 'i-tabler-external-link',
                    eye: 'i-tabler-eye',
                    eyeOff: 'i-tabler-eye-off',
                    file: 'i-tabler-file',
                    folder: 'i-tabler-folder',
                    folderOpen: 'i-tabler-folder-open',
                    hash: 'i-tabler-hash',
                    info: 'i-tabler-info-circle',
                    light: 'i-tabler-sun',
                    loading: 'i-tabler-loader-2',
                    menu: 'i-tabler-menu-2',
                    minus: 'i-tabler-minus',
                    panelClose: 'i-tabler-layout-sidebar-left-collapse',
                    panelOpen: 'i-tabler-layout-sidebar-left-expand',
                    plus: 'i-tabler-plus',
                    reload: 'i-tabler-rotate',
                    search: 'i-tabler-search',
                    stop: 'i-tabler-square',
                    success: 'i-tabler-circle-check',
                    system: 'i-tabler-device-desktop',
                    tip: 'i-tabler-bulb',
                    upload: 'i-tabler-upload',
                    warning: 'i-tabler-alert-triangle',
                },
            },
            colorMode: {
                disableTransition: false
            },
            autoImport: {
                imports: [
                    'vue',
                    VueRouterAutoImports,
                    '@vueuse/math',
                    'vue-i18n',
                    'pinia',
                ],
                dirs: [
                    './resources/js/@core/utils',
                    './resources/js/@core/composable/',
                    './resources/js/composables/',
                    './resources/js/utils/',
                    './resources/js/plugins/*/composables/*',
                ],
                vueTemplate: true,

                // ℹ️ Disabled to avoid confusion & accidental usage
                ignore: ['useCookies', 'useStorage'],
                eslintrc: {
                    enabled: true,
                    filepath: './.eslintrc-auto-import.json',
                },
            },
            components: {
                dirs: ['resources/js/@core/components', 'resources/js/components'],
                dts: true,
                resolvers: [
                    OkuMotionResolver()
                ]
            }
        }),
        laravel({
            input: ['resources/js/main.js', 'resources/css/fonts.css'],
            refresh: true,
        }),
        MetaLayouts({
            target: './resources/js/layouts',
            defaultLayout: 'default',
        }),
        VueI18nPlugin({
            runtimeOnly: true,
            compositionOnly: true,
            include: [
                fileURLToPath(new URL('./resources/js/plugins/i18n/locales/**', import.meta.url)),
            ],
        }),
        svgLoader(),
    ],
    define: {'process.env': {}},
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
            '@core': fileURLToPath(new URL('./resources/js/@core', import.meta.url)),
            '@layouts': fileURLToPath(new URL('./resources/js/layouts', import.meta.url)),
            '@images': fileURLToPath(new URL('./resources/images', import.meta.url)),
            '@css': fileURLToPath(new URL('./resources/css', import.meta.url))
        }
    },
    build: {
        chunkSizeWarningLimit: 5000,
    },
    optimizeDeps: {
        exclude: [],
        entries: [
            './resources/js/**/*.vue',
        ],
    },
})
