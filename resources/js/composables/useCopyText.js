/*
 * Copy text to the clipboard with a transient "copied" flag. The async
 * Clipboard API only exists in secure contexts (https or localhost);
 * over plain http the deprecated execCommand path still works, so try
 * both before reporting failure.
 */
export function useCopyText () {
    const copied = ref(false)
    let timer = null

    // Don't let a pending flag reset fire into an unmounted component.
    onUnmounted(() => {
        clearTimeout(timer)
    })

    function legacyCopy (text) {
        const previouslyFocused = document.activeElement

        const textarea = document.createElement('textarea')
        textarea.value = text

        // readonly keeps mobile keyboards closed during the programmatic
        // select; pinning to the corner prevents a scroll jump.
        textarea.setAttribute('readonly', '')
        textarea.style.position = 'fixed'
        textarea.style.top = '0'
        textarea.style.left = '0'
        textarea.style.opacity = '0'

        // Mount inside the dialog the trigger lives in (if any): a modal's
        // focus trap refocuses anything outside itself, which would drop
        // the textarea's selection before execCommand runs.
        const host = previouslyFocused?.closest?.('[role="dialog"]') ?? document.body
        host.appendChild(textarea)
        textarea.select()

        let ok = false
        try {
            ok = document.execCommand('copy')
        } catch {
            ok = false
        }

        textarea.remove()
        previouslyFocused?.focus?.()

        return ok
    }

    async function copy (text) {
        let ok = false

        if (window.isSecureContext && navigator.clipboard) {
            try {
                await navigator.clipboard.writeText(text)
                ok = true
            } catch {
                // Fall through to the legacy path.
            }
        }

        if (!ok) {
            ok = legacyCopy(text)
        }

        if (ok) {
            copied.value = true
            clearTimeout(timer)
            timer = setTimeout(() => {
                copied.value = false
            }, 2000)
        }

        return ok
    }

    /* Clear the flag early (e.g. when a dialog showing it reopens). */
    function reset () {
        clearTimeout(timer)
        copied.value = false
    }

    return {
        copied: readonly(copied),
        copy,
        reset,
    }
}
