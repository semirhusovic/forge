<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
</script>

<template>
    <div
        class="relative flex aspect-square size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground shadow-[0_6px_16px_-6px_var(--brand)]"
    >
        <AppLogoIcon class="size-5 fill-current" />
    </div>
    <div class="ml-1.5 grid flex-1 text-left leading-tight">
        <span class="font-display text-[15px] font-extrabold tracking-tight"
            >Forge</span
        >
        <span
            class="text-[10px] font-medium tracking-[0.14em] text-muted-foreground uppercase"
            >Server platform</span
        >
    </div>
</template>
