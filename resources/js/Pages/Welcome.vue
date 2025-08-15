<template>
    <div>
        <h1 class="text-3xl font-bold underline text-amber-800">
            Welcome to Laravel + Inertia + Vue!
        </h1>
        <p>{{ message }}</p>
    </div>
</template>

<script setup>
defineProps({
    message: String,
});
</script>
