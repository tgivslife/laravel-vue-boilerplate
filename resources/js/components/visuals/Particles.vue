<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'

const props = defineProps({
    class: { type: String, default: '' },
    quantity: { type: Number, default: 100 },
    staticity: { type: Number, default: 50 },
    ease: { type: Number, default: 50 },
    size: { type: Number, default: 0.4 },
    refresh: { type: Boolean, default: false },
    color: { type: String, default: '#ffffff' },
    vx: { type: Number, default: 0 },
    vy: { type: Number, default: 0 },
})

const canvasRef = ref(null)
const canvasContainerRef = ref(null)

const context = ref(null)
const circles = ref([])
const mouse = reactive({ x: 0, y: 0 })
const mousePosition = reactive({ x: 0, y: 0 })
const canvasSize = reactive({ w: 0, h: 0 })

const dpr = typeof window !== 'undefined' ? window.devicePixelRatio : 1
let rafID = null
let resizeTimeout = null

const handleMouseMove = (event) => {
    mousePosition.x = event.clientX
    mousePosition.y = event.clientY
}

watch(mousePosition, () => {
    onMouseMove()
})

const hexToRgb = (hex) => {
    let cleanHex = hex.replace('#', '')
    if (cleanHex.length === 3) {
        cleanHex = cleanHex.split('').map((char) => char + char).join('')
    }
    const hexInt = parseInt(cleanHex, 16)
    const red = (hexInt >> 16) & 255
    const green = (hexInt >> 8) & 255
    const blue = hexInt & 255

    return [red, green, blue]
}

const rgb = computed(() => hexToRgb(props.color))

const remapValue = (value, start1, end1, start2, end2) =>
    Math.max(
        ((value - start1) * (end2 - start2)) / (end1 - start1) + start2,
        0,
    )

const circleParams = () => ({
    x: Math.random() * canvasSize.w,
    y: Math.random() * canvasSize.h,
    translateX: 0,
    translateY: 0,
    size: Math.random() * 2 + props.size,
    alpha: 0,
    targetAlpha: +(Math.random() * 0.6 + 0.1).toFixed(1),
    dx: (Math.random() - 0.5) * 0.1,
    dy: (Math.random() - 0.5) * 0.1,
    magnetism: 0.1 + Math.random() * 4,
})

const clearContext = () => {
    if (!context.value) {
        return
    }
    context.value.clearRect(0, 0, canvasSize.w, canvasSize.h)
}

const drawCircle = (circle, update = false) => {
    if (!context.value) {
        return
    }

    context.value.translate(circle.translateX, circle.translateY)
    context.value.beginPath()
    context.value.arc(circle.x, circle.y, circle.size, 0, Math.PI * 2)
    context.value.fillStyle = `rgba(${rgb.value.join(', ')}, ${circle.alpha})`
    context.value.fill()
    context.value.setTransform(dpr, 0, 0, dpr, 0, 0)

    if (!update) {
        circles.value.push(circle)
    }
}

const resizeCanvas = () => {
    if (!canvasRef.value || !canvasContainerRef.value) {
        return
    }

    canvasSize.w = canvasContainerRef.value.offsetWidth
    canvasSize.h = canvasContainerRef.value.offsetHeight

    canvasRef.value.width = canvasSize.w * dpr
    canvasRef.value.height = canvasSize.h * dpr
    canvasRef.value.style.width = canvasSize.w + 'px'
    canvasRef.value.style.height = canvasSize.h + 'px'

    context.value.scale(dpr, dpr)

    circles.value = []
    for (let i = 0; i < props.quantity; i++) {
        drawCircle(circleParams())
    }
}

const onMouseMove = () => {
    if (!canvasRef.value) {
        return
    }
    const rect = canvasRef.value.getBoundingClientRect()

    const x = mousePosition.x - rect.left - canvasSize.w / 2
    const y = mousePosition.y - rect.top - canvasSize.h / 2
    const inside = x < canvasSize.w / 2 && x > -canvasSize.w / 2 && y < canvasSize.h / 2 && y > -canvasSize.h / 2
    if (inside) {
        mouse.x = x
        mouse.y = y
    }
}

const animate = () => {
    clearContext()

    circles.value.forEach((circle, i) => {
        const edge = [
            circle.x + circle.translateX - circle.size,
            canvasSize.w - circle.x - circle.translateX - circle.size,
            circle.y + circle.translateY - circle.size,
            canvasSize.h - circle.y - circle.translateY - circle.size,
        ]

        const closestEdge = Math.min(...edge)
        const alphaFactor = +remapValue(closestEdge, 0, 20, 0, 1).toFixed(2)

        circle.alpha =
            alphaFactor > 1
                ? Math.min(circle.alpha + 0.02, circle.targetAlpha)
                : circle.targetAlpha * alphaFactor

        circle.x += circle.dx + props.vx
        circle.y += circle.dy + props.vy

        circle.translateX +=
            (mouse.x / (props.staticity / circle.magnetism) - circle.translateX) /
            props.ease

        circle.translateY +=
            (mouse.y / (props.staticity / circle.magnetism) - circle.translateY) /
            props.ease

        drawCircle(circle, true)

        if (
            circle.x < -circle.size ||
            circle.x > canvasSize.w + circle.size ||
            circle.y < -circle.size ||
            circle.y > canvasSize.h + circle.size
        ) {
            circles.value.splice(i, 1)
            drawCircle(circleParams())
        }
    })

    rafID = requestAnimationFrame(animate)
}

onMounted(() => {
    context.value = canvasRef.value?.getContext('2d')
    resizeCanvas()
    animate()

    window.addEventListener('mousemove', handleMouseMove)
    window.addEventListener('resize', () => {
        if (resizeTimeout) {
            clearTimeout(resizeTimeout)
        }
        resizeTimeout = setTimeout(resizeCanvas, 200)
    })
})

onBeforeUnmount(() => {
    if (rafID) {
        cancelAnimationFrame(rafID)
    }
    window.removeEventListener('mousemove', handleMouseMove)
})

watch(() => props.refresh, resizeCanvas)
watch(() => props.color, resizeCanvas)
</script>

<template>
    <div
        ref="canvasContainerRef"
        :class="['pointer-events-none', props.class]"
        aria-hidden="true"
    >
        <canvas ref="canvasRef" class="size-full"/>
    </div>
</template>
