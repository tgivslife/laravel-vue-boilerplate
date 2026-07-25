import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { createRequire } from 'node:module'
import { getIcons } from '@iconify/utils'
import customIcons from './vite-tabler-custom-icons.js'

const require = createRequire(import.meta.url)

const VIRTUAL_ID = 'virtual:tabler-icons'
const RESOLVED_ID = '\0' + VIRTUAL_ID

/*
 * Matches every literal spelling the app uses: `i-tabler-arrow-down`,
 * `i-tabler:chevron-left`, and bare `tabler:x`. Only literals are
 * supported - an icon name assembled at runtime must be declared through
 * the plugin's `extraIcons` option instead.
 */
const ICON_NAME_PATTERN = /\b(?:i-)?tabler[-:]([a-z0-9]+(?:-[a-z0-9]+)*)/g

/**
 * Collect every tabler icon name referenced in the app source, including
 * vite.config.js, where the Nuxt UI default icons are remapped.
 */
function collectIconNames (projectRoot) {
    const names = new Set()

    const scanFile = (path) => {
        const content = readFileSync(path, 'utf8')
        for (const match of content.matchAll(ICON_NAME_PATTERN)) {
            names.add(match[1])
        }
    }

    const walk = (dir) => {
        for (const entry of readdirSync(dir, { withFileTypes: true })) {
            const path = join(dir, entry.name)
            if (entry.isDirectory()) {
                walk(path)
            } else if (/\.(vue|js)$/.test(entry.name)) {
                scanFile(path)
            }
        }
    }

    walk(join(projectRoot, 'resources/js'))
    scanFile(join(projectRoot, 'vite.config.js'))

    return [...names].sort()
}

/**
 * Serves `virtual:tabler-icons`: the @iconify-json/tabler collection
 * filtered down to the icons the source code actually references, so the
 * app can register a complete-enough collection offline (no
 * api.iconify.design calls in the airgapped environment) without bundling
 * all ~5000 icons.
 *
 * Dev note: the module is re-scanned on every file change, but a newly
 * added icon only registers after a browser refresh (registration happens
 * once at app startup).
 */
export default function tablerIconSubset ({ extraIcons = [] } = {}) {
    let projectRoot = process.cwd()

    return {
        name: 'tabler-icon-subset',

        configResolved (config) {
            projectRoot = config.root
        },

        resolveId (id) {
            if (id === VIRTUAL_ID) {
                return RESOLVED_ID
            }
        },

        load (id) {
            if (id !== RESOLVED_ID) {
                return
            }

            const names = [...new Set([...collectIconNames(projectRoot), ...extraIcons])]
                .filter(name => !(name in customIcons))
            const collection = JSON.parse(
                readFileSync(require.resolve('@iconify-json/tabler/icons.json'), 'utf8'),
            )

            const subset = getIcons(collection, names, true)

            if (subset?.not_found?.length) {
                this.warn(`Unknown tabler icons referenced in source: ${subset.not_found.join(', ')}`)
                delete subset.not_found
            }

            // In-house brand icons ride along under the tabler prefix so the
            // app spells every icon the same way.
            Object.assign(subset.icons, customIcons)

            return `export default ${JSON.stringify(subset)}`
        },

        handleHotUpdate ({ server }) {
            const mod = server.moduleGraph.getModuleById(RESOLVED_ID)

            if (mod) {
                server.moduleGraph.invalidateModule(mod)
            }
        },
    }
}
