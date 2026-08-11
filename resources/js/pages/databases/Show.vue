<script setup lang="ts">
import { Head, router, useHttp } from '@inertiajs/vue3';
import { Database, Play, Table2, TriangleAlert } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import { index as databasesIndex } from '@/routes/databases';
import { query as databasesQuery, show as databasesShow } from '@/routes/databases';

interface DatabaseProps {
    id: number;
    name: string;
    username: string;
}

interface TableItem {
    name: string;
    row_estimate: number;
    size: number;
}

interface RowsResult {
    table: string;
    columns: string[];
    rows: unknown[][];
    page: number;
    per_page: number;
    has_more: boolean;
    row_estimate: number;
}

interface QueryResult {
    kind: 'rows' | 'statement' | 'error';
    columns: string[];
    rows: unknown[][];
    row_count: number;
    affected: number;
    truncated: boolean;
    duration_ms: number;
    error: string | null;
}

const props = defineProps<{
    database: DatabaseProps;
    tables?: TableItem[];
    rows?: RowsResult | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Databases', href: databasesIndex().url }],
    },
});

const selected = ref<string | null>(props.rows?.table ?? null);

const http = useHttp<{ sql: string }, QueryResult>({ sql: '' });

onMounted(() => {
    if (props.tables === undefined) {
        router.reload({ only: ['tables'] });
    }
});

function openTable(table: string, page = 1) {
    selected.value = table;

    router.get(
        databasesShow(props.database.id).url,
        { table, page },
        { only: ['rows'], preserveState: true, preserveScroll: true },
    );
}

