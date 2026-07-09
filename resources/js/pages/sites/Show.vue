<script setup lang="ts">
import { Head, usePage, usePoll } from '@inertiajs/vue3';
import { ref } from 'vue';
import { index as sitesIndex } from '@/routes/sites';
import AppTab from './tabs/AppTab.vue';
import EnvTab from './tabs/EnvTab.vue';
import SslTab from './tabs/SslTab.vue';
import WorkersTab from './tabs/WorkersTab.vue';

export interface SiteProps {
    id: number;
    domain: string;
    repository: string;
    branch: string;
    root_path: string;
    status: string;
    deploy_script: string;
    auto_deploy: boolean;
    deploy_key_public: string | null;
    ssl_enabled: boolean;
    ssl_expires_at: string | null;
    has_scheduler: boolean;
    provision_log: string | null;
    webhook_url: string;
}

export interface DeploymentItem {
    id: number;
    site_id: number;
    status: string;
    trigger: string;
    commit_hash: string | null;
    commit_message: string | null;
    created_at: string;
    finished_at: string | null;
}

export interface WorkerItem {
    id: number;
    command: string;
    status: string;
}

defineProps<{
    site: SiteProps;
    deployments: DeploymentItem[];
    workers: WorkerItem[];
    envContent?: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Sites', href: sitesIndex().url },
            { title: 'Site', href: '#' },
        ],
    },
});

const page = usePage();
const currentTab = ref<'app' | 'env' | 'ssl' | 'workers' | 'scheduler'>('app');
const tabs = ['app', 'env', 'ssl', 'workers', 'scheduler'] as const;

usePoll(3000, { only: ['site', 'deployments', 'workers'] });
</script>

<template>
    <Head :title="site.domain" />

    <div class="flex flex-col gap-4 p-4">
        <div v-if="page.props.flash?.success" class="rounded border border-green-300 bg-green-50 p-3 text-sm text-green-800">
            {{ page.props.flash.success }}
        </div>
        <div v-if="page.props.flash?.error" class="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800">
            {{ page.props.flash.error }}
        </div>

        <div class="flex items-center gap-3">
            <h1 class="text-xl font-semibold">{{ site.domain }}</h1>
            <span class="rounded bg-muted px-2 py-0.5 text-xs">{{ site.status }}</span>
        </div>

        <nav class="flex gap-1 border-b">
            <button
                v-for="tab in tabs"
                :key="tab"
                class="rounded-t px-3 py-1.5 text-sm capitalize"
                :class="currentTab === tab ? 'border border-b-0 font-medium' : 'text-muted-foreground'"
                @click="currentTab = tab"
            >
                {{ tab }}
            </button>
        </nav>

        <AppTab v-if="currentTab === 'app'" :site="site" :deployments="deployments" />
        <EnvTab v-else-if="currentTab === 'env'" :site="site" :envContent="envContent" />
        <SslTab v-else-if="currentTab === 'ssl'" :site="site" />
        <WorkersTab v-else-if="currentTab === 'workers'" :site="site" :workers="workers" />
        <div v-else class="text-sm text-muted-foreground">Coming in a later task: {{ currentTab }}</div>
    </div>
</template>
