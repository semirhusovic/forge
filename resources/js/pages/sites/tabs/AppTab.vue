<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3';
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
        <section
            v-if="site.status === 'pending' || site.status === 'key_generated' || site.status === 'failed'"
            class="flex flex-col gap-3 rounded-xl border p-4"
        >
            <h2 class="font-semibold">Install repository</h2>
            <p class="text-sm text-muted-foreground">
                Add this deploy key to the GitHub repo (Settings → Deploy keys), and the webhook URL (Settings → Webhooks, content type JSON) for
                push-to-deploy. Then install.
            </p>
            <div>
                <div class="text-sm font-medium">Deploy key</div>
                <pre class="mt-1 overflow-x-auto rounded bg-muted p-2 text-xs">{{ site.deploy_key_public ?? 'generating…' }}</pre>
            </div>
            <div>
                <div class="text-sm font-medium">Webhook URL</div>
                <pre class="mt-1 overflow-x-auto rounded bg-muted p-2 text-xs">{{ site.webhook_url }}</pre>
            </div>
            <button
                :disabled="site.status === 'pending'"
                class="self-start rounded bg-black px-4 py-2 text-sm text-white disabled:opacity-50 dark:bg-white dark:text-black"
                @click="installRepo"
            >
                Install repository
            </button>
        </section>

        <section v-if="site.status === 'installing' || (site.status === 'failed' && site.provision_log)" class="rounded-xl border p-4">
            <h2 class="font-semibold">Install log</h2>
            <pre class="mt-2 max-h-96 overflow-auto rounded bg-black p-3 text-xs text-green-400">{{ site.provision_log }}</pre>
        </section>

        <template v-if="site.status === 'installed'">
            <section class="flex items-center gap-4 rounded-xl border p-4">
                <button class="rounded bg-black px-4 py-2 text-sm text-white dark:bg-white dark:text-black" @click="deployNow">Deploy now</button>
                <div class="text-sm text-muted-foreground">
                    Push to <code>{{ site.branch }}</code> also deploys via webhook.
                </div>
            </section>

            <section class="flex flex-col gap-2 rounded-xl border p-4">
                <h2 class="font-semibold">Deploy script</h2>
                <textarea v-model="scriptForm.deploy_script" rows="8" class="w-full rounded border p-2 font-mono text-xs"></textarea>
                <span v-if="scriptForm.errors.deploy_script" class="text-sm text-red-600">{{ scriptForm.errors.deploy_script }}</span>
                <button :disabled="scriptForm.processing" class="self-start rounded border px-4 py-2 text-sm" @click="saveScript">Save script</button>
            </section>

            <section class="flex flex-col gap-2 rounded-xl border p-4">
                <h2 class="font-semibold">Deployments</h2>
                <div v-if="!deployments.length" class="text-sm text-muted-foreground">No deployments yet.</div>
                <DeploymentRow v-for="deployment in deployments" :key="deployment.id" :deployment="deployment" />
            </section>

            <section class="rounded-xl border p-4 text-sm">
                <div class="font-medium">Webhook URL</div>
                <pre class="mt-1 overflow-x-auto rounded bg-muted p-2 text-xs">{{ site.webhook_url }}</pre>
                <div class="mt-3 font-medium">Deploy key</div>
                <pre class="mt-1 overflow-x-auto rounded bg-muted p-2 text-xs">{{ site.deploy_key_public }}</pre>
            </section>
        </template>
    </div>
</template>
