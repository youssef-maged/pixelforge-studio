<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { useDebouncedVisit } from '@/composables/useDebouncedVisit';
import { ref } from 'vue';
import Pagination from '@/components/admin/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { exact, usd } from '@/lib/money';
import { generations as generationsRoute } from '@/routes/admin';
import type { GenerationStatus } from '@/types';

type Row = {
    id: string;
    user: { id: number; name: string; email: string };
    prompt: string;
    spec: string;
    status: GenerationStatus;
    provider_id: string | null;
    estimate: string;
    charge: string | null;
    provider_cost: string | null;
    error_code: string | null;
    failure_reason: string | null;
    created_at: string;
    completed_at: string | null;
};

const props = defineProps<{
    filters: { status: GenerationStatus | null; q: string | null };
    generations: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    counts: Record<string, number>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generations', href: generationsRoute() }],
    },
});

const STATUSES: GenerationStatus[] = [
    'pending',
    'queued',
    'processing',
    'succeeded',
    'failed',
];
const q = ref(props.filters.q ?? '');
const go = (params: Record<string, unknown>) =>
    router.get(
        generationsRoute().url,
        {
            status: props.filters.status ?? undefined,
            q: q.value || undefined,
            ...params,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
const search = useDebouncedVisit(() => go({}), 350);
const when = (iso: string) =>
    new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'UTC',
    });
const tone: Record<GenerationStatus, string> = {
    pending: 'text-muted-foreground',
    queued: 'text-muted-foreground',
    processing: 'text-primary',
    succeeded: 'text-success',
    failed: 'text-destructive',
};
</script>

<template>
    <Head title="Generations" />
    <div class="flex flex-col gap-5 px-4 pt-6 pb-16 md:px-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Generations</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Every user's generations, newest first. Times in UTC.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div
                class="flex flex-wrap gap-1 rounded-lg bg-raised p-0.5 text-xs"
                role="tablist"
                aria-label="Status"
            >
                <button
                    type="button"
                    role="tab"
                    :aria-selected="!filters.status"
                    class="rounded-md px-2.5 py-1.5"
                    :class="
                        !filters.status
                            ? 'bg-background shadow-sm'
                            : 'text-muted-foreground'
                    "
                    @click="go({ status: undefined })"
                >
                    All
                </button>
                <button
                    v-for="status in STATUSES"
                    :key="status"
                    type="button"
                    role="tab"
                    :aria-selected="filters.status === status"
                    class="rounded-md px-2.5 py-1.5 capitalize"
                    :class="
                        filters.status === status
                            ? 'bg-background shadow-sm'
                            : 'text-muted-foreground'
                    "
                    @click="go({ status })"
                >
                    {{ status }}
                    <span class="receipt text-muted-foreground">{{
                        counts[status] ?? 0
                    }}</span>
                </button>
            </div>
            <Input
                v-model="q"
                type="search"
                placeholder="Search prompt, user or provider id"
                class="h-8 max-w-80 rounded-lg"
                aria-label="Search generations"
                @input="search"
            />
        </div>

        <div class="overflow-x-auto rounded-xl border bg-card">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted-foreground">
                    <tr class="border-b">
                        <th class="px-4 py-3 font-normal">Requested</th>
                        <th class="px-4 py-3 font-normal">User</th>
                        <th class="px-4 py-3 font-normal">Prompt</th>
                        <th class="px-4 py-3 font-normal">Status</th>
                        <th class="px-4 py-3 text-right font-normal">
                            Estimate
                        </th>
                        <th class="px-4 py-3 text-right font-normal">
                            Charged
                        </th>
                        <th class="px-4 py-3 text-right font-normal">
                            Pixelforge cost
                        </th>
                        <th class="px-4 py-3 font-normal">Provider id</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="g in generations.data"
                        :key="g.id"
                        class="align-top transition-colors hover:bg-raised/60"
                    >
                        <td
                            class="px-4 py-2.5 whitespace-nowrap text-muted-foreground"
                        >
                            {{ when(g.created_at) }}
                        </td>
                        <td class="px-4 py-2.5">
                            <span class="block">{{ g.user.name }}</span
                            ><span class="text-xs text-muted-foreground">{{
                                g.user.email
                            }}</span>
                        </td>
                        <td class="max-w-80 px-4 py-2.5">
                            <span class="line-clamp-2" :title="g.prompt">{{
                                g.prompt
                            }}</span
                            ><span
                                class="receipt text-[11px] text-muted-foreground"
                                >{{ g.spec }}</span
                            >
                        </td>
                        <td class="px-4 py-2.5 whitespace-nowrap">
                            <span class="capitalize" :class="tone[g.status]">{{
                                g.status
                            }}</span>
                            <span
                                v-if="g.error_code"
                                class="block max-w-56 text-xs text-muted-foreground"
                                :title="g.failure_reason ?? ''"
                                >{{ g.error_code }} · free</span
                            >
                        </td>
                        <td
                            class="receipt px-4 py-2.5 text-right whitespace-nowrap"
                            :title="exact(g.estimate)"
                        >
                            {{ usd(g.estimate, { round: 'up' }) }}
                        </td>
                        <td
                            class="receipt px-4 py-2.5 text-right whitespace-nowrap"
                            :title="g.charge ? exact(g.charge) : ''"
                        >
                            {{ g.charge ? usd(g.charge) : '—' }}
                        </td>
                        <td
                            class="receipt px-4 py-2.5 text-right whitespace-nowrap"
                            :title="
                                g.provider_cost ? exact(g.provider_cost) : ''
                            "
                        >
                            {{ g.provider_cost ? usd(g.provider_cost) : '—' }}
                        </td>
                        <td
                            class="receipt px-4 py-2.5 text-xs whitespace-nowrap text-muted-foreground"
                        >
                            {{ g.provider_id ?? 'not accepted' }}
                        </td>
                    </tr>
                    <tr v-if="!generations.data.length">
                        <td colspan="8" class="px-4 py-12 text-center">
                            <p class="text-sm">
                                {{
                                    filters.q
                                        ? `Nothing matches "${filters.q}"`
                                        : filters.status
                                          ? `No ${filters.status} generations`
                                          : 'No generations yet'
                                }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Search matches the prompt, the user's name or
                                email, and Pixelforge's gen_ id.
                            </p>
                            <Button
                                v-if="filters.q || filters.status"
                                size="sm"
                                variant="secondary"
                                class="mt-3"
                                @click="
                                    q = '';
                                    go({ status: undefined, q: undefined });
                                "
                                >Clear filters</Button
                            >
                            <Link
                                v-else
                                href="/studio"
                                class="mt-3 inline-block text-sm text-primary hover:underline"
                                >Make one in the studio</Link
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :links="generations.links" :total="generations.total" />
    </div>
</template>
