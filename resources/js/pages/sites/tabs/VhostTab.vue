<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3';
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
    <div class="flex flex-col gap-2 rounded-xl border p-4">
        <h2 class="font-semibold">Apache config</h2>
        <p class="text-xs text-muted-foreground">
            Edits the site's HTTP vhost
            (<code>/etc/apache2/sites-available/{{ site.domain }}.conf</code>).
            The config is validated with <code>apache2ctl configtest</code>
            before reload; an invalid change is reverted automatically. When SSL
            is enabled, certbot manages a separate
            <code>{{ site.domain }}-le-ssl.conf</code> for HTTPS traffic.
        </p>
        <div
            v-if="vhostContent === undefined"
            class="h-64 animate-pulse rounded bg-muted"
        ></div>
        <template v-else>
            <textarea
                v-model="form.content"
                rows="22"
                spellcheck="false"
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
                Save &amp; reload Apache
            </button>
        </template>
    </div>
</template>
