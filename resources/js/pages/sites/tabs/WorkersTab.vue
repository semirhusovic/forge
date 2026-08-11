<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3';
import { Cpu, Plus, RotateCw, Trash2 } from '@lucide/vue';
import StatusBadge from '@/components/StatusBadge.vue';
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
    <div class="flex flex-col gap-6">
        <section class="panel">
            <div class="panel-header">
                <Cpu class="size-4 text-primary" />
                <h2 class="panel-title">Add worker</h2>
            </div>

            <form
                @submit.prevent="create"
                class="grid gap-4 p-5 sm:grid-cols-[14rem_1fr_auto] sm:items-end"
            >
                <label class="text-sm font-medium">
                    Type
                    <select
                        class="mt-1.5 w-full rounded-lg border border-input bg-background px-2.5 py-2 text-sm outline-none focus:ring-2 focus:ring-ring"
                        @change="
                            form.command = (
                                $event.target as HTMLSelectElement
                            ).value
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

                <label class="text-sm font-medium">
                    Artisan command
                    <input
                        v-model="form.command"
                        spellcheck="false"
                        class="mt-1.5 w-full rounded-lg border border-input bg-background px-2.5 py-2 font-mono text-xs outline-none focus:ring-2 focus:ring-ring"
                    />
                    <span v-if="form.errors.command" class="field-error">{{
                        form.errors.command
                    }}</span>
                </label>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="btn-ember"
                >
                    <Plus class="size-4" /> Add worker
                </button>
            </form>
        </section>

        <section v-if="workers.length" class="panel">
            <div class="panel-header">
                <h2 class="panel-title">Running workers</h2>
                <span class="text-xs text-muted-foreground"
                    >{{ workers.length }} total</span
                >
            </div>

            <div
                v-for="worker in workers"
                :key="worker.id"
                class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-3.5 text-sm last:border-0"
            >
                <code class="min-w-0 flex-1 truncate font-mono text-xs"
                    >php artisan {{ worker.command }}</code
                >
                <StatusBadge :status="worker.status" />
                <button
                    @click="restart(worker)"
                    class="btn-secondary px-3 py-1.5"
                >
                    <RotateCw class="size-3.5" /> Restart
                </button>
                <button @click="remove(worker)" class="btn-danger px-3 py-1.5">
                    <Trash2 class="size-3.5" /> Delete
                </button>
            </div>
        </section>

        <div
            v-else
            class="forge-grid flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-border bg-card/40 px-6 py-12 text-center"
        >
            <div
                class="flex size-12 items-center justify-center rounded-2xl bg-primary/10 text-primary"
            >
                <Cpu class="size-6" />
            </div>
            <p class="text-sm text-muted-foreground">
                No workers yet. Add one above to run a queue or SSR process
                under systemd.
            </p>
        </div>
    </div>
</template>
