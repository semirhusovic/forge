<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{ site: Record<string, unknown> }>();
</script>

<template>
    <Head :title="String(site.domain)" />
    <div class="p-4 text-sm text-muted-foreground">Site detail page lands in a later task. Domain: {{ site.domain }}</div>
</template>
