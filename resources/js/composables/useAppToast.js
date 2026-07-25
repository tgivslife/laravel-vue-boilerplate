/**
 * useToast with the app's toast conventions baked in.
 *
 * Nuxt UI toast bodies stay neutral whatever `color` says - the color only renders through the
 * accent surfaces: the icon and the progress bar. The app disables progress bars everywhere by
 * convention, which used to leave color-only toasts (most of the 40+ error toasts) visually
 * indistinguishable from neutral ones. This wrapper supplies a per-color default icon so a
 * colored toast is always visibly colored; anything passed explicitly at the call site wins.
 *
 * The glyphs mirror the app-wide aliases in vite.config.js (`ui.icons`), so toasts agree with
 * every other component about what an error or warning looks like.
 */

const COLOR_ICONS = {
    success: 'i-tabler-circle-check',
    info: 'i-tabler-info-circle',
    warning: 'i-tabler-alert-triangle',
    error: 'i-tabler-circle-x',
}

/**
 * Drop-in replacement for useToast(); only add() is decorated.
 *
 * @returns {Object} The Nuxt UI toast API with the convention-applying add().
 */
export function useAppToast () {
    const toast = useToast()

    return {
        ...toast,
        add: (options = {}) => toast.add({
            progress: false,
            icon: options.color ? COLOR_ICONS[options.color] : undefined,
            ...options,
        }),
    }
}
