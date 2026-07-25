import { can, canAll, canAny, checkAccess, hasRole } from './access.js'
import RbacGuard from './components/RbacGuard.vue'

export { can, canAll, canAny, checkAccess, hasRole }

export default function (app) {
    app.component('RbacGuard', RbacGuard)
}
