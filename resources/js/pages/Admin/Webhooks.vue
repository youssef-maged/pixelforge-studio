<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { useDebouncedVisit } from '@/composables/useDebouncedVisit';
import { ref } from 'vue';
import Pagination from '@/components/admin/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { webhooks as webhooksRoute } from '@/routes/admin';

type Row = {
    id: string;
    type: string;
    generation_id: string | null;
    deliveries: number;
    outcome: string | null;
    received_at: string;
};

const props = defineProps<{
    filters: { q: string | null; duplicates: boolean };
    events: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    stats: { events: number; duplicates: number };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Webhook log', href: webhooksRoute() }] },
});

const q = ref(props.filters.q ?? '');
const go = (params: Record<string, unknown> = {}) =>
    router.get(
        webhooksRoute().url,
        {
            q: q.value || undefined,
            duplicates: props.filters.duplicates ? 1 : undefined,
            ...params,
        },
        { preserveState: true, replace: true },
    );
const search = useDebouncedVisit(() => go(), 350);
const when = (iso: string) =>
    new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        timeZone: 'UTC',
    });
</script>

<template>
    <Head title="Webhook log" />
    <div class="flex flex-col gap-5 px-4 pt-6 pb-16 md:px-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Webhook log</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ stats.events.toLocaleString() }} events received,
                {{ stats.duplicates.toLocaleString() }} duplicate deliveries
                ignored. Each event is applied exactly once, and a status never
                moves backwards. Times in UTC.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <Input
                v-model="q"
                type="search"
                placeholder="Search event id, type or generation"
                class="h-8 max-w-80 rounded-lg"
                aria-label="Search events"
                @input="search"
            />
            <label class="flex cursor-pointer items-center gap-2 px-2 text-xs">
                <input
                    type="checkbox"
                    class="accent-primary"
                    :checked="filters.duplicates"
                    @change="
                        go({ duplicates: filters.duplicates ? undefined : 1 })
                    "
                />
                Only events delivered more than once
            </label>
        </div>

        <div class="overflow-x-auto rounded-xl border bg-card">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted-foreground">
                    <tr class="border-b">
                        <th class="px-4 py-3 font-normal">Received</th>
                        <th class="px-4 py-3 font-normal">Event</th>
                        <th class="px-4 py-3 font-normal">Generation</th>
                        <th class="px-4 py-3 text-right font-normal">
                            Deliveries
                        </th>
                        <th class="px-4 py-3 font-normal">What it did</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="event in events.data"
                        :key="event.id"
                        class="transition-colors hover:bg-raised/60"
                    >
                        <td
                            class="px-4 py-2.5 whitespace-nowrap text-muted-foreground"
                        >
                            {{ when(event.received_at) }}
                        </td>
                        <td class="px-4 py-2.5">
                            <span class="block">{{ event.type }}</span
                            ><span
                                class="receipt text-[11px] text-muted-foreground"
                                >{{ event.id }}</span
                            >
                        </td>
                        <td
                            class="receipt px-4 py-2.5 text-xs text-muted-foreground"
                        >
                            {{ event.generation_id ?? '—' }}
                        </td>
                        <td
                            class="receipt px-4 py-2.5 text-right"
                            :class="
                                event.deliveries > 1
                                    ? 'text-primary'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ event.deliveries }}×<span
                                v-if="event.deliveries > 1"
                                class="block font-sans text-[11px]"
                                >recorded once</span
                            >
                        </td>
                        <td
                            class="px-4 py-2.5 text-xs"
                            :class="
                                event.outcome?.startsWith('moved')
                                    ? ''
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ event.outcome ?? '—' }}
                        </td>
                    </tr>
                    <tr v-if="!events.data.length">
                        <td colspan="5" class="px-4 py-12 text-center">
                            <p class="text-sm">
                                {{
                                    filters.q || filters.duplicates
                                        ? 'No events match these filters'
                                        : 'No webhooks received yet'
                                }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{
                                    filters.q || filters.duplicates
                                        ? 'Try another id, or clear the filters.'
                                        : 'Generate an image, or press "Send test event" in the Pixelforge dashboard. Add [duplicate] to a prompt to see a double delivery.'
                                }}
                            </p>
                            <Button
                                v-if="filters.q || filters.duplicates"
                                size="sm"
                                variant="secondary"
                                class="mt-3"
                                @click="
                                    q = '';
                                    go({ q: undefined, duplicates: undefined });
                                "
                                >Clear filters</Button
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :links="events.links" :total="events.total" />
    </div>
</template>
