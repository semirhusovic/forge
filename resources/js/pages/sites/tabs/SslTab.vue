<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Lock, ShieldCheck } from '@lucide/vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { store as sslStore } from '@/routes/sites/ssl';
import type { SiteProps } from '../Show.vue';

const props = defineProps<{ site: SiteProps }>();

function issue() {
    router.post(sslStore(props.site.id).url);
}
</script>

<template>
    <section class="panel">
        <div class="panel-header">
            <Lock class="size-4 text-primary" />
            <h2 class="panel-title">SSL certificate</h2>
            <StatusBadge
                class="ml-auto"
                :status="site.ssl_enabled ? 'active' : 'idle'"
                :label="site.ssl_enabled ? 'Active' : 'Not issued'"
            />
        </div>

        <div class="panel-body">
            <p v-if="site.ssl_enabled" class="text-sm text-muted-foreground">
                Expires
                {{
                    site.ssl_expires_at
                        ? new Date(site.ssl_expires_at).toLocaleDateString()
                        : '—'
                }}
                — renewed automatically by certbot's timer.
            </p>
            <p v-else class="text-sm text-muted-foreground">
                No certificate yet. DNS for <code>{{ site.domain }}</code> must
                point at this server before one can be issued.
            </p>

            <button @click="issue" class="btn-ember self-start">
                <ShieldCheck class="size-4" />
                {{
                    site.ssl_enabled
                        ? 'Re-issue certificate'
                        : "Issue certificate (Let's Encrypt)"
                }}
            </button>
        </div>

        <pre
            v-if="site.ssl_log"
            class="terminal max-h-96 border-t border-border"
            >{{ site.ssl_log }}</pre>
    </section>
</template>
