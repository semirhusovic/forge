<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    Database,
    Globe,
    Rocket,
    ShieldCheck,
    Terminal,
} from '@lucide/vue';
import { dashboard } from '@/routes';
import { index as databasesIndex } from '@/routes/databases';
import { index as sitesIndex } from '@/routes/sites';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const shortcuts = [
    {
        title: 'Sites',
        description: 'Deploy and manage applications',
        icon: Globe,
        href: sitesIndex().url,
    },
    {
        title: 'Databases',
        description: 'Provision managed MySQL databases',
        icon: Database,
        href: databasesIndex().url,
    },
];

const capabilities = [
    {
        icon: Rocket,
        title: 'Zero-downtime deploys',
        text: 'Push to your branch and ship automatically via webhook.',
    },
    {
        icon: ShieldCheck,
        title: 'Automatic SSL',
        text: "Let's Encrypt certificates issued and renewed for you.",
    },
    {
        icon: Terminal,
        title: 'Workers & schedulers',
        text: 'Run queue workers and cron with a couple of clicks.',
    },
];
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-col gap-6 p-4 sm:p-6">
        <!-- Hero -->
        <section
            class="forge-glow relative overflow-hidden rounded-2xl border border-border bg-card px-6 py-10 sm:px-10 sm:py-12"
        >
            <div
                class="forge-grid absolute inset-0 [mask-image:radial-gradient(80%_80%_at_100%_0%,black,transparent)] opacity-40"
            />
            <div class="relative max-w-xl">
                <p
                    class="flex items-center gap-2 text-xs font-medium tracking-[0.18em] text-primary uppercase"
                >
                    <span class="size-1.5 rounded-full bg-primary" /> Command
                    center
                </p>
                <h1
                    class="mt-3 font-display text-4xl font-extrabold tracking-tight sm:text-5xl"
                >
                    Ship to your servers,<br /><span class="text-primary"
                        >forged</span
                    >
                    your way.
                </h1>
                <p class="mt-3 text-sm text-muted-foreground sm:text-base">
                    Provision sites, wire up deploys, manage databases and SSL —
                    all from one control plane.
                </p>
                <div class="mt-6 flex flex-wrap gap-3">
                    <Link :href="sitesIndex().url" class="btn-ember"
                        ><Globe class="size-4" /> Manage sites</Link
                    >
                    <Link
                        :href="databasesIndex().url"
                        class="inline-flex items-center gap-2 rounded-lg border border-border bg-background px-4 py-2 text-sm font-semibold transition hover:border-primary/40 hover:text-primary"
                    >
                        <Database class="size-4" /> Databases
                    </Link>
                </div>
            </div>
        </section>

        <!-- Shortcuts -->
        <div class="grid gap-4 sm:grid-cols-2">
            <Link
                v-for="item in shortcuts"
                :key="item.title"
                :href="item.href"
                class="group flex items-center gap-4 rounded-2xl border border-border bg-card p-5 transition hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-lg hover:shadow-primary/5"
            >
                <div
                    class="flex size-12 items-center justify-center rounded-xl bg-primary/10 text-primary"
                >
                    <component :is="item.icon" class="size-6" />
                </div>
                <div class="flex-1">
                    <p class="font-display text-lg font-bold">
                        {{ item.title }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ item.description }}
                    </p>
                </div>
                <ArrowUpRight
                    class="size-5 text-muted-foreground transition group-hover:text-primary"
                />
            </Link>
        </div>

        <!-- Capabilities -->
        <div class="grid gap-4 md:grid-cols-3">
            <div
                v-for="cap in capabilities"
                :key="cap.title"
                class="rounded-2xl border border-border bg-card p-5"
            >
                <div
                    class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary"
                >
                    <component :is="cap.icon" class="size-4" />
                </div>
                <h3 class="mt-3 font-display text-base font-bold">
                    {{ cap.title }}
                </h3>
                <p class="mt-1 text-sm text-muted-foreground">{{ cap.text }}</p>
            </div>
        </div>
    </div>
</template>
