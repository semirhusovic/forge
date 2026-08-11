<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { destroy as logsDestroy } from '@/routes/sites/logs';
import type { LogContent, LogFileItem, SiteProps } from '../Show.vue';

const props = defineProps<{
    site: SiteProps;
    logFiles?: LogFileItem[];
    logContent?: LogContent | null;
}>();

const selected = ref<string | null>(props.logContent?.name ?? null);
const autoRefresh = ref(false);

let timer: ReturnType<typeof setInterval> | null = null;

function load(file?: string | null) {
    router.reload({
        only: ['logFiles', 'logContent'],
        data: file ? { log: file } : {},
    });
}

onMounted(() => {
    if (props.logFiles === undefined) {
        load(selected.value);
    }
});

// Keep the picker in step with whatever the server actually returned — on the
// first load nothing is selected yet and the server falls back to the newest.
watch(
    () => props.logContent,
    (value) => {
        if (value) {
            selected.value = value.name;
        }
    },
);

function selectFile(event: Event) {
    const name = (event.target as HTMLSelectElement).value;
    selected.value = name;
    load(name);
}

function stopTimer() {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}

function startTimer() {
    stopTimer();
    timer = setInterval(() => {
        // A backgrounded tab does not need a live log; skipping keeps an
        // forgotten-open panel from polling the server all day.
        if (document.hidden) {
            return;
        }
        load(selected.value);
    }, 5000);
}

watch(autoRefresh, (enabled) => (enabled ? startTimer() : stopTimer()));

onUnmounted(stopTimer);

function clearLog() {
    if (!selected.value) {
        return;
    }

    if (!window.confirm(`Empty ${selected.value}? This cannot be undone.`)) {
        return;
    }

    router.delete(logsDestroy(props.site.id).url, {
        data: { log: selected.value },
        preserveScroll: true,
        onSuccess: () => load(selected.value),
    });
}

function formatSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
</script>

<template>
    <div class="flex flex-col gap-3 rounded-xl border p-4">
        <div class="flex flex-wrap items-center gap-3">
            <h2 class="font-semibold">Logs</h2>
            <span class="text-xs text-muted-foreground">
                <code>{{ site.root_path }}/storage/logs</code>
            </span>
        </div>

        <!-- Loading -->
        <div v-if="logFiles === undefined" class="h-64 animate-pulse rounded bg-muted"></div>

        <p v-else-if="logFiles.length === 0" class="text-sm text-muted-foreground">
            No log files yet. Laravel creates <code>laravel.log</code> the first
            time the site writes a log entry.
        </p>

        <template v-else>
            <div class="flex flex-wrap items-center gap-2">
                <select
                    :value="selected ?? ''"
                    @change="selectFile"
                    class="rounded border bg-background px-2 py-1.5 font-mono text-xs"
                >
                    <option v-for="file in logFiles" :key="file.name" :value="file.name">
                        {{ file.name }} ({{ formatSize(file.size) }})
                    </option>
                </select>

                <button
                    @click="load(selected)"
                    class="rounded border px-3 py-1.5 text-sm hover:bg-muted"
                >
                    Refresh
                </button>

                <label class="flex items-center gap-1.5 text-sm">
                    <input v-model="autoRefresh" type="checkbox" class="rounded" />
                    Auto-refresh
                </label>

                <button
                    @click="clearLog"
                    class="ml-auto rounded border border-red-500/40 px-3 py-1.5 text-sm text-red-600 hover:bg-red-500/10 dark:text-red-400"
                >
                    Clear file
                </button>
            </div>

            <p v-if="logContent?.truncated" class="text-xs text-muted-foreground">
                Showing the last 500 lines of
                {{ formatSize(logContent.size) }} — earlier entries are not
                displayed. Read the full file on the server.
            </p>

            <pre
                v-if="logContent?.content"
                class="max-h-[32rem] overflow-auto rounded bg-[#0c0a09] p-4 font-mono text-xs leading-relaxed whitespace-pre text-emerald-400"
                >{{ logContent.content }}</pre>
            <p v-else class="text-sm text-muted-foreground">
                This log file is empty.
            </p>
        </template>
    </div>
</template>
