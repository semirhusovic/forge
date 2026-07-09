<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { destroy as sitesDestroy, index as sitesIndex, show as siteShow, store as sitesStore } from '@/routes/sites';

interface SiteListItem {
    id: number;
    domain: string;
    repository: string;
    branch: string;
    status: string;
    ssl_enabled: boolean;
}

defineProps<{ sites: SiteListItem[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Sites', href: sitesIndex().url }],
    },
});

const page = usePage();

const form = useForm({
    domain: '',
    repository: '',
    branch: 'main',
});

function submit() {
    form.post(sitesStore().url);
}

function removeSite(site: SiteListItem) {
    if (confirm(`Remove "${site.domain}" from the panel? Vhost, workers and scheduler are torn down; files stay on disk.`)) {
        router.delete(sitesDestroy(site.id).url);
    }
}
</script>

<template>
    <Head title="Sites" />

    <div class="flex flex-col gap-6 p-4">
        <div v-if="page.props.flash?.success" class="rounded border border-green-300 bg-green-50 p-3 text-sm text-green-800">
            {{ page.props.flash.success }}
        </div>
        <div v-if="page.props.flash?.error" class="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800">
            {{ page.props.flash.error }}
        </div>

        <form class="flex flex-col gap-3 rounded-xl border p-4 md:max-w-xl" @submit.prevent="submit">
            <h2 class="font-semibold">New site</h2>
            <label class="text-sm">
                Domain
                <input v-model="form.domain" placeholder="app.example.com" class="mt-1 w-full rounded border px-2 py-1.5" />
                <span v-if="form.errors.domain" class="text-sm text-red-600">{{ form.errors.domain }}</span>
            </label>
            <label class="text-sm">
                Repository (SSH)
                <input v-model="form.repository" placeholder="git@github.com:user/repo.git" class="mt-1 w-full rounded border px-2 py-1.5" />
                <span v-if="form.errors.repository" class="text-sm text-red-600">{{ form.errors.repository }}</span>
            </label>
            <label class="text-sm">
                Branch
                <input v-model="form.branch" class="mt-1 w-full rounded border px-2 py-1.5" />
                <span v-if="form.errors.branch" class="text-sm text-red-600">{{ form.errors.branch }}</span>
            </label>
            <button
                type="submit"
                :disabled="form.processing"
                class="self-start rounded bg-black px-4 py-2 text-sm text-white disabled:opacity-50 dark:bg-white dark:text-black"
            >
                Create site
            </button>
        </form>

        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b">
                    <th class="py-2">Domain</th>
                    <th>Repository</th>
                    <th>Branch</th>
                    <th>Status</th>
                    <th>SSL</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="site in sites" :key="site.id" class="border-b">
                    <td class="py-2">
                        <Link :href="siteShow(site.id).url" class="font-medium underline">{{ site.domain }}</Link>
                    </td>
                    <td>{{ site.repository }}</td>
                    <td>{{ site.branch }}</td>
                    <td>{{ site.status }}</td>
                    <td>{{ site.ssl_enabled ? 'yes' : 'no' }}</td>
                    <td>
                        <button @click="removeSite(site)" class="rounded border border-red-300 px-3 py-1 text-sm text-red-700">Delete</button>
                    </td>
                </tr>
                <tr v-if="!sites.length">
                    <td colspan="6" class="py-4 text-muted-foreground">No sites yet.</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
