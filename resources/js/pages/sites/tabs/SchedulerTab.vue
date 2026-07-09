<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { update as schedulerUpdate } from '@/routes/sites/scheduler';
import type { SiteProps } from '../Show.vue';

const props = defineProps<{ site: SiteProps }>();

function toggle() {
    router.put(schedulerUpdate(props.site.id).url);
}
</script>

<template>
    <div class="flex flex-col gap-3 rounded-xl border p-4">
        <h2 class="font-semibold">Scheduler</h2>
        <p class="text-sm text-muted-foreground">
            Runs <code>php artisan schedule:run</code> every minute via a cron
            entry in <code>/etc/cron.d</code>.
        </p>
        <div class="text-sm">
            Status:
            <span
                :class="
                    site.has_scheduler
                        ? 'text-green-700'
                        : 'text-muted-foreground'
                "
            >
                {{ site.has_scheduler ? 'enabled' : 'disabled' }}
            </span>
        </div>
        <button
            @click="toggle"
            class="self-start rounded bg-black px-4 py-2 text-sm text-white dark:bg-white dark:text-black"
        >
            {{ site.has_scheduler ? 'Disable scheduler' : 'Enable scheduler' }}
        </button>
    </div>
</template>
