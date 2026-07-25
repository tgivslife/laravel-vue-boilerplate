import { ref } from 'vue'
import { useRouter } from 'vue-router'

// Loads faster than this never show a spinner at all (prevents the "flash"
// effect on fast navigations). Spinner.vue imports this as its render delay,
// so the two sides can't drift apart.
export const SPINNER_MIN_LOADING_TIME = 200

// Once visible, the spinner stays at least this long so it doesn't blink.
const SPINNER_MIN_SHOW_TIME = 300

// Module-level singleton: the spinner reflects app-wide navigation state, so
// every consumer shares one ref and the router guards are registered exactly
// once, no matter how many components call the composable or how often they
// remount.
const spinning = ref(false)
let startTime = 0
let hideTimer = null
let guardsRegistered = false

const onStart = () => {
    // A pending hide from a previous fast navigation must not fire while this navigation is still in flight.
    clearTimeout(hideTimer)
    startTime = performance.now()
    spinning.value = true
}

const onEnd = () => {
    clearTimeout(hideTimer)
    const processTime = performance.now() - startTime

    // The spinner only renders once the navigation outlives the render
    // delay, so the minimum show time counts from that moment - not from
    // navigation start - or anything finishing between the two thresholds
    // would blink for less than SPINNER_MIN_SHOW_TIME.
    const visibleTime = processTime - SPINNER_MIN_LOADING_TIME

    // Finished before the spinner ever rendered, or already shown long
    // enough: hide immediately. Otherwise keep it up to the minimum.
    if (processTime < SPINNER_MIN_LOADING_TIME || visibleTime >= SPINNER_MIN_SHOW_TIME) {
        spinning.value = false
        return
    }

    hideTimer = setTimeout(() => {
        spinning.value = false
    }, SPINNER_MIN_SHOW_TIME - visibleTime)
}

const onError = () => {
    // Thrown guard errors and failed lazy-chunk loads never reach
    // afterEach; without this the full-screen overlay would stay up
    // forever and block the whole app.
    clearTimeout(hideTimer)
    spinning.value = false
}

export function useContentSpinner () {
    const router = useRouter()

    if (router && !guardsRegistered) {
        guardsRegistered = true
        router.beforeEach(onStart)
        router.afterEach(onEnd)
        router.onError(onError)
    }

    return { spinning }
}
