/**
 * Development knob for inspecting loading skeletons.
 *
 * Set `VITE_SKELETON_DELAY` (milliseconds) in .env to hold every loading
 * state open before its request fires; unset (or 0) it resolves
 * immediately and costs nothing. Vite bakes env values at build time, so
 * changing it requires an `npm run build` or a dev-server restart.
 */
export function skeletonDelay () {
    const ms = Number(import.meta.env.VITE_SKELETON_DELAY ?? 0)

    return ms > 0
        ? new Promise(resolve => setTimeout(resolve, ms))
        : Promise.resolve()
}
