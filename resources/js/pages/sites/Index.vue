<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    GitBranch,
    Globe,
    Lock,
    LockOpen,
    Plus,
    Server,
    Trash2,
} from '@lucide/vue';
import { ref, watch } from 'vue';
import RepositoryCombobox from '@/components/RepositoryCombobox.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import {
    branches as githubBranches,
    edit as githubSettings,
} from '@/routes/github';
import {
    destroy as sitesDestroy,
    index as sitesIndex,
    show as siteShow,
    store as sitesStore,
} from '@/routes/sites';
import type { Repository } from '@/types';

interface SiteListItem {
    id: number;
    domain: string;
    repository: string;
    branch: string;
    status: string;
    ssl_enabled: boolean;
    php_version: string;
}

const props = defineProps<{
    sites: SiteListItem[];
    phpVersions: string[];
    defaultPhpVersion: string;
    githubConnected: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Sites', href: sitesIndex().url }],
    },
});

const page = usePage();
const showForm = ref(false);

const form = useForm({
    domain: '',
    repository: '',
    branch: 'main',
    php_version: props.defaultPhpVersion,
});

const manualEntry = ref(!props.githubConnected);
const branches = ref<string[]>([]);
const branchesLoading = ref(false);

// Whichever direction the mode is toggled, the previously fetched branch
// list no longer describes the repository the operator is about to pick or
// type — clear it so the select can't be submitted still holding a branch
// that belongs to a different repository.
watch(manualEntry, (isManual) => {
    branches.value = [];
    form.branch = '';

    if (!isManual) {
        // Returning to the picker: RepositoryCombobox remounts with an
        // empty search box (it has no way to display a prefilled value), so
        // reset the repository too rather than leave a manually-typed value
        // the picker can no longer reflect. Everything resets together.
        form.repository = '';
    }
});

// Guards against an older `/github/branches` response landing after a newer
// selection has already been made (see onRepositorySelected below).
let branchesRequestId = 0;

async function onRepositorySelected(repository: Repository) {
    const requestId = ++branchesRequestId;

    form.repository = `git@github.com:${repository.full_name}.git`;
    form.branch = repository.default_branch;
    branches.value = [repository.default_branch];
    branchesLoading.value = true;

    try {
        const response = await fetch(
            `${githubBranches().url}?repository=${encodeURIComponent(repository.full_name)}`,
            { headers: { Accept: 'application/json' } },
        );
        const data = await response.json();

        if (requestId !== branchesRequestId) {
            return;
        }

        if (response.ok) {
            branches.value = data.branches;
            form.branch = data.default_branch;
        }
    } catch {
        // Keep the default branch; the operator can switch to manual entry.
    } finally {
        if (requestId === branchesRequestId) {
            branchesLoading.value = false;
        }
    }
}

function submit() {
    form.post(sitesStore().url, {
        onSuccess: () => {
            form.reset();
            showForm.value = false;
        },
    });
}

function removeSite(site: SiteListItem) {
    if (
        confirm(
            `Remove "${site.domain}" from the panel? Vhost, workers and scheduler are torn down; files stay on disk.`,
        )
    ) {
        router.delete(sitesDestroy(site.id).url);
    }
}

function repoShort(repository: string) {
    return repository.replace(/^git@github\.com:/, '').replace(/\.git$/, '');
}
</script>

