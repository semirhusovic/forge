<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ status: string; label?: string }>();

type Tone = 'ok' | 'busy' | 'bad' | 'idle';

const tone = computed<Tone>(() => {
    const s = props.status.toLowerCase();
    if (
        [
            'installed',
            'success',
            'active',
            'running',
            'enabled',
            'succeeded',
        ].includes(s)
    ) {
        return 'ok';
    }
    if (['failed', 'error', 'errored'].includes(s)) {
        return 'bad';
    }
    if (
        [
            'installing',
            'pending',
            'queued',
            'deploying',
            'key_generated',
            'in_progress',
        ].includes(s)
    ) {
        return 'busy';
    }
    return 'idle';
});

const text = computed(() => props.label ?? props.status.replace(/_/g, ' '));

const classes: Record<Tone, string> = {
    ok: 'border-emerald-500/25 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    bad: 'border-red-500/25 bg-red-500/10 text-red-600 dark:text-red-400',
    busy: 'border-primary/30 bg-primary/10 text-primary',
    idle: 'border-border bg-muted text-muted-foreground',
};

const dot: Record<Tone, string> = {
    ok: 'bg-emerald-500',
    bad: 'bg-red-500',
    busy: 'bg-primary',
    idle: 'bg-muted-foreground',
};
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize"
        :class="classes[tone]"
    >
        <span class="relative flex size-1.5">
            <span
                v-if="tone === 'busy'"
                class="absolute inline-flex size-full animate-ping rounded-full opacity-75"
                :class="dot[tone]"
            />
            <span
                class="relative inline-flex size-1.5 rounded-full"
                :class="dot[tone]"
            />
        </span>
        {{ text }}
    </span>
</template>
