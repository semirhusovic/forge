<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3';
import { Save, ServerCog } from '@lucide/vue';
import { onMounted, watch } from 'vue';
import { update as vhostUpdate } from '@/routes/sites/vhost';
import type { SiteProps } from '../Show.vue';

const props = defineProps<{ site: SiteProps; vhostContent?: string }>();

const form = useForm({ content: props.vhostContent ?? '' });

onMounted(() => {
    if (props.vhostContent === undefined) {
        router.reload({ only: ['vhostContent'] });
    }
});

watch(
    () => props.vhostContent,
    (value) => {
        if (value !== undefined && !form.isDirty) {
            form.defaults({ content: value }).reset();
        }
    },
);

function save() {
    form.put(vhostUpdate(props.site.id).url);
}
</script>

<template>
    <section class="panel">
        <div class="panel-header">
            <ServerCog class="size-4 text-primary" />
            <h2 class="panel-title">Apache config</h2>
            <span class="text-xs text-muted-foreground">
                <code>/etc/apache2/sites-available/{{ site.domain }}.conf</code>
            </span>
            <span
                v-if="form.isDirty"
                class="ml-auto text-xs font-medium text-primary"
            >
                Unsaved changes
            </span>
        </div>

        <div class="panel-body">
            <p class="text-xs text-muted-foreground">
                Validated with <code>apache2ctl configtest</code> before reload;
                an invalid change is reverted automatically. When SSL is
                enabled, certbot manages a separate
                <code>{{ site.domain }}-le-ssl.conf</code> for HTTPS traffic.
            </p>

            <div
                v-if="vhostContent === undefined"
                class="h-72 animate-pulse rounded-xl bg-muted"
            ></div>

            <template v-else>
                <textarea
                    v-model="form.content"
                    rows="22"
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
                    {{ form.processing ? 'Saving…' : 'Save & reload Apache' }}
                </button>
            </template>
        </div>
    </section>
</template>
