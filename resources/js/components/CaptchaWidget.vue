<script setup>
/**
 * Vendor-neutral captcha widget host.
 *
 * Turnstile, hCaptcha and reCAPTCHA all load a script and expose the same explicit-render
 * API - `render(el, { sitekey, callback })` plus `reset(id)` - so one component hosts any of them with no SDK.
 * The page passes what /api/auth/methods reported (site key, script URL, provider);
 * The solved token rides out through v-model and expires back to ''.
 *
 * A widget that fails to load leaves the token empty: the server rejects the request
 * without one, so the gate fails closed rather than silently open.
 */

import { useColorMode } from '@vueuse/core'

const props = defineProps({
    siteKey: { type: String, required: true },
    scriptUrl: { type: String, required: true },
    provider: { type: String, default: 'turnstile' },
})

const emit = defineEmits(['update:modelValue'])

defineOptions({ inheritAttrs: true })

const { t } = useI18n()

const container = useTemplateRef('container')

/* All three vendors accept the same `theme` render param, but only Turnstile defaults to auto-detecting - hCaptcha
 * and reCAPTCHA render light unless told otherwise, so the app's resolved color mode is passed explicitly.
 * Captured once: no vendor can restyle an already-rendered widget, so everything keyed off the theme must agree with the
 * value used at render time, not follow the live color mode.
 */
const colorMode = useColorMode()
const rendersDark = colorMode.value === 'dark'

/* Google's dark widget keeps a border baked into its cross-origin (hence un-stylable) iframe: a 2px white gutter around
 * the anchor plus the anchor's own 1px #525252 edge.
 * Shaving all 3px off with an overflow crop and drawing the app's own 1px border in their place is the only way
 * to blend it into a dark page.
 * Light mode keeps the vendor border - it is part of the widget's design there.
 */
const cropsBorder = computed(() => props.provider === 'recaptcha' && rendersDark)

/* The widget arrives over the network: a skeleton pre-reserves its footprint so the form doesn't shift when it pops in,
 * and a load failure gets said out loud instead of leaving a blank spot next to a submit that keeps refusing. */
const failed = ref(false)
const covered = ref(false)

/** The vendor global each provider's script installs. */
const PROVIDER_GLOBALS = {
    turnstile: 'turnstile',
    hcaptcha: 'hcaptcha',
    recaptcha: 'grecaptcha',
}

/** Each vendor's checkbox footprint, for the skeleton. */
const PROVIDER_SIZES = {
    turnstile: 'w-[300px] h-[65px]',
    hcaptcha: 'w-[303px] h-[78px]',
    recaptcha: 'w-[304px] h-[78px]',
}

const placeholderSize = computed(() => cropsBorder.value
    ? 'w-[300px] h-[74px]'
    : PROVIDER_SIZES[props.provider] ?? PROVIDER_SIZES.turnstile)

let widgetId = null

function vendorApi () {
    return window[PROVIDER_GLOBALS[props.provider] ?? props.provider]
}

/** Load the vendor script once; concurrent widgets share the same tag. */
function loadScript (src) {
    return new Promise((resolve, reject) => {
        const existing = document.querySelector(`script[src="${src}"]`)

        if (existing) {
            if (existing.dataset.captchaLoaded === 'true') {
                resolve()
            } else {
                existing.addEventListener('load', resolve)
                existing.addEventListener('error', reject)
            }
            return
        }

        const script = document.createElement('script')
        script.src = src
        script.async = true
        script.defer = true
        script.addEventListener('load', () => {
            script.dataset.captchaLoaded = 'true'
            resolve()
        })
        script.addEventListener('error', reject)
        document.head.appendChild(script)
    })
}

/** Some vendors finish installing their global slightly after the script's load event. */
async function waitForVendorApi (tries = 50) {
    while (typeof vendorApi()?.render !== 'function' && tries-- > 0) {
        await new Promise(resolve => setTimeout(resolve, 100))
    }

    return vendorApi()
}

onMounted(async () => {
    try {
        await loadScript(props.scriptUrl)
        const api = await waitForVendorApi()

        if (typeof api?.render !== 'function' || !container.value) {
            failed.value = true
            return
        }

        widgetId = api.render(container.value, {
            sitekey: props.siteKey,
            theme: rendersDark ? 'dark' : 'light',
            callback: token => emit('update:modelValue', token),
            'expired-callback': () => emit('update:modelValue', ''),
            'error-callback': () => emit('update:modelValue', ''),
        })

        retireSkeletonOnPaint()
    } catch {
        // The token stays empty; the guarded door will refuse, so say why up front.
        failed.value = true
    }
})

let paintObserver = null

/* The vendor keeps polling its widget's container after Vue removes it from the DOM (Turnstile logs
 * "Cannot find Widget" on an interval), so the widget is surrendered on unmount: remove() where the
 * vendor has it (Turnstile, hCaptcha), reset(id) as the fallback (reCAPTCHA has no remove).
 * Vendors throw on already-collected ids - teardown never propagates that. */
onBeforeUnmount(() => {
    paintObserver?.disconnect()

    if (widgetId === null) {
        return
    }

    const api = vendorApi()

    try {
        if (typeof api?.remove === 'function') {
            api.remove(widgetId)
        } else if (typeof api?.reset === 'function') {
            api.reset(widgetId)
        }
    } catch {
        // Nothing to clean up - the vendor already forgot the widget.
    }

    widgetId = null
})

/**
 * Retire the skeleton once the vendor's iframe has loaded - the earliest deterministic moment the widget shows something of its own.
 * Vendors inject the iframe asynchronously after render() returns, so the container is observed until it appears rather than queried once.
 */
function retireSkeletonOnPaint () {
    const attach = frame => frame.addEventListener('load', () => {
        covered.value = true
    }, { once: true })

    const existing = container.value?.querySelector('iframe')

    if (existing) {
        attach(existing)
        return
    }

    paintObserver = new MutationObserver(() => {
        const frame = container.value?.querySelector('iframe')

        if (!frame) {
            return
        }

        paintObserver.disconnect()
        paintObserver = null
        attach(frame)
    })

    paintObserver.observe(container.value, { childList: true, subtree: true })
}

/**
 * Discard the current (single-use) token and challenge again - callers invoke this after every submit, successful or not,
 * so a retry gets a fresh token.
 */
function reset () {
    if (widgetId !== null && typeof vendorApi()?.reset === 'function') {
        vendorApi().reset(widgetId)
    }

    emit('update:modelValue', '')
}

defineExpose({ reset })
</script>

<template>
    <div>
        <p v-if="failed" class="text-sm text-muted">
            {{ t('messages.auth.captcha_unavailable') }}
        </p>

        <!-- Skeleton below, vendor container explicitly stacked above (relative) so the widget stays clickable:
             the skeleton holds the footprint until the vendor's (asynchronously injected) iframe loads.
             The container is never display:none (that breaks reCAPTCHA's size computation). -->
        <div v-else class="grid">
            <USkeleton v-if="!covered" :class="placeholderSize" class="[grid-area:1/1]"/>
            <div
                ref="container"
                class="[grid-area:1/1] relative"
                :class="cropsBorder && 'w-[300px] h-[74px] border border-default rounded-[3px] overflow-hidden [&_iframe]:-mt-[3px] [&_iframe]:-ml-[3px]'"/>
        </div>
    </div>
</template>
