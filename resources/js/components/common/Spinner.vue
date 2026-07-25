<script setup>
import { onUnmounted, ref, watch } from 'vue'
import { SPINNER_MIN_LOADING_TIME } from '@/composables/useContentSpinner'

const props = defineProps({
    class: {
        type: String,
        default: '',
    },
    // Cover the viewport above everything instead of the nearest positioned
    // ancestor. An explicit prop rather than utility classes passed from
    // outside, which would conflict with the root's own position classes
    // and win or lose by stylesheet order.
    fullscreen: {
        type: Boolean,
        default: false,
    },
    minLoadingTime: {
        type: Number,
        default: SPINNER_MIN_LOADING_TIME,
    },
    spinning: {
        type: Boolean,
        default: false,
    },
})

const showSpinner = ref(false)
const renderSpinner = ref(false)
let showTimer = null

watch(
    () => props.spinning,
    (show) => {
        clearTimeout(showTimer)

        if (!show) {
            showSpinner.value = false
            return
        }

        showTimer = setTimeout(() => {
            showSpinner.value = true
            renderSpinner.value = true
        }, props.minLoadingTime)
    },
    {
        immediate: true,
    },
)

onUnmounted(() => clearTimeout(showTimer))

function onTransitionEnd () {
    if (!showSpinner.value) {
        renderSpinner.value = false
    }
}
</script>

<template>
    <div
        :class="[
      'flex items-center justify-center bg-overlay-content backdrop-blur-sm transition-all duration-200',
      fullscreen ? 'fixed inset-0 z-[9999]' : 'absolute left-0 top-0 z-50 size-full',
      { 'invisible opacity-0 pointer-events-none': !showSpinner },
      props.class,
    ]"
        @transitionend="onTransitionEnd"
    >
        <div
            v-if="renderSpinner"
            class="loader relative size-12 before:absolute before:left-0 before:top-[60px] before:h-[5px] before:w-12 before:rounded-[50%] before:bg-primary/50 before:content-[''] after:absolute after:left-0 after:top-0 after:h-full after:w-full after:rounded after:bg-primary after:content-['']"
        ></div>
    </div>
</template>

<style scoped>
.loader {
    &::before {
        animation: loader-shadow-ani 0.5s linear infinite;
    }

    &::after {
        animation: loader-jump-ani 0.5s linear infinite;
    }
}

@keyframes loader-jump-ani {
    15% {
        border-bottom-right-radius: 3px;
    }
    25% {
        transform: translateY(9px) rotate(22.5deg);
    }
    50% {
        border-bottom-right-radius: 40px;
        transform: translateY(18px) scale(1, 0.9) rotate(45deg);
    }
    75% {
        transform: translateY(9px) rotate(67.5deg);
    }
    100% {
        transform: translateY(0) rotate(90deg);
    }
}

@keyframes loader-shadow-ani {
    0%,
    100% {
        transform: scale(1, 1);
    }
    50% {
        transform: scale(1.2, 1);
    }
}
</style>
