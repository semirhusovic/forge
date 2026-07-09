<script setup lang="ts">
import { Head, usePage, usePoll } from '@inertiajs/vue3';
import {
    AppWindow,
    CalendarClock,
    ExternalLink,
    GitBranch,
    KeyRound,
    Lock,
    Cpu,
} from '@lucide/vue';
import { ref } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { index as sitesIndex } from '@/routes/sites';
import AppTab from './tabs/AppTab.vue';
import EnvTab from './tabs/EnvTab.vue';
import SchedulerTab from './tabs/SchedulerTab.vue';
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

const tabs = [
    { key: 'app', label: 'Application', icon: AppWindow },
    { key: 'env', label: 'Environment', icon: KeyRound },
    { key: 'ssl', label: 'SSL', icon: Lock },
    { key: 'workers', label: 'Workers', icon: Cpu },
    { key: 'scheduler', label: 'Scheduler', icon: CalendarClock },
] as const;

const currentTab = ref<(typeof tabs)[number]['key']>('app');

usePoll(3000, { only: ['site', 'deployments', 'workers'] });
</script>

<template>
    <Head :title="site.domain" />

    <div class="flex flex-col gap-6 p-4 sm:p-6">
        <!-- Flash -->
        <div
            v-if="page.props.flash?.success"
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
            <div
                class="relative flex flex-wrap items-start justify-between gap-4"
            >
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-3">
                        <h1
                            class="font-mono text-2xl font-bold tracking-tight break-all"
                        >
                            {{ site.domain }}
                        </h1>
                        <StatusBadge :status="site.status" />
                    </div>
                    <div
                        class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-muted-foreground"
                    >
                        <span class="flex items-center gap-1.5"
                            ><GitBranch class="size-3.5" />
                            <span class="font-mono">{{
                                site.branch
                            }}</span></span
                        >
                        <span class="flex items-center gap-1.5"
                            ><Lock class="size-3.5" />
                            {{ site.ssl_enabled ? 'HTTPS' : 'HTTP only' }}</span
                        >
                        <span class="truncate font-mono">{{
                            site.root_path
                        }}</span>
                    </div>
                </div>
                <a
                    :href="`https://${site.domain}`"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-2 rounded-lg border border-border bg-background px-3.5 py-2 text-sm font-medium transition hover:border-primary/40 hover:text-primary"
                >
                    Visit <ExternalLink class="size-3.5" />
                </a>
            </div>
        </header>

        <!-- Tabs -->
        <nav
            class="flex gap-1 overflow-x-auto rounded-xl border border-border bg-card p-1"
        >
            <button
                v-for="tab in tabs"
                :key="tab.key"
                class="flex shrink-0 items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium transition"
                :class="
                    currentTab === tab.key
                        ? 'bg-primary/10 text-primary'
                        : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                "
                @click="currentTab = tab.key"
            >
                <component :is="tab.icon" class="size-4" />
                {{ tab.label }}
            </button>
        </nav>

        <AppTab
            v-if="currentTab === 'app'"
            :site="site"
            :deployments="deployments"
        />
        <EnvTab
            v-else-if="currentTab === 'env'"
            :site="site"
            :envContent="envContent"
        />
        <SslTab v-else-if="currentTab === 'ssl'" :site="site" />
        <WorkersTab
            v-else-if="currentTab === 'workers'"
            :site="site"
            :workers="workers"
        />
        <SchedulerTab v-else-if="currentTab === 'scheduler'" :site="site" />
    </div>
</template>
