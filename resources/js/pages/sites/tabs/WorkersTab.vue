<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3';
import {
    store as workersStore,
    restart as workerRestart,
    destroy as workerDestroy,
} from '@/routes/sites/workers';
import type { SiteProps, WorkerItem } from '../Show.vue';

const props = defineProps<{ site: SiteProps; workers: WorkerItem[] }>();

const presets = [
    { label: 'Queue worker', command: 'queue:work --tries=3' },
    { label: 'Inertia SSR', command: 'inertia:start-ssr' },
];

const form = useForm({ command: presets[0].command });

function create() {
    form.post(workersStore(props.site.id).url, {
        onSuccess: () => form.reset(),
    });
}

function restart(worker: WorkerItem) {
    router.post(workerRestart([props.site.id, worker.id]).url);
}

function remove(worker: WorkerItem) {
    if (confirm('Remove this worker?')) {
        router.delete(workerDestroy([props.site.id, worker.id]).url);
    }
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <form
            @submit.prevent="create"
            class="flex items-end gap-2 rounded-xl border p-4"
        >
            <label class="text-sm">
                Type
                <select
                    class="mt-1 w-full rounded border px-2 py-1.5 text-xs"
                    @change="
                        form.command = ($event.target as HTMLSelectElement)
                            .value
                    "
                >
                    <option
                        v-for="preset in presets"
                        :key="preset.command"
                        :value="preset.command"
                    >
                        {{ preset.label }}
                    </option>
                </select>
            </label>
            <label class="flex-1 text-sm">
                Artisan command
                <input
                    v-model="form.command"
                    class="mt-1 w-full rounded border px-2 py-1.5 font-mono text-xs"
                />
                <span v-if="form.errors.command" class="text-sm text-red-600">{{
                    form.errors.command
                }}</span>
            </label>
            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-black px-4 py-2 text-sm text-white dark:bg-white dark:text-black"
            >
                Add worker
            </button>
        </form>

        <div class="flex flex-col gap-2">
            <div
                v-for="worker in workers"
                :key="worker.id"
                class="flex items-center gap-3 rounded border p-3 text-sm"
            >
                <code class="flex-1">php artisan {{ worker.command }}</code>
                <span class="text-xs text-muted-foreground">{{
                    worker.status
                }}</span>
                <button
                    @click="restart(worker)"
                    class="rounded border px-3 py-1"
                >
                    Restart
                </button>
                <button
                    @click="remove(worker)"
                    class="rounded border border-red-300 px-3 py-1 text-red-700"
                >
                    Delete
                </button>
            </div>
            <div v-if="!workers.length" class="text-sm text-muted-foreground">
                No workers.
            </div>
        </div>
    </div>
</template>
