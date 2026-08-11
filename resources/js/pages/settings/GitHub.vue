<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { GitBranch } from '@lucide/vue';
import { computed } from 'vue';
import GitHubConnectionController from '@/actions/App/Http/Controllers/Settings/GitHubConnectionController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { edit } from '@/routes/github';

defineProps<{ configured: boolean }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'GitHub', href: edit() }],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const connected = computed(() => Boolean(user.value.github_login));
</script>

<template>
    <Head title="GitHub" />

    <h1 class="sr-only">GitHub settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="GitHub"
            description="Connect your account to pick repositories from a list and install deploy keys and webhooks automatically"
        />

        <div
            v-if="connected"
            class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-border bg-card p-4"
        >
            <div class="flex items-center gap-3">
                <img
                    v-if="user.github_avatar_url"
                    :src="user.github_avatar_url"
                    :alt="`@${user.github_login} avatar`"
                    class="size-10 rounded-full"
                />
                <div>
                    <p class="font-mono text-sm font-semibold">
                        @{{ user.github_login }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Connected — new sites install their deploy key and
                        webhook automatically.
                    </p>
                </div>
            </div>

            <Form v-bind="GitHubConnectionController.destroy.form()">
                <Button variant="outline" type="submit">Disconnect</Button>
            </Form>
        </div>

        <div
            v-else
            class="flex flex-col items-start gap-4 rounded-xl border border-dashed border-border bg-card/40 p-6"
        >
            <div
                class="flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary"
            >
                <GitBranch class="size-5" />
            </div>
            <p class="max-w-lg text-sm text-muted-foreground">
                Connecting grants the panel the
                <code class="font-mono">repo</code> and
                <code class="font-mono">admin:repo_hook</code> scopes, so it can
                read your repository list and add a read-only deploy key plus a
                push webhook to the repositories you deploy.
            </p>

            <p v-if="!configured" class="text-sm text-destructive">
                Set <code class="font-mono">GITHUB_CLIENT_ID</code> and
                <code class="font-mono">GITHUB_CLIENT_SECRET</code> in the panel
                .env first, using
                <code class="font-mono">/settings/github/callback</code> as the
                OAuth App callback URL.
            </p>

            <Form v-bind="GitHubConnectionController.create.form()">
                <Button type="submit" :disabled="!configured">
                    <GitBranch class="size-4" /> Connect GitHub
                </Button>
            </Form>
        </div>
    </div>
</template>
