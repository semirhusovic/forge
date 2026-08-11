<script setup lang="ts">
import { Lock, LoaderCircle, Search } from '@lucide/vue';
import { onClickOutside, refDebounced } from '@vueuse/core';
import { ref, watch } from 'vue';
import { repositories as githubRepositories } from '@/routes/github';
import type { Repository } from '@/types';

const emit = defineEmits<{
    (event: 'selected', repository: Repository): void;
}>();

const root = ref<HTMLElement | null>(null);
const open = ref(false);
const loading = ref(false);
const failed = ref(false);
const results = ref<Repository[]>([]);
const highlighted = ref(0);
const search = ref('');
const debounced = refDebounced(search, 250);

onClickOutside(root, () => {
    open.value = false;
});

watch(debounced, () => {
    void load();
});

async function load() {
    loading.value = true;
    failed.value = false;

    try {
        const response = await fetch(
            `${githubRepositories().url}?q=${encodeURIComponent(search.value)}`,
            { headers: { Accept: 'application/json' } },
        );
        const data = await response.json();

        results.value = response.ok ? data.repositories : [];
        failed.value = !response.ok;
    } catch {
        results.value = [];
        failed.value = true;
    } finally {
        loading.value = false;
        highlighted.value = 0;
    }
}

function focus() {
    open.value = true;

    if (!results.value.length && !loading.value) {
        void load();
    }
}

function select(repository: Repository) {
    search.value = repository.full_name;
    open.value = false;
    emit('selected', repository);
}

function move(offset: number) {
    if (!results.value.length) {
        return;
    }

    highlighted.value =
        (highlighted.value + offset + results.value.length) %
        results.value.length;
}

function choose() {
    const repository = results.value[highlighted.value];

    if (repository) {
        select(repository);
    }
}
</script>

<template>
    <div ref="root" class="relative">
        <div
            class="mt-1.5 flex items-center rounded-lg border border-input bg-background focus-within:ring-2 focus-within:ring-ring"
        >
            <Search class="ml-3 size-4 shrink-0 text-muted-foreground" />
            <input
                v-model="search"
                placeholder="Search your repositories…"
                autocomplete="off"
                class="w-full rounded-lg bg-transparent px-2.5 py-2 font-mono text-sm outline-none"
                @focus="focus"
                @keydown.down.prevent="move(1)"
                @keydown.up.prevent="move(-1)"
                @keydown.enter.prevent="choose"
                @keydown.esc="open = false"
            />
            <LoaderCircle
                v-if="loading"
                class="mr-3 size-4 shrink-0 animate-spin text-muted-foreground"
            />
        </div>

        <ul
            v-if="open"
            class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-border bg-popover p-1 shadow-lg"
        >
            <li
                v-for="(repository, index) in results"
                :key="repository.full_name"
                :class="[
                    'flex cursor-pointer items-center gap-2 rounded-md px-2.5 py-1.5 font-mono text-sm',
                    index === highlighted
                        ? 'bg-accent text-accent-foreground'
                        : '',
                ]"
                @mouseenter="highlighted = index"
                @mousedown.prevent="select(repository)"
            >
                <Lock
                    v-if="repository.private"
                    class="size-3 shrink-0 text-muted-foreground"
                />
                <span class="truncate">{{ repository.full_name }}</span>
            </li>

            <li
                v-if="!results.length && !loading"
                class="px-2.5 py-2 text-sm text-muted-foreground"
            >
                {{
                    failed
                        ? 'Could not reach GitHub. Enter the repository manually.'
                        : 'No repositories match.'
                }}
            </li>
        </ul>
    </div>
</template>
