<script setup lang="ts">
import { ChevronRight, GitCommitHorizontal } from '@lucide/vue';
import { ref, watch, onUnmounted } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
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
    try {
        const response = await fetch(
            deploymentShow.url([props.deployment.site_id, props.deployment.id]),
            {
                headers: { Accept: 'application/json' },
            },
        );

        if (!response.ok) {
            return;
        }

        const data = await response.json();
        log.value = data.output ?? '';
        liveStatus.value = data.status;

        if (data.status !== 'pending' && data.status !== 'running') {
            stopPolling();
        }
    } catch {
        // Transient network error — keep polling.
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
        stopPolling();
        fetchLog();
        timer = setInterval(fetchLog, 2000);
    } else {
        stopPolling();
    }
}

onUnmounted(stopPolling);
</script>

<template>
    <div
        class="overflow-hidden rounded-xl border border-border bg-background transition hover:border-primary/30"
    >
        <button
            @click="toggle"
            class="flex w-full items-center gap-3 px-3.5 py-3 text-left text-sm"
        >
            <ChevronRight
                class="size-4 shrink-0 text-muted-foreground transition"
                :class="expanded && 'rotate-90'"
            />
            <StatusBadge :status="liveStatus" />
            <span
                class="flex items-center gap-1.5 font-mono text-xs text-muted-foreground"
            >
                <GitCommitHorizontal class="size-3.5" />{{
                    deployment.commit_hash?.slice(0, 7) ?? '—'
                }}
            </span>
            <span class="hidden flex-1 truncate text-foreground/90 sm:block">{{
                deployment.commit_message ?? ''
            }}</span>
            <span class="ml-auto text-xs text-muted-foreground">
                <span class="rounded bg-muted px-1.5 py-0.5 capitalize">{{
                    deployment.trigger
                }}</span>
                · {{ new Date(deployment.created_at).toLocaleString() }}
            </span>
        </button>
        <pre
            v-if="expanded"
            class="max-h-96 overflow-auto border-t border-border bg-[#0c0a09] p-4 font-mono text-xs leading-relaxed text-emerald-400"
            >{{ log || 'no output yet…' }}</pre>
    </div>
</template>
