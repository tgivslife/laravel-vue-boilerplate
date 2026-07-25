<!--
    Ported from nuxt-ui-templates/landing (HeroShaders.client.vue) and
    recolored onto the app palette: a rose plasma rendered through a
    Pixelate filter - the animated "squares" are its pixel blocks - with
    an invisible SineWave texture whose alpha modulates the gaps between
    them (the id ties the wave to the Pixelate gap map). The plasma base
    follows the color scheme so the grid reads on both themes.
-->
<script setup>
import { computed } from 'vue'
import { useColorMode } from '@vueuse/core'
import { Pixelate, Plasma, Shader, SineWave } from 'shaders/vue'

const colorMode = useColorMode()

const plasmaColors = computed(() => colorMode.value === 'dark'
    ? { a: '#f43f5e', b: '#171717' }
    : { a: '#f43f5e', b: '#ffffff' })
</script>

<template>
    <Shader>
        <Pixelate
            :gap="{
                type: 'map',
                curve: 0.35,
                source: 'hero-shaders-wave',
                channel: 'alphaInverted',
                inputMax: 1,
                inputMin: 0,
                outputMax: 1,
                outputMin: 0.16
            }"
            :roundness="0.2"
            :scale="68"
            :transform="{ rotation: 180 }"
        >
            <Plasma
                :balance="57"
                :color-a="plasmaColors.a"
                :color-b="plasmaColors.b"
                :contrast="1.6"
                :density="3.3"
                :intensity="1.8"
                :visible="true"
            />
        </Pixelate>
        <SineWave
            id="hero-shaders-wave"
            :amplitude="0.1"
            :position="{ x: 0.5, y: 1 }"
            :softness="0.8"
            :thickness="0.7"
            :visible="false"
        />
    </Shader>
</template>
