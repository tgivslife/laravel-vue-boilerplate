export const DEFAULT_AUTHENTICATED_ROUTE = '/app'

/**
 * Resolves the post-login destination from a `redirect` query parameter.
 *
 * Only same-app paths are accepted: the value must start with a single `/`
 * (rejecting `//host` and `/\host`, which browsers treat as external URLs)
 * and must not point back into the auth pages, which would loop through the
 * guest guard. Anything else falls back to the default authenticated route.
 *
 * @param {unknown} value
 * @param {string} fallback
 * @returns {string}
 */
export function resolveRedirectTarget (value, fallback = DEFAULT_AUTHENTICATED_ROUTE) {
    if (typeof value !== 'string' || !value.startsWith('/')) {
        return fallback
    }

    if (value.startsWith('//') || value.startsWith('/\\')) {
        return fallback
    }

    if (value.startsWith('/auth')) {
        return fallback
    }

    return value
}
