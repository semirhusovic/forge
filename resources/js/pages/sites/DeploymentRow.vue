<script setup lang="ts">
import { ref, watch, onUnmounted } from 'vue';
import { show as deploymentShow } from '@/routes/sites/deployments';
import type { DeploymentItem } from './Show.vue';

const props = defineProps<{ deployment: DeploymentItem }>();

const expanded = ref(false);
const log = ref<string>('');
const liveStatus = ref(props.deployment.status);
let timer: ReturnType<typeof setInterval> | null = null;

watch(
    () => props.deployment.status,
    (v) => {
        liveStatus.value = v;
    },
);

async function fetchLog() {
    const response = await fetch(deploymentShow.url([props.deployment.site_id, props.deployment.id]), {
        headers: { Accept: 'application/json' },
    });
    const data = await response.json();
    log.value = data.output ?? '';
    liveStatus.value = data.status;

    if (data.status !== 'pending' && data.status !== 'running') {
        stopPolling();
    }
}

function stopPolling() {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }
}

function toggle() {
    expanded.value = !expanded.value;

    if (expanded.value) {
        fetchLog();
        timer = setInterval(fetchLog, 2000);
    } else {
        stopPolling();
    }
}

onUnmounted(stopPolling);
</script>

<template>
    <div class="rounded border">
        <button @click="toggle" class="flex w-full items-center gap-3 p-2 text-left text-sm">
            <span
                class="rounded px-2 py-0.5 text-xs"
                :class="{
                    'bg-green-100 text-green-800': liveStatus === 'success',
                    'bg-red-100 text-red-800': liveStatus === 'failed',
                    'bg-yellow-100 text-yellow-800': liveStatus === 'running' || liveStatus === 'pending',
                }"
            >
                {{ liveStatus }}
            </span>
            <span class="font-mono text-xs">{{ deployment.commit_hash?.slice(0, 7) ?? '—' }}</span>
            <span class="flex-1 truncate">{{ deployment.commit_message ?? '' }}</span>
            <span class="text-xs text-muted-foreground">{{ deployment.trigger }} · {{ new Date(deployment.created_at).toLocaleString() }}</span>
        </button>
        <pre v-if="expanded" class="max-h-96 overflow-auto border-t bg-black p-3 text-xs text-green-400">{{ log || 'no output yet…' }}</pre>
    </div>
</template>
