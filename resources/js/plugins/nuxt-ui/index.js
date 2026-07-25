import ui from '@nuxt/ui/vue-plugin'
import { addCollection } from '@iconify/vue'
import tablerIcons from 'virtual:tabler-icons'

/*
 * Register the tabler icons before anything renders: without a local collection Iconify resolves icon data
 * at runtime from api.iconify.design, which is unreachable in the air-gapped production environment.
 * The virtual module (vite-tabler-icons.js) holds just the icons the source code references - runtime-assembled names
 * must be declared via the plugin's extraIcons option in vite.config.js.
 */
addCollection(tablerIcons)

export default function (app) {
    app.use(ui)
}