<template>
    <Head title="Sites" />

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
            class="forge-glow relative overflow-hidden rounded-2xl border border-border bg-card px-5 py-6 sm:px-7 sm:py-7"
        >
            <div
                class="relative flex flex-wrap items-end justify-between gap-4"
            >
                <div>
                    <p
                        class="flex items-center gap-2 text-xs font-medium tracking-[0.16em] text-primary uppercase"
                    >
                        <Server class="size-3.5" /> Deployments
                    </p>
                    <h1
                        class="mt-2 font-display text-3xl font-extrabold tracking-tight"
                    >
                        Sites
                    </h1>
                    <p class="mt-1 max-w-md text-sm text-muted-foreground">
                        Provision, deploy and manage your applications from a
                        single forge.
                    </p>
                </div>
                <button class="btn-ember" @click="showForm = !showForm">
                    <Plus class="size-4" /> New site
                </button>
            </div>
        </header>

        <!-- New site form -->
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="-translate-y-2 opacity-0"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="-translate-y-2 opacity-0"
        >
            <form
                v-if="showForm"
                class="grid gap-4 rounded-2xl border border-border bg-card p-5 sm:p-6"
                @submit.prevent="submit"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="text-sm font-medium">
                        Domain
                        <div
                            class="mt-1.5 flex items-center rounded-lg border border-input bg-background focus-within:ring-2 focus-within:ring-ring"
                        >
                            <Globe
                                class="ml-3 size-4 shrink-0 text-muted-foreground"
                            />
                            <input
                                v-model="form.domain"
                                placeholder="app.example.com"
                                class="w-full rounded-lg bg-transparent px-2.5 py-2 font-mono text-sm outline-none"
                            />
                        </div>
                        <span v-if="form.errors.domain" class="field-error">{{
                            form.errors.domain
                        }}</span>
                    </label>
                    <label class="text-sm font-medium">
                        Branch
                        <select
                            v-if="githubConnected && !manualEntry"
                            v-model="form.branch"
                            :disabled="branchesLoading || !branches.length"
                            class="mt-1.5 w-full rounded-lg border border-input bg-background px-3 py-2 font-mono text-sm outline-none focus:ring-2 focus:ring-ring disabled:opacity-60"
                        >
                            <option v-if="!branches.length" value="">
                                Pick a repository first
                            </option>
                            <option
                                v-for="branch in branches"
                                :key="branch"
                                :value="branch"
                            >
                                {{ branch }}
                            </option>
                        </select>
                        <div
                            v-else
                            class="mt-1.5 flex items-center rounded-lg border border-input bg-background focus-within:ring-2 focus-within:ring-ring"
                        >
                            <GitBranch
                                class="ml-3 size-4 shrink-0 text-muted-foreground"
                            />
                            <input
                                v-model="form.branch"
                                placeholder="main"
                                class="w-full rounded-lg bg-transparent px-2.5 py-2 font-mono text-sm outline-none"
                            />
                        </div>
                        <span v-if="form.errors.branch" class="field-error">{{
                            form.errors.branch
                        }}</span>
                    </label>
                </div>
                <div class="grid gap-4 sm:grid-cols-[1fr_auto]">
                    <div class="text-sm font-medium">
                        <div class="flex items-center justify-between gap-2">
                            <label for="site-repository">Repository</label>
                            <button
                                v-if="githubConnected"
                                type="button"
                                class="text-xs font-normal text-muted-foreground underline-offset-2 hover:underline"
                                @click="manualEntry = !manualEntry"
                            >
                                {{
                                    manualEntry
                                        ? 'Pick from GitHub'
                                        : 'Enter manually'
                                }}
                            </button>
                        </div>
                        <RepositoryCombobox
                            v-if="githubConnected && !manualEntry"
                            id="site-repository"
                            @selected="onRepositorySelected"
                        />
                        <input
                            v-else
                            id="site-repository"
                            v-model="form.repository"
                            placeholder="git@github.com:user/repo.git"
                            class="mt-1.5 w-full rounded-lg border border-input bg-background px-3 py-2 font-mono text-sm outline-none focus:ring-2 focus:ring-ring"
                        />
                        <span
                            v-if="!githubConnected"
                            class="mt-1.5 block text-xs text-muted-foreground"
                        >
                            <Link
                                :href="githubSettings().url"
                                class="underline underline-offset-2"
                                >Connect GitHub</Link
                            >
                            to pick a repository and install the deploy key and
                            webhook automatically.
                        </span>
                        <span
                            v-if="form.errors.repository"
                            class="field-error"
                            >{{ form.errors.repository }}</span
                        >
                    </div>
                    <label class="text-sm font-medium">
                        PHP version
                        <select
                            v-model="form.php_version"
                            class="mt-1.5 w-full rounded-lg border border-input bg-background px-3 py-2 font-mono text-sm outline-none focus:ring-2 focus:ring-ring sm:w-32"
                        >
                            <option
                                v-for="version in phpVersions"
                                :key="version"
                                :value="version"
                            >
                                PHP {{ version }}
                            </option>
                        </select>
                        <span
                            v-if="form.errors.php_version"
                            class="field-error"
                            >{{ form.errors.php_version }}</span
                        >
                    </label>
                </div>
                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="btn-ember"
                    >
                        Create site
                    </button>
                    <button
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                        @click="showForm = false"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </Transition>

        <!-- Sites grid -->
        <div
            v-if="sites.length"
            class="grid gap-3 md:grid-cols-2 xl:grid-cols-3"
        >
            <Link
                v-for="site in sites"
                :key="site.id"
                :href="siteShow(site.id).url"
                class="group relative flex flex-col gap-4 rounded-2xl border border-border bg-card p-5 transition hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-lg hover:shadow-primary/5"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <div
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"
                        >
                            <Globe class="size-4" />
                        </div>
                        <div class="min-w-0">
                            <p class="truncate font-mono text-sm font-semibold">
                                {{ site.domain }}
                            </p>
                            <p
                                class="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground"
                            >
                                <component
                                    :is="site.ssl_enabled ? Lock : LockOpen"
                                    class="size-3"
                                />
                                {{
                                    site.ssl_enabled
                                        ? 'HTTPS secured'
                                        : 'No SSL'
                                }}
                            </p>
                        </div>
                    </div>
                    <ArrowUpRight
                        class="size-4 shrink-0 text-muted-foreground transition group-hover:text-primary"
                    />
                </div>

                <div
                    class="flex items-center justify-between gap-2 border-t border-border pt-3"
                >
                    <div
                        class="flex min-w-0 items-center gap-2 text-xs text-muted-foreground"
                    >
                        <GitBranch class="size-3.5 shrink-0" />
                        <span class="truncate font-mono">{{
                            repoShort(site.repository)
                        }}</span>
                        <span
                            class="rounded bg-muted px-1.5 py-0.5 font-mono"
                            >{{ site.branch }}</span
                        >
                        <span
                            class="shrink-0 rounded bg-muted px-1.5 py-0.5 font-mono"
                            >PHP {{ site.php_version }}</span
                        >
                    </div>
                    <StatusBadge :status="site.status" />
                </div>

                <button
                    class="absolute top-4 right-4 hidden rounded-md p-1.5 text-muted-foreground opacity-0 transition group-hover:opacity-100 hover:bg-destructive/10 hover:text-destructive sm:block"
                    title="Remove site"
                    @click.prevent="removeSite(site)"
                >
                    <Trash2 class="size-4" />
                </button>
            </Link>
        </div>

        <!-- Empty state -->
        <div
            v-else
            class="forge-grid relative flex flex-col items-center justify-center gap-4 rounded-2xl border border-dashed border-border bg-card/40 px-6 py-16 text-center"
        >
            <div
                class="flex size-14 items-center justify-center rounded-2xl bg-primary/10 text-primary"
            >
                <Server class="size-7" />
            </div>
            <div>
                <h3 class="font-display text-lg font-bold">No sites yet</h3>
                <p class="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">
                    Add your first application to provision a deploy key, wire
                    up the webhook, and ship on every push.
                </p>
            </div>
            <button class="btn-ember" @click="showForm = true">
                <Plus class="size-4" /> Create your first site
            </button>
        </div>
    </div>
</template>
