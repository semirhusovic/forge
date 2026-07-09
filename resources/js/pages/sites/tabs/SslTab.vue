<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { store as sslStore } from '@/routes/sites/ssl';
import type { SiteProps } from '../Show.vue';

const props = defineProps<{ site: SiteProps }>();

function issue() {
    router.post(sslStore(props.site.id).url);
}
</script>

<template>
    <div class="flex flex-col gap-4 rounded-xl border p-4">
        <h2 class="font-semibold">SSL</h2>

        <div v-if="site.ssl_enabled" class="text-sm">
            <span class="rounded bg-green-100 px-2 py-0.5 text-green-800"
                >Active</span
            >
            <span class="ml-2 text-muted-foreground">
                Expires ~{{
                    site.ssl_expires_at
                        ? new Date(site.ssl_expires_at).toLocaleDateString()
                        : '—'
                }}
                (auto-renewed by certbot)
            </span>
        </div>
        <div v-else class="text-sm text-muted-foreground">
            No certificate. DNS for <code>{{ site.domain }}</code> must point at
            this server before issuing.
        </div>

        <button
            @click="issue"
            class="self-start rounded bg-black px-4 py-2 text-sm text-white dark:bg-white dark:text-black"
        >
            {{
                site.ssl_enabled
                    ? 'Re-issue certificate'
                    : "Issue certificate (Let's Encrypt)"
            }}
        </button>

        <pre
            v-if="site.provision_log"
            class="max-h-96 overflow-auto rounded bg-black p-3 text-xs text-green-400"
            >{{ site.provision_log }}</pre>
    </div>
</template>
