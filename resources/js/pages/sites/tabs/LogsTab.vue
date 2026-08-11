<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { RefreshCw, ScrollText, Trash2 } from '@lucide/vue';
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
    <section class="panel">
        <div class="panel-header">
            <ScrollText class="size-4 text-primary" />
            <h2 class="panel-title">Logs</h2>
            <span class="text-xs text-muted-foreground">
                <code>{{ site.root_path }}/storage/logs</code>
            </span>
        </div>

        <div class="panel-body">
            <div
                v-if="logFiles === undefined"
                class="h-64 animate-pulse rounded-xl bg-muted"
            ></div>

            <p
                v-else-if="logFiles.length === 0"
                class="text-sm text-muted-foreground"
            >
                No log files yet. Laravel creates <code>laravel.log</code> the
                first time the site writes a log entry.
            </p>

            <template v-else>
                <div class="flex flex-wrap items-center gap-2">
                    <select
                        :value="selected ?? ''"
                        @change="selectFile"
                        class="rounded-lg border border-input bg-background px-2.5 py-2 font-mono text-xs outline-none focus:ring-2 focus:ring-ring"
                    >
                        <option
                            v-for="file in logFiles"
                            :key="file.name"
                            :value="file.name"
                        >
                            {{ file.name }} ({{ formatSize(file.size) }})
                        </option>
                    </select>

                    <button
                        @click="load(selected)"
                        class="btn-secondary px-3 py-2"
                    >
                        <RefreshCw class="size-3.5" /> Refresh
                    </button>

                    <label
                        class="flex cursor-pointer items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm"
                    >
                        <input
                            v-model="autoRefresh"
                            type="checkbox"
                            class="accent-primary"
                        />
                        Auto-refresh
                    </label>

                    <button
                        @click="clearLog"
                        class="btn-danger ml-auto px-3 py-2"
                    >
                        <Trash2 class="size-3.5" /> Clear file
                    </button>
                </div>

                <p
                    v-if="logContent?.truncated"
                    class="text-xs text-muted-foreground"
                >
                    Showing the last 500 lines of
                    {{ formatSize(logContent.size) }} — earlier entries are not
                    displayed.
                </p>

                <pre
                    v-if="logContent?.content"
                    class="terminal max-h-[32rem] rounded-xl border border-border"
                    >{{ logContent.content }}</pre>
                <p v-else class="text-sm text-muted-foreground">
                    This log file is empty.
                </p>
            </template>
        </div>
    </section>
</template>
