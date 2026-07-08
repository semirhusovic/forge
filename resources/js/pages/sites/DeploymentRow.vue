<script setup lang="ts">
import type { DeploymentItem } from './Show.vue';

defineProps<{ deployment: DeploymentItem }>();
</script>

<template>
    <div class="rounded border p-2 text-sm">{{ deployment.status }} — {{ deployment.commit_message ?? 'pending' }}</div>
</template>
