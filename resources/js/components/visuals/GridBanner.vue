<template>
    <!--
        Decorative profile banner: a faint square grid with a few accented
        cells, drawn with plain CSS so it costs nothing and renders
        identically in the loading and loaded states.
    -->
    <div class="relative h-24 sm:h-32 bg-elevated/30 overflow-hidden" aria-hidden="true">
        <div class="absolute top-0 left-[10.5rem] size-14 bg-elevated"/>
        <div class="absolute top-14 left-[3.5rem] size-14 bg-elevated/60"/>
        <div class="absolute top-14 left-[24.5rem] size-14 bg-elevated"/>
        <div class="absolute top-0 left-[38.5rem] size-14 bg-elevated/60"/>
        <div class="absolute top-14 left-[52.5rem] size-14 bg-elevated"/>
        <div
            class="absolute inset-0 opacity-60 dark:opacity-40 bg-[linear-gradient(to_right,var(--ui-border)_1px,transparent_1px),linear-gradient(to_bottom,var(--ui-border)_1px,transparent_1px)] bg-[size:3.5rem_3.5rem]"/>
    </div>
</template>
