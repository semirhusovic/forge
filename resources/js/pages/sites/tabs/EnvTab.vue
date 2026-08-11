<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3';
import { KeyRound, Save } from '@lucide/vue';
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
    <section class="panel">
        <div class="panel-header">
            <KeyRound class="size-4 text-primary" />
            <h2 class="panel-title">Environment</h2>
            <span class="text-xs text-muted-foreground">
                <code>{{ site.root_path }}/.env</code>
            </span>
            <span
                v-if="form.isDirty"
                class="ml-auto text-xs font-medium text-primary"
            >
                Unsaved changes
            </span>
        </div>

        <div class="panel-body">
            <div
                v-if="envContent === undefined"
                class="h-72 animate-pulse rounded-xl bg-muted"
            ></div>

            <template v-else>
                <textarea
                    v-model="form.content"
                    rows="20"
                    spellcheck="false"
                    class="code-editor"
                ></textarea>

                <span v-if="form.errors.content" class="field-error">{{
                    form.errors.content
                }}</span>

                <button
                    @click="save"
                    :disabled="form.processing"
                    class="btn-ember self-start"
                >
                    <Save class="size-4" />
                    {{ form.processing ? 'Saving…' : 'Save .env' }}
                </button>
            </template>
        </div>
    </section>
</template>
