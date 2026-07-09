<script setup lang="ts">
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import {
    Database,
    KeyRound,
    Plus,
    Trash2,
    TriangleAlert,
    User,
} from '@lucide/vue';
import {
    index as databasesIndex,
    store as databasesStore,
    destroy as databasesDestroy,
} from '@/routes/databases';

interface DatabaseItem {
    id: number;
    name: string;
    username: string;
    created_at: string;
}

defineProps<{ databases: DatabaseItem[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Databases', href: databasesIndex().url }],
    },
});

const page = usePage();

const form = useForm({ name: '', username: '' });

function create() {
    form.post(databasesStore().url, { onSuccess: () => form.reset() });
}

function remove(database: DatabaseItem) {
    if (
        confirm(
            `Drop database "${database.name}" and its user? This is permanent.`,
        )
    ) {
        router.delete(databasesDestroy(database.id).url);
    }
}
</script>

<template>
    <Head title="Databases" />

    <div class="flex flex-col gap-6 p-4 sm:p-6">
        <!-- Flash -->
        <div
            v-if="page.props.flash?.password"
            class="flex items-start gap-3 rounded-lg border border-primary/30 bg-primary/10 px-4 py-3 text-sm"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0 text-primary" />
            <p>
                {{ page.props.flash.success }}
                <code
                    class="rounded bg-background px-1.5 py-0.5 font-mono font-bold"
                    >{{ page.props.flash.password }}</code
                >
                — copy it now, it is not stored.
            </p>
        </div>
        <div
            v-else-if="page.props.flash?.success"
            class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-300"
        >
            {{ page.props.flash.success }}
        </div>
        <div
            v-if="page.props.flash?.error"
            class="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-700 dark:text-red-300"
        >
            {{ page.props.flash.error }}
        </div>

        <!-- Header -->
        <header
            class="forge-glow relative overflow-hidden rounded-2xl border border-border bg-card px-5 py-6 sm:px-7"
        >
            <div class="relative">
                <p
                    class="flex items-center gap-2 text-xs font-medium tracking-[0.16em] text-primary uppercase"
                >
                    <Database class="size-3.5" /> Managed MySQL
                </p>
                <h1
                    class="mt-2 font-display text-3xl font-extrabold tracking-tight"
                >
                    Databases
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Create isolated databases and users for your applications.
                </p>
            </div>
        </header>

        <!-- Create form -->
        <form
            @submit.prevent="create"
            class="grid gap-4 rounded-2xl border border-border bg-card p-5 sm:grid-cols-[1fr_1fr_auto] sm:items-end sm:p-6"
        >
            <label class="text-sm font-medium">
                Database name
                <div
                    class="mt-1.5 flex items-center rounded-lg border border-input bg-background focus-within:ring-2 focus-within:ring-ring"
                >
                    <Database
                        class="ml-3 size-4 shrink-0 text-muted-foreground"
                    />
                    <input
                        v-model="form.name"
                        placeholder="app_production"
                        class="w-full rounded-lg bg-transparent px-2.5 py-2 font-mono text-sm outline-none"
                    />
                </div>
                <span
                    v-if="form.errors.name"
                    class="mt-1 block text-xs text-red-600"
                    >{{ form.errors.name }}</span
                >
            </label>
            <label class="text-sm font-medium">
                Username
                <div
                    class="mt-1.5 flex items-center rounded-lg border border-input bg-background focus-within:ring-2 focus-within:ring-ring"
                >
                    <User class="ml-3 size-4 shrink-0 text-muted-foreground" />
                    <input
                        v-model="form.username"
                        placeholder="app_user"
                        class="w-full rounded-lg bg-transparent px-2.5 py-2 font-mono text-sm outline-none"
                    />
                </div>
                <span
                    v-if="form.errors.username"
                    class="mt-1 block text-xs text-red-600"
                    >{{ form.errors.username }}</span
                >
            </label>
            <button type="submit" :disabled="form.processing" class="btn-ember">
                <Plus class="size-4" /> Create
            </button>
        </form>

        <!-- List -->
        <div
            v-if="databases.length"
            class="overflow-hidden rounded-2xl border border-border bg-card"
        >
            <div
                class="hidden grid-cols-[1fr_1fr_auto_auto] gap-4 border-b border-border px-5 py-3 text-xs font-medium tracking-wide text-muted-foreground uppercase sm:grid"
            >
                <span>Name</span><span>Username</span><span>Created</span
                ><span></span>
            </div>
            <div
                v-for="database in databases"
                :key="database.id"
                class="grid grid-cols-2 items-center gap-4 border-b border-border px-5 py-3.5 text-sm last:border-0 sm:grid-cols-[1fr_1fr_auto_auto]"
            >
                <span class="flex items-center gap-2 font-mono font-medium">
                    <Database class="size-4 text-primary" />{{ database.name }}
                </span>
                <span
                    class="flex items-center gap-2 font-mono text-muted-foreground"
                >
                    <KeyRound class="size-3.5" />{{ database.username }}
                </span>
                <span class="hidden text-muted-foreground sm:block">{{
                    new Date(database.created_at).toLocaleDateString()
                }}</span>
                <button
                    @click="remove(database)"
                    class="justify-self-end rounded-lg border border-border p-2 text-muted-foreground transition hover:border-destructive/40 hover:bg-destructive/10 hover:text-destructive"
                >
                    <Trash2 class="size-4" />
                </button>
            </div>
        </div>

        <!-- Empty -->
        <div
            v-else
            class="forge-grid flex flex-col items-center justify-center gap-4 rounded-2xl border border-dashed border-border bg-card/40 px-6 py-16 text-center"
        >
            <div
                class="flex size-14 items-center justify-center rounded-2xl bg-primary/10 text-primary"
            >
                <Database class="size-7" />
            </div>
            <div>
                <h3 class="font-display text-lg font-bold">
                    No managed databases
                </h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    Create one above to get a database and dedicated user.
                </p>
            </div>
        </div>
    </div>
</template>
