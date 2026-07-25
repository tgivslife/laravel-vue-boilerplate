<script setup>
// Placeholder brand mark - swap the SVG for the real logo when branding the
// app. The wordmark is plain text driven by VITE_APP_NAME, so a project
// rename never requires SVG surgery.
const appName = import.meta.env.VITE_APP_NAME || 'Acme'
</script>

<template>
    <div class="flex items-center gap-2">
        <svg viewBox="0 0 24 24" class="h-full w-auto" aria-hidden="true">
            <rect width="24" height="24" rx="6" fill="var(--ui-primary)"/>
            <path d="M13.5 3.5 6 13.5h4.5L10 20.5l7.5-10H13l.5-7z" fill="white"/>
        </svg>
        <span class="text-lg font-semibold tracking-tight text-highlighted leading-none">{{ appName }}</span>
    </div>
</template>
