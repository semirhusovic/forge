<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3';
import { onMounted, watch } from 'vue';
import { update as envUpdate } from '@/routes/sites/env';
import type { SiteProps } from '../Show.vue';

const props = defineProps<{ site: SiteProps; envContent?: string }>();

const form = useForm({ content: props.envContent ?? '' });

onMounted(() => {
    if (props.envContent === undefined) {
        router.reload({ only: ['envContent'] });
    }
});

watch(
    () => props.envContent,
    (value) => {
        if (value !== undefined && !form.isDirty) {
            form.defaults({ content: value }).reset();
        }
    },
);

function save() {
    form.put(envUpdate(props.site.id).url);
}
</script>

<template>
    <div class="flex flex-col gap-2 rounded-xl border p-4">
        <h2 class="font-semibold">.env</h2>
        <div
            v-if="envContent === undefined"
            class="h-64 animate-pulse rounded bg-muted"
        ></div>
        <template v-else>
            <textarea
                v-model="form.content"
                rows="20"
                class="w-full rounded border p-2 font-mono text-xs"
            ></textarea>
            <span v-if="form.errors.content" class="text-sm text-red-600">{{
                form.errors.content
            }}</span>
            <button
                @click="save"
                :disabled="form.processing"
                class="self-start rounded bg-black px-4 py-2 text-sm text-white disabled:opacity-50 dark:bg-white dark:text-black"
            >
                Save .env
            </button>
        </template>
    </div>
</template>
