<script setup>
/*
 * /app/access is just the menu group; land on the users page.
 */
definePage({
    redirect: '/app/access/users',

    meta: {
        layout: 'protected',

        requiresAuth: true,
    },
})
</script>

<template>
    <div/>
</template>
