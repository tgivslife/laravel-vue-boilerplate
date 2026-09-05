/**
 * The app's timestamp format, in one place: `dd/mm/YYYY HH:mm:ss`, 24-hour.
 * Fixed rather than delegated to `toLocaleString(locale)`, which rendered the same record month-first for an English
 * session and day-first for a Romanian one - a genuine misreading risk for any day under 13.
 * Composed by hand, not through `Intl`, because the point is that the order does not move.
 *
 * Times render in the browser's zone (as toLocaleString did): the getters below are the local-time
 * family, converting the server's UTC instants on read - their getUTC* twins would render UTC.
 */

/**
 * @param {number} value
 * @returns {string}
 */
function pad (value) {
    return String(value).padStart(2, '0')
}

/**
 * @param {?string|Date} iso - An ISO-8601 timestamp, a Date, or null. Callers that assemble a date
 *   from calendar parts pass the Date itself, since parsing 'YYYY-MM-DD' would read as UTC midnight
 *   and format as the previous day west of Greenwich.
 * @returns {?Date} null when absent or unparseable, so a bad value shows as a dash rather than
 *   "Invalid Date".
 */
function parse (iso) {
    if (!iso) {
        return null
    }

    const date = new Date(iso)

    return Number.isNaN(date.getTime()) ? null : date
}

/**
 * `dd/mm/YYYY`.
 *
 * @param {?string|Date} iso - An ISO-8601 timestamp, a Date, or null.
 * @returns {?string}
 */
export function formatDate (iso) {
    const date = parse(iso)

    if (date === null) {
        return null
    }

    return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()}`
}

/**
 * `HH:mm:ss`, 24-hour.
 *
 * @param {?string|Date} iso - An ISO-8601 timestamp, a Date, or null.
 * @returns {?string}
 */
export function formatTime (iso) {
    const date = parse(iso)

    if (date === null) {
        return null
    }

    return `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
}

/**
 * `dd/mm/YYYY HH:mm:ss`, 24-hour.
 *
 * @param {?string|Date} iso - An ISO-8601 timestamp, a Date, or null.
 * @returns {?string}
 */
export function formatDateTime (iso) {
    const date = parse(iso)

    if (date === null) {
        return null
    }

    return `${formatDate(iso)} ${formatTime(iso)}`
}
