<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CalendarClock, Power } from '@lucide/vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { update as schedulerUpdate } from '@/routes/sites/scheduler';
import type { SiteProps } from '../Show.vue';

const props = defineProps<{ site: SiteProps }>();

function toggle() {
    router.put(schedulerUpdate(props.site.id).url);
}
</script>

<template>
    <section class="panel">
        <div class="panel-header">
            <CalendarClock class="size-4 text-primary" />
            <h2 class="panel-title">Scheduler</h2>
            <StatusBadge
                class="ml-auto"
                :status="site.has_scheduler ? 'enabled' : 'idle'"
                :label="site.has_scheduler ? 'Enabled' : 'Disabled'"
            />
        </div>

        <div class="panel-body">
            <p class="text-sm text-muted-foreground">
                Runs <code>php artisan schedule:run</code> every minute via a
                cron entry in <code>/etc/cron.d</code>.
            </p>

            <button
                @click="toggle"
                :class="site.has_scheduler ? 'btn-danger' : 'btn-ember'"
                class="self-start"
            >
                <Power class="size-4" />
                {{
                    site.has_scheduler
                        ? 'Disable scheduler'
                        : 'Enable scheduler'
                }}
            </button>
        </div>
    </section>
</template>
