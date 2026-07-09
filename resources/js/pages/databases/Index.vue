<script setup lang="ts">
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { index as databasesIndex, store as databasesStore, destroy as databasesDestroy } from '@/routes/databases';

interface DatabaseItem {
    id: number;
    name: string;
    username: string;
    created_at: string;
}

defineProps<{ databases: DatabaseItem[] }>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Databases', href: databasesIndex().url }] },
});

const page = usePage();

const form = useForm({ name: '', username: '' });

function create() {
    form.post(databasesStore().url, { onSuccess: () => form.reset() });
}

function remove(database: DatabaseItem) {
    if (confirm(`Drop database "${database.name}" and its user? This is permanent.`)) {
        router.delete(databasesDestroy(database.id).url);
    }
}
</script>

<template>
    <Head title="Databases" />

    <div class="flex flex-col gap-6 p-4">
        <div v-if="page.props.flash?.password" class="rounded border border-yellow-300 bg-yellow-50 p-3 text-sm text-yellow-900">
            {{ page.props.flash.success }}
            <code class="font-bold">{{ page.props.flash.password }}</code>
            — copy it now, it is not stored.
        </div>
        <div v-else-if="page.props.flash?.success" class="rounded border border-green-300 bg-green-50 p-3 text-sm text-green-800">
            {{ page.props.flash.success }}
        </div>
        <div v-if="page.props.flash?.error" class="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800">
            {{ page.props.flash.error }}
        </div>

        <form @submit.prevent="create" class="flex items-end gap-2 rounded-xl border p-4 md:max-w-xl">
            <label class="flex-1 text-sm">
                Database name
                <input v-model="form.name" class="mt-1 w-full rounded border px-2 py-1.5" />
                <span v-if="form.errors.name" class="text-sm text-red-600">{{ form.errors.name }}</span>
            </label>
            <label class="flex-1 text-sm">
                Username
                <input v-model="form.username" class="mt-1 w-full rounded border px-2 py-1.5" />
                <span v-if="form.errors.username" class="text-sm text-red-600">{{ form.errors.username }}</span>
            </label>
            <button type="submit" :disabled="form.processing" class="rounded bg-black px-4 py-2 text-sm text-white dark:bg-white dark:text-black">
                Create
            </button>
        </form>

        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b">
                    <th class="py-2">Name</th>
                    <th>Username</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="database in databases" :key="database.id" class="border-b">
                    <td class="py-2 font-mono">{{ database.name }}</td>
                    <td class="font-mono">{{ database.username }}</td>
                    <td>{{ new Date(database.created_at).toLocaleDateString() }}</td>
                    <td class="text-right">
                        <button @click="remove(database)" class="rounded border border-red-300 px-3 py-1 text-red-700">Drop</button>
                    </td>
                </tr>
                <tr v-if="!databases.length">
                    <td colspan="4" class="py-4 text-muted-foreground">No managed databases.</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
