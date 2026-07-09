<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        :class="className"
        v-bind="$attrs"
    >
        <path
            fill="currentColor"
            fill-rule="evenodd"
            clip-rule="evenodd"
            d="M12 2.5C11.4 5 9.9 7.2 8 8.8 6 10.5 5 12.3 5 14.4A7 7 0 0 0 19 14.4c0-2.1-1-3.9-3-5.6-1.9-1.6-3.4-3.8-4-6.3Zm0 16.8a3.2 3.2 0 0 1-3.2-3.2c0-1 .5-1.8 1.4-2.6.6-.5 1.2-1.2 1.8-2 .6.8 1.2 1.5 1.8 2 .9.8 1.4 1.6 1.4 2.6A3.2 3.2 0 0 1 12 19.3Z"
        />
    </svg>
</template>
