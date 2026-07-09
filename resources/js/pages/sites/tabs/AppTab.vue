<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3';
import {
    KeyRound,
    Rocket,
    Terminal,
    Webhook,
    Download,
    History,
} from '@lucide/vue';
import { watch } from 'vue';
import { install as siteInstall } from '@/routes/sites';
import { store as deploymentsStore } from '@/routes/sites/deployments';
import { update as deployScriptUpdate } from '@/routes/sites/deploy-script';
import DeploymentRow from '@/pages/sites/DeploymentRow.vue';
import type { SiteProps, DeploymentItem } from '../Show.vue';

const props = defineProps<{ site: SiteProps; deployments: DeploymentItem[] }>();

const scriptForm = useForm({ deploy_script: props.site.deploy_script });

watch(
    () => props.site.deploy_script,
    (newValue) => {
        if (!scriptForm.isDirty) {
            scriptForm.defaults({ deploy_script: newValue }).reset();
        }
    },
);

function saveScript() {
    scriptForm.put(deployScriptUpdate(props.site.id).url);
}

function installRepo() {
    router.post(siteInstall(props.site.id).url);
}

function deployNow() {
    router.post(deploymentsStore(props.site.id).url);
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <!-- Install / setup -->
        <section
            v-if="
                site.status === 'pending' ||
                site.status === 'key_generated' ||
                site.status === 'failed'
            "
            class="flex flex-col gap-5 rounded-2xl border border-border bg-card p-5 sm:p-6"
        >
            <div class="flex items-center gap-3">
                <div
                    class="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary"
                >
                    <Download class="size-5" />
                </div>
                <div>
                    <h2 class="font-display text-lg font-bold">
                        Install repository
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Add the deploy key and webhook to GitHub, then install.
                    </p>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-border bg-background p-4">
                    <div class="flex items-center gap-2 text-sm font-medium">
                        <KeyRound class="size-4 text-primary" /> Deploy key
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">
                        GitHub → Settings → Deploy keys
                    </p>
                    <pre
                        class="mt-2 max-h-32 overflow-auto rounded-lg bg-muted p-3 font-mono text-[11px] leading-relaxed break-all whitespace-pre-wrap"
                        >{{ site.deploy_key_public ?? 'generating…' }}</pre>
                </div>
                <div class="rounded-xl border border-border bg-background p-4">
                    <div class="flex items-center gap-2 text-sm font-medium">
                        <Webhook class="size-4 text-primary" /> Webhook URL
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">
                        GitHub → Settings → Webhooks (content type JSON)
                    </p>
                    <pre
                        class="mt-2 max-h-32 overflow-auto rounded-lg bg-muted p-3 font-mono text-[11px] leading-relaxed break-all whitespace-pre-wrap"
                        >{{ site.webhook_url }}</pre>
                </div>
            </div>

            <button
                :disabled="site.status === 'pending'"
                class="btn-ember self-start"
                @click="installRepo"
            >
                <Download class="size-4" /> Install repository
            </button>
        </section>

        <!-- Install log -->
        <section
            v-if="
                site.status === 'installing' ||
                (site.status === 'failed' && site.provision_log)
            "
            class="overflow-hidden rounded-2xl border border-border bg-card"
        >
            <div
                class="flex items-center gap-2 border-b border-border px-5 py-3"
            >
                <Terminal class="size-4 text-primary" />
                <h2 class="font-display text-sm font-bold">Install log</h2>
                <span
                    v-if="site.status === 'installing'"
                    class="ml-auto flex items-center gap-1.5 text-xs text-primary"
                >
                    <span
                        class="size-1.5 animate-pulse rounded-full bg-primary"
                    />
                    running
                </span>
            </div>
            <pre
                class="max-h-96 overflow-auto bg-[#0c0a09] p-4 font-mono text-xs leading-relaxed text-emerald-400"
                >{{ site.provision_log }}</pre>
        </section>

        <template v-if="site.status === 'installed'">
            <!-- Deploy -->
            <section
                class="forge-glow relative flex flex-wrap items-center justify-between gap-4 overflow-hidden rounded-2xl border border-border bg-card p-5 sm:p-6"
            >
                <div class="relative flex items-center gap-4">
                    <div
                        class="flex size-11 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-[0_8px_20px_-8px_var(--brand)]"
                    >
                        <Rocket class="size-5" />
                    </div>
                    <div>
                        <h2 class="font-display text-lg font-bold">Deploy</h2>
                        <p class="text-sm text-muted-foreground">
                            Pushing to
                            <code
                                class="rounded bg-muted px-1.5 py-0.5 font-mono text-xs"
                                >{{ site.branch }}</code
                            >
                            auto-deploys via webhook.
                        </p>
                    </div>
                </div>
                <button class="btn-ember relative" @click="deployNow">
                    <Rocket class="size-4" /> Deploy now
                </button>
            </section>

            <!-- Deploy script -->
            <section
                class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-5 sm:p-6"
            >
                <div class="flex items-center gap-2">
                    <Terminal class="size-4 text-primary" />
                    <h2 class="font-display text-sm font-bold">
                        Deploy script
                    </h2>
                    <span
                        v-if="scriptForm.isDirty"
                        class="ml-auto text-xs text-primary"
                        >unsaved changes</span
                    >
                </div>
                <textarea
                    v-model="scriptForm.deploy_script"
                    rows="8"
                    spellcheck="false"
                    class="w-full rounded-xl border border-border bg-[#0c0a09] p-4 font-mono text-xs leading-relaxed text-emerald-300 outline-none focus:ring-2 focus:ring-ring"
                ></textarea>
                <span
                    v-if="scriptForm.errors.deploy_script"
                    class="text-sm text-red-600"
                    >{{ scriptForm.errors.deploy_script }}</span
                >
                <button
                    :disabled="scriptForm.processing"
                    class="self-start rounded-lg border border-border bg-background px-4 py-2 text-sm font-medium transition hover:border-primary/40 hover:text-primary disabled:opacity-50"
                    @click="saveScript"
                >
                    Save script
                </button>
            </section>

            <!-- Deployments -->
            <section
                class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-5 sm:p-6"
            >
                <div class="flex items-center gap-2">
                    <History class="size-4 text-primary" />
                    <h2 class="font-display text-sm font-bold">Deployments</h2>
                    <span
                        class="ml-auto rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                        >{{ deployments.length }}</span
                    >
                </div>
                <div
                    v-if="!deployments.length"
                    class="rounded-xl border border-dashed border-border py-8 text-center text-sm text-muted-foreground"
                >
                    No deployments yet. Hit
                    <span class="font-medium text-foreground">Deploy now</span>
                    to ship.
                </div>
                <div v-else class="flex flex-col gap-2">
                    <DeploymentRow
                        v-for="deployment in deployments"
                        :key="deployment.id"
                        :deployment="deployment"
                    />
                </div>
            </section>

            <!-- Connection details -->
            <section
                class="grid gap-4 rounded-2xl border border-border bg-card p-5 sm:p-6 lg:grid-cols-2"
            >
                <div>
                    <div class="flex items-center gap-2 text-sm font-medium">
                        <Webhook class="size-4 text-primary" /> Webhook URL
                    </div>
                    <pre
                        class="mt-2 overflow-x-auto rounded-lg bg-muted p-3 font-mono text-[11px] break-all whitespace-pre-wrap"
                        >{{ site.webhook_url }}</pre>
                </div>
                <div>
                    <div class="flex items-center gap-2 text-sm font-medium">
                        <KeyRound class="size-4 text-primary" /> Deploy key
                    </div>
                    <pre
                        class="mt-2 max-h-28 overflow-auto rounded-lg bg-muted p-3 font-mono text-[11px] break-all whitespace-pre-wrap"
                        >{{ site.deploy_key_public }}</pre>
                </div>
            </section>
        </template>
    </div>
</template>