function run() {
    if (http.sql.trim() === '') {
        return;
    }

    http.post(databasesQuery(props.database.id).url);
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

function cell(value: unknown): string {
    return value === null || value === undefined ? 'NULL' : String(value);
}

function isNull(value: unknown): boolean {
    return value === null || value === undefined;
}
</script>

<template>
    <Head :title="`Database · ${database.name}`" />

    <div class="flex flex-col gap-6 p-4 sm:p-6">
        <header
            class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-border bg-card px-5 py-4"
        >
            <div class="flex items-center gap-3">
                <Database class="size-5 text-primary" />
                <div>
                    <h1 class="font-mono text-xl font-bold">
                        {{ database.name }}
                    </h1>
                    <p class="text-xs text-muted-foreground">
                        user <code>{{ database.username }}</code>
                    </p>
                </div>
            </div>
        </header>

        <div class="grid gap-6 lg:grid-cols-[18rem_1fr]">
            <!-- Tables -->
            <aside
                class="flex h-fit flex-col gap-1 rounded-2xl border border-border bg-card p-3"
            >
                <h2 class="px-2 py-1 text-sm font-semibold">Tables</h2>

                <div v-if="tables === undefined" class="flex flex-col gap-2 p-2">
                    <div
                        v-for="n in 6"
                        :key="n"
                        class="h-7 animate-pulse rounded bg-muted"
                    ></div>
                </div>

                <p
                    v-else-if="tables.length === 0"
                    class="px-2 py-1 text-sm text-muted-foreground"
                >
                    This database has no tables yet.
                </p>

                <button
                    v-for="table in tables"
                    :key="table.name"
                    @click="openTable(table.name)"
                    class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm transition"
                    :class="
                        selected === table.name
                            ? 'bg-primary/10 text-primary'
                            : 'hover:bg-muted'
                    "
                >
                    <Table2 class="size-3.5 shrink-0" />
                    <span class="truncate font-mono text-xs">{{
                        table.name
                    }}</span>
                    <span
                        class="ml-auto shrink-0 text-[10px] text-muted-foreground"
                    >
                        ~{{ table.row_estimate }} · {{ formatSize(table.size) }}
                    </span>
                </button>
            </aside>

            <div class="flex min-w-0 flex-col gap-6">
                <!-- SQL console -->
                <section
                    class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-5"
                >
                    <div class="flex items-center gap-2">
                        <h2 class="text-sm font-bold">SQL console</h2>
                        <span class="text-xs text-muted-foreground"
                            >runs against
                            <code>{{ database.name }}</code></span
                        >
                    </div>

                    <p
                        class="flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-300"
                    >
                        <TriangleAlert class="mt-px size-3.5 shrink-0" />
                        <span>
                            Statements execute as
                            <code>forge_admin</code>, which has full privileges
                            on every database on this server. There is no undo.
                        </span>
                    </p>

                    <textarea
                        v-model="http.sql"
                        rows="5"
                        spellcheck="false"
                        placeholder="SELECT * FROM users LIMIT 10"
                        class="w-full rounded border p-2 font-mono text-xs"
                        @keydown.ctrl.enter="run"
                        @keydown.meta.enter="run"
                    ></textarea>

                    <div class="flex items-center gap-3">
                        <button
                            @click="run"
                            :disabled="http.processing"
                            class="flex items-center gap-1.5 rounded bg-black px-4 py-2 text-sm text-white disabled:opacity-50 dark:bg-white dark:text-black"
                        >
                            <Play class="size-3.5" />
                            {{ http.processing ? 'Running…' : 'Run' }}
                        </button>
                        <span class="text-xs text-muted-foreground"
                            >Ctrl/⌘ + Enter</span
                        >
                        <span
                            v-if="http.response && !http.response.error"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                http.response.kind === 'rows'
                                    ? `${http.response.row_count} row(s)`
                                    : `${http.response.affected} row(s) affected`
                            }}
                            in {{ http.response.duration_ms }} ms
                        </span>
                    </div>

                    <p
                        v-if="http.errors.sql"
                        class="text-sm text-red-600 dark:text-red-400"
                    >
                        {{ http.errors.sql }}
                    </p>

                    <pre
                        v-if="http.response?.error"
                        class="overflow-auto rounded bg-red-500/10 p-3 font-mono text-xs whitespace-pre-wrap text-red-600 dark:text-red-400"
                        >{{ http.response.error }}</pre
                    >

                    <template
                        v-else-if="
                            http.response?.kind === 'rows' &&
                            http.response.columns.length
                        "
                    >
                        <p
                            v-if="http.response.truncated"
                            class="text-xs text-muted-foreground"
                        >
                            Showing the first {{ http.response.row_count }} rows
                            — add a LIMIT to narrow the result.
                        </p>
                        <div class="overflow-x-auto rounded border">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-muted/50">
                                    <tr>
                                        <th
                                            v-for="column in http.response
                                                .columns"
                                            :key="column"
                                            class="px-3 py-2 font-mono font-semibold whitespace-nowrap"
                                        >
                                            {{ column }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(row, index) in http.response
                                            .rows"
                                        :key="index"
                                        class="border-t"
                                    >
                                        <td
                                            v-for="(value, column) in row"
                                            :key="column"
                                            class="max-w-md truncate px-3 py-1.5 font-mono"
                                            :class="
                                                isNull(value)
                                                    ? 'text-muted-foreground italic'
                                                    : ''
                                            "
                                        >
                                            {{ cell(value) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </section>

                <!-- Table browser -->
                <section
                    v-if="selected"
                    class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-5"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-mono text-sm font-bold">
                            {{ selected }}
                        </h2>
                        <span
                            v-if="rows"
                            class="text-xs text-muted-foreground"
                        >
                            ~{{ rows.row_estimate }} rows (estimated)
                        </span>
                    </div>

                    <div
                        v-if="rows === undefined"
                        class="h-48 animate-pulse rounded bg-muted"
                    ></div>

                    <p
                        v-else-if="rows === null"
                        class="text-sm text-muted-foreground"
                    >
                        That table no longer exists.
                    </p>

                    <p
                        v-else-if="rows.rows.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No rows on this page.
                    </p>

                    <template v-else>
                        <div class="overflow-x-auto rounded border">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-muted/50">
                                    <tr>
                                        <th
                                            v-for="column in rows.columns"
                                            :key="column"
                                            class="px-3 py-2 font-mono font-semibold whitespace-nowrap"
                                        >
                                            {{ column }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(row, index) in rows.rows"
                                        :key="index"
                                        class="border-t"
                                    >
                                        <td
                                            v-for="(value, column) in row"
                                            :key="column"
                                            class="max-w-md truncate px-3 py-1.5 font-mono"
                                            :class="
                                                isNull(value)
                                                    ? 'text-muted-foreground italic'
                                                    : ''
                                            "
                                        >
                                            {{ cell(value) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="flex items-center gap-2">
                            <button
                                :disabled="rows.page <= 1"
                                @click="openTable(selected, rows.page - 1)"
                                class="rounded border px-3 py-1.5 text-sm disabled:opacity-40"
                            >
                                Previous
                            </button>
                            <span class="text-xs text-muted-foreground"
                                >Page {{ rows.page }}</span
                            >
                            <button
                                :disabled="!rows.has_more"
                                @click="openTable(selected, rows.page + 1)"
                                class="rounded border px-3 py-1.5 text-sm disabled:opacity-40"
                            >
                                Next
                            </button>
                        </div>
                    </template>
                </section>
            </div>
        </div>
    </div>
</template>
