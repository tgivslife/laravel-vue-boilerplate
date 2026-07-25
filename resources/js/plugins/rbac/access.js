import { useAuthStore } from '@/stores/AuthStore.js'

/**
 * Session access checks, all backed by the reactive auth store: results
 * re-evaluate automatically inside templates, computeds and effects.
 * Every helper fails closed - junk input or an unauthenticated session can only produce `false`.
 */

function store () {
    return useAuthStore()
}

/**
 * Supports both the variadic form `canAny('a', 'b')` and the array form
 * `canAny(['a', 'b'])`.
 *
 * @param {Array<string|string[]>} values
 * @returns {string[]}
 */
function spread (values) {
    return values.length === 1 && Array.isArray(values[0]) ? values[0] : values
}

export function can (permission) {
    return store().permissions.includes(permission)
}

export function canAny (...permissions) {
    return spread(permissions).some(permission => can(permission))
}

export function canAll (...permissions) {
    const list = spread(permissions)

    return list.length > 0 && list.every(permission => can(permission))
}

export function hasRole (role) {
    return store().roles.includes(role)
}

/**
 * Single evaluator behind <RbacGuard>.
 *
 * - `role`       - at least one of the given roles
 * - `any`        - at least one of the given permissions
 * - `all`        - every given permission
 * - `permission` - alias of `all` (a single string works for both)
 * - `not`        - none of the given values match a role or a permission
 *
 * An unrecognized type is denied, like every other malformed input.
 *
 * @param {'role'|'permission'|'any'|'all'|'not'} type
 * @param {string|string[]} value
 * @returns {boolean}
 */
export function checkAccess (type, value) {
    const values = Array.isArray(value) ? value : [value]

    if (values.length === 0 || values.some(entry => typeof entry !== 'string' || entry === '')) {
        return false
    }

    switch (type) {
        case 'role':
            return values.some(role => hasRole(role))
        case 'any':
            return values.some(permission => can(permission))
        case 'not':
            return !values.some(entry => hasRole(entry) || can(entry))
        case 'permission':
        case 'all':
            return values.every(permission => can(permission))
        default:
            if (import.meta.env.DEV) {
                console.warn(`[rbac] Unknown access check type "${type}"; denying.`)
            }

            return false
    }
}
