<script setup>
import { useContentSpinner } from '@/composables/useContentSpinner'
import Spinner from '@/components/common/Spinner.vue'

defineOptions({ name: 'GlobalSpinner' })

const { spinning } = useContentSpinner()
</script>

<template>
    <Spinner :spinning="spinning" fullscreen/>
</template>
