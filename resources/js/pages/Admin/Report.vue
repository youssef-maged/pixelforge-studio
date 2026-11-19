<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { VisArea, VisAxis, VisLine, VisStackedBar, VisXYContainer } from '@unovis/vue';
import { useDebounceFn } from '@vueuse/core';
import { Check, ChevronRight, Download, RefreshCw, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import type { ChartConfig } from '@/components/ui/chart';
import { ChartContainer, ChartCrosshair, ChartTooltip, ChartTooltipContent, componentToString } from '@/components/ui/chart';
import { Input } from '@/components/ui/input';
import { exact, toNano, usd } from '@/lib/money';
import { report } from '@/routes/admin';
import { exportMethod as exportReport } from '@/routes/admin/report';

type Figures = { succeeded: number; failed: number; rejected: number; charged: string; cost: string; profit: string };
type DayRow = Figures & { date: string; pixelforge_cost: string | null; pixelforge_generations: number | null; match: boolean | null; delta: string | null };
type UserRow = Figures & { user_id: number; name: string; email: string };
type Paginated<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
type ChartPoint = { date: string; cost: number; profit: number; cumulative_profit: number; succeeded: number; failed: number; rejected: number; mismatch: boolean };

const props = defineProps<{
    filters: { from: string; to: string; q: string | null; mismatches: boolean; tab: 'days' | 'users'; sort: string; dir: 'asc' | 'desc' };
    summary: Figures & { verified: boolean; filtered: boolean; checked_at: string | null; mismatched_days: number; quiet_days: number; pixelforge_cost: string | null };
    days: Paginated<DayRow>;
    users: Paginated<UserRow>;
    dayUsers?: Paginated<UserRow> | null;
    chart: ChartPoint[];
    topUsers: UserRow[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Admin', href: report() }] } });

// --- Filters live in the URL ---------------------------------------------------------------------
const form = ref({ from: props.filters.from, to: props.filters.to, q: props.filters.q ?? '', mismatches: props.filters.mismatches });
const query = (overrides: Record<string, unknown> = {}) => ({
    from: form.value.from,
    to: form.value.to,
    q: form.value.q || undefined,
    mismatches: form.value.mismatches ? 1 : undefined,
    tab: props.filters.tab,
    sort: props.filters.sort,
    dir: props.filters.dir,
    ...overrides,
});
const apply = (overrides: Record<string, unknown> = {}) =>
    router.get(report().url, query(overrides), { preserveState: true, preserveScroll: true, replace: true });
const applySearch = useDebounceFn(() => apply(), 350);
const exportUrl = computed(() => {
    const params = Object.fromEntries(Object.entries(query()).filter(([, value]) => value !== undefined).map(([key, value]) => [key, String(value)]));

    return exportReport({ query: params }).url;
});

// --- Expanding a day into its users ----------------------------------------------------------------
const openDay = ref<string | null>(null);
function toggleDay(date: string): void {
    openDay.value = openDay.value === date ? null : date;
    if (openDay.value) {
        router.reload({ only: ['dayUsers'], data: { day: date, day_page: 1 }, preserveUrl: true });
    }
}

function sortBy(column: string): void {
    apply({ tab: 'users', sort: column, dir: props.filters.sort === column && props.filters.dir === 'desc' ? 'asc' : 'desc' });
}

// --- Figures ----------------------------------------------------------------------------------------
const margin = computed(() => {
    const charged = toNano(props.summary.charged);
    return charged === 0n ? null : Number((toNano(props.summary.profit) * 1000n) / charged) / 10;
});
const short = (date: string) => new Date(`${date}T00:00:00Z`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', timeZone: 'UTC' });
const dollars = (value: number) => `$${value.toFixed(value >= 1 ? 2 : 3)}`;
const mismatchedInRange = computed(() => props.chart.filter((d) => d.mismatch));
const topMax = computed(() => Math.max(1, ...props.topUsers.map((u) => Number(u.charged))));

const moneyConfig = {
    cost: { label: 'Pixelforge cost', color: 'var(--chart-2)' },
    profit: { label: 'Profit', color: 'var(--chart-1)' },
} satisfies ChartConfig;
const outcomeConfig = {
    succeeded: { label: 'Succeeded', color: 'var(--chart-1)' },
    failed: { label: 'Failed', color: 'var(--chart-4)' },
    rejected: { label: 'Rejected', color: 'var(--chart-5)' },
} satisfies ChartConfig;
const profitConfig = { cumulative_profit: { label: 'Profit to date', color: 'var(--chart-1)' } } satisfies ChartConfig;

const x = (_: ChartPoint, i: number) => i;
const tick = (i: number) => (props.chart[i] ? short(props.chart[i].date) : '');
const tooltip = (config: ChartConfig) =>
    componentToString(config, ChartTooltipContent, { labelFormatter: (i: number | Date) => (props.chart[Number(i)] ? short(props.chart[Number(i)].date) : '') });
</script>

<template>
    <Head title="Admin report" />

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 pt-10 pb-20 md:px-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Admin report</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Per UTC day, by when each generation finished. Provider costs are checked against Pixelforge's own records.
                </p>
            </div>
            <div class="flex gap-2">
                <Button variant="ghost" size="sm" @click="apply({ refresh: 1 })"><RefreshCw /> Recheck Pixelforge</Button>
                <Button variant="secondary" size="sm" as-child>
                    <a :href="exportUrl"><Download /> Export CSV</a>
                </Button>
            </div>
        </div>

        <!-- Filters -->
        <form class="flex flex-wrap items-center gap-2 rounded-xl border bg-card p-2" @submit.prevent="apply()">
            <label class="flex items-center gap-2 text-xs text-muted-foreground">
                From <Input v-model="form.from" type="date" class="h-8 w-38 rounded-lg" :max="form.to" @change="apply()" />
            </label>
            <label class="flex items-center gap-2 text-xs text-muted-foreground">
                to <Input v-model="form.to" type="date" class="h-8 w-38 rounded-lg" :min="form.from" @change="apply()" />
            </label>
            <span class="text-xs text-muted-foreground">UTC</span>
            <Input v-model="form.q" type="search" placeholder="Filter by user name or email" class="h-8 min-w-56 flex-1 rounded-lg" aria-label="Filter by user" @input="applySearch" />
            <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-xs">
                <input v-model="form.mismatches" type="checkbox" class="accent-primary" @change="apply()" />
                Only days that don't match Pixelforge
            </label>
        </form>
        <p v-if="$page.props.errors.from || $page.props.errors.to" class="-mt-3 text-sm text-destructive">{{ $page.props.errors.from ?? $page.props.errors.to }}</p>

        <!-- Figures -->
        <section class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border bg-border lg:grid-cols-5" aria-label="Totals">
            <div class="bg-card p-4">
                <p class="text-xs text-muted-foreground">Charged to users</p>
                <p class="receipt mt-1 text-xl font-medium" :title="exact(summary.charged)">{{ usd(summary.charged) }}</p>
            </div>
            <div class="bg-card p-4">
                <p class="text-xs text-muted-foreground">Pixelforge cost</p>
                <p class="receipt mt-1 text-xl font-medium" :title="exact(summary.cost)">{{ usd(summary.cost) }}</p>
            </div>
            <div class="bg-card p-4">
                <p class="text-xs text-muted-foreground">Profit</p>
                <p class="receipt mt-1 text-xl font-medium" :title="exact(summary.profit)">
                    {{ usd(summary.profit) }}<span v-if="margin !== null" class="ml-1.5 text-xs font-normal text-muted-foreground">{{ margin }}% margin</span>
                </p>
            </div>
            <div class="bg-card p-4">
                <p class="text-xs text-muted-foreground">Generations</p>
                <p class="receipt mt-1 text-xl font-medium">
                    {{ summary.succeeded }}
                    <span class="text-xs font-normal text-muted-foreground">billed · {{ summary.failed }} failed · {{ summary.rejected }} rejected</span>
                </p>
            </div>
            <div class="col-span-2 bg-card p-4 lg:col-span-1">
                <p class="text-xs text-muted-foreground">Matches Pixelforge</p>
                <p v-if="summary.filtered" class="mt-1 text-sm text-muted-foreground">Clear the user filter to compare totals</p>
                <p v-else-if="!summary.verified" class="mt-1 flex items-center gap-1.5 text-sm text-destructive"><TriangleAlert class="size-4" /> Couldn't reach Pixelforge</p>
                <p v-else-if="summary.mismatched_days === 0" class="mt-1 flex items-center gap-1.5 text-sm font-medium text-success"><Check class="size-4" /> Every day, to the nanodollar</p>
                <p v-else class="mt-1 flex items-center gap-1.5 text-sm font-medium text-destructive"><TriangleAlert class="size-4" /> {{ summary.mismatched_days }} day(s) differ</p>
                <p v-if="summary.checked_at" class="mt-0.5 text-[11px] text-muted-foreground">Checked {{ new Date(summary.checked_at).toLocaleTimeString() }}</p>
            </div>
        </section>

        <!-- Main chart -->
        <section class="rounded-xl border bg-card p-5" aria-labelledby="money-title">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="money-title" class="text-sm font-medium">Charged per day <span class="font-normal text-muted-foreground">= Pixelforge cost + profit</span></h2>
                <div class="flex items-center gap-4 text-xs text-muted-foreground">
                    <span v-for="(item, key) in moneyConfig" :key="key" class="flex items-center gap-1.5">
                        <span class="size-2 rounded-sm" :style="{ background: item.color }" />{{ item.label }}
                    </span>
                </div>
            </div>
            <ChartContainer :config="moneyConfig" class="mt-4 aspect-auto h-64" cursor>
                <VisXYContainer :data="chart" :margin="{ left: 4, right: 4 }" :y-domain="[0, undefined]">
                    <VisStackedBar :x="x" :y="[(d: ChartPoint) => d.cost, (d: ChartPoint) => d.profit]" :color="[moneyConfig.cost.color, moneyConfig.profit.color]" :rounded-corners="3" :bar-padding="0.25" />
                    <VisAxis type="x" :x="x" :tick-line="false" :domain-line="false" :grid-line="false" :num-ticks="8" :tick-format="tick" />
                    <VisAxis type="y" :num-ticks="4" :tick-line="false" :domain-line="false" :tick-format="dollars" />
                    <ChartTooltip />
                    <ChartCrosshair :template="tooltip(moneyConfig)" :color="[moneyConfig.cost.color, moneyConfig.profit.color]" />
                </VisXYContainer>
            </ChartContainer>
            <p v-if="mismatchedInRange.length" class="mt-3 flex flex-wrap items-center gap-1.5 text-xs text-destructive">
                <TriangleAlert class="size-3.5" /> Differs from Pixelforge on {{ mismatchedInRange.map((d) => short(d.date)).join(', ') }}
            </p>
        </section>

        <section class="grid gap-4 lg:grid-cols-3">
            <div class="rounded-xl border bg-card p-5">
                <h2 class="text-sm font-medium">Outcomes per day</h2>
                <ChartContainer :config="outcomeConfig" class="mt-4 aspect-auto h-40" cursor>
                    <VisXYContainer :data="chart" :margin="{ left: 4, right: 4 }">
                        <VisStackedBar :x="x" :y="[(d: ChartPoint) => d.succeeded, (d: ChartPoint) => d.failed, (d: ChartPoint) => d.rejected]" :color="[outcomeConfig.succeeded.color, outcomeConfig.failed.color, outcomeConfig.rejected.color]" :rounded-corners="2" :bar-padding="0.25" />
                        <VisAxis type="x" :x="x" :tick-line="false" :domain-line="false" :grid-line="false" :num-ticks="4" :tick-format="tick" />
                        <VisAxis type="y" :num-ticks="3" :tick-line="false" :domain-line="false" />
                        <ChartTooltip />
                        <ChartCrosshair :template="tooltip(outcomeConfig)" :color="[outcomeConfig.succeeded.color, outcomeConfig.failed.color, outcomeConfig.rejected.color]" />
                    </VisXYContainer>
                </ChartContainer>
            </div>

            <div class="rounded-xl border bg-card p-5">
                <h2 class="text-sm font-medium">Profit to date</h2>
                <ChartContainer :config="profitConfig" class="mt-4 aspect-auto h-40" cursor>
                    <VisXYContainer :data="chart" :margin="{ left: 4, right: 4 }" :y-domain="[0, undefined]">
                        <VisArea :x="x" :y="(d: ChartPoint) => d.cumulative_profit" :color="profitConfig.cumulative_profit.color" :opacity="0.15" />
                        <VisLine :x="x" :y="(d: ChartPoint) => d.cumulative_profit" :color="profitConfig.cumulative_profit.color" :line-width="2" />
                        <VisAxis type="x" :x="x" :tick-line="false" :domain-line="false" :grid-line="false" :num-ticks="4" :tick-format="tick" />
                        <VisAxis type="y" :num-ticks="3" :tick-line="false" :domain-line="false" :tick-format="dollars" />
                        <ChartTooltip />
                        <ChartCrosshair :template="tooltip(profitConfig)" :color="profitConfig.cumulative_profit.color" />
                    </VisXYContainer>
                </ChartContainer>
            </div>

            <div class="rounded-xl border bg-card p-5">
                <h2 class="text-sm font-medium">Top users by spend</h2>
                <p v-if="!topUsers.length" class="mt-6 text-sm text-muted-foreground">No charges in this range.</p>
                <ol class="mt-4 flex flex-col gap-3">
                    <li v-for="user in topUsers" :key="user.user_id" class="flex flex-col gap-1">
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="truncate" :title="user.email">{{ user.name }}</span>
                            <span class="receipt shrink-0 text-xs" :title="exact(user.charged)">{{ usd(user.charged) }}</span>
                        </div>
                        <span class="h-1 overflow-hidden rounded-full bg-secondary">
                            <span class="block h-full rounded-full bg-primary" :style="{ width: `${(Number(user.charged) / topMax) * 100}%` }" />
                        </span>
                    </li>
                </ol>
            </div>
        </section>

        <!-- Tables -->
        <section class="flex flex-col gap-3">
            <div class="flex gap-1 self-start rounded-lg bg-raised p-0.5 text-sm" role="tablist">
                <button v-for="tab in (['days', 'users'] as const)" :key="tab" type="button" role="tab" :aria-selected="filters.tab === tab"
                    class="rounded-md px-3 py-1.5 transition-colors" :class="filters.tab === tab ? 'bg-background shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                    @click="apply({ tab })">
                    {{ tab === 'days' ? 'By day' : 'By user' }}
                </button>
            </div>

            <div class="overflow-x-auto rounded-xl border bg-card">
                <table v-if="filters.tab === 'days'" class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr class="border-b">
                            <th class="px-4 py-3 font-normal">Day (UTC)</th>
                            <th class="px-4 py-3 text-right font-normal">Billed · failed · rejected</th>
                            <th class="px-4 py-3 text-right font-normal">Charged</th>
                            <th class="px-4 py-3 text-right font-normal">Pixelforge cost</th>
                            <th class="px-4 py-3 text-right font-normal">Profit</th>
                            <th class="px-4 py-3 text-right font-normal">Pixelforge reports</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="day in days.data" :key="day.date">
                            <tr class="cursor-pointer border-b transition-colors last:border-0 hover:bg-raised/60" :aria-expanded="openDay === day.date" @click="toggleDay(day.date)">
                                <td class="px-4 py-2.5 whitespace-nowrap">
                                    <ChevronRight class="mr-1 inline size-3.5 text-muted-foreground transition-transform" :class="{ 'rotate-90': openDay === day.date }" />
                                    {{ short(day.date) }} <span class="receipt text-xs text-muted-foreground">{{ day.date }}</span>
                                </td>
                                <td class="receipt px-4 py-2.5 text-right text-muted-foreground">{{ day.succeeded }} · {{ day.failed }} · {{ day.rejected }}</td>
                                <td class="receipt px-4 py-2.5 text-right" :title="exact(day.charged)">{{ usd(day.charged) }}</td>
                                <td class="receipt px-4 py-2.5 text-right" :title="exact(day.cost)">{{ usd(day.cost) }}</td>
                                <td class="receipt px-4 py-2.5 text-right" :title="exact(day.profit)">{{ usd(day.profit) }}</td>
                                <td class="receipt px-4 py-2.5 text-right whitespace-nowrap">
                                    <span v-if="day.match === true" class="inline-flex items-center gap-1 text-success" :title="day.pixelforge_cost ? exact(day.pixelforge_cost) : ''"><Check class="size-3.5" /> {{ usd(day.pixelforge_cost!) }}</span>
                                    <span v-else-if="day.match === false" class="text-destructive" :title="`Pixelforge: ${day.pixelforge_cost} for ${day.pixelforge_generations} generation(s). Usually a webhook still on its way; it settles on the next check.`">
                                        {{ usd(day.pixelforge_cost!, { places: 6 }) }} · Δ {{ day.delta }}
                                    </span>
                                    <span v-else class="text-muted-foreground">{{ day.pixelforge_cost ? usd(day.pixelforge_cost) : '—' }}</span>
                                </td>
                            </tr>
                            <tr v-if="openDay === day.date" class="border-b bg-raised/40">
                                <td colspan="6" class="px-4 py-3">
                                    <p v-if="!dayUsers" class="text-xs text-muted-foreground">Loading…</p>
                                    <p v-else-if="!dayUsers.data.length" class="text-xs text-muted-foreground">No finished generations this day.</p>
                                    <table v-else class="w-full text-xs">
                                        <tbody>
                                            <tr v-for="user in dayUsers.data" :key="user.user_id">
                                                <td class="py-1.5 pl-5">{{ user.name }} <span class="text-muted-foreground">{{ user.email }}</span></td>
                                                <td class="receipt py-1.5 text-right text-muted-foreground">{{ user.succeeded }} · {{ user.failed }} · {{ user.rejected }}</td>
                                                <td class="receipt py-1.5 text-right" :title="exact(user.charged)">{{ usd(user.charged) }}</td>
                                                <td class="receipt py-1.5 text-right" :title="exact(user.cost)">{{ usd(user.cost) }}</td>
                                                <td class="receipt py-1.5 pr-1 text-right" :title="exact(user.profit)">{{ usd(user.profit) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <nav v-if="dayUsers && dayUsers.links.length > 3" class="mt-2 flex gap-1 pl-5">
                                        <Link v-for="link in dayUsers.links" :key="link.label" :href="link.url ?? ''" :only="['dayUsers']" preserve-scroll preserve-state
                                            class="receipt rounded-md border px-2 py-0.5 text-[11px]" :class="[link.active ? 'border-primary text-primary' : '', !link.url ? 'pointer-events-none opacity-40' : '']" v-html="link.label" />
                                    </nav>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!days.data.length">
                            <td colspan="6" class="px-4 py-10 text-center text-sm text-muted-foreground">
                                {{ filters.mismatches ? 'Every day in this range matches Pixelforge.' : 'No generations finished in this range.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <table v-else class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr class="border-b">
                            <th class="px-4 py-3 font-normal">User</th>
                            <th v-for="[column, label] in [['generations', 'Billed · failed · rejected'], ['charged', 'Charged'], ['cost', 'Pixelforge cost'], ['profit', 'Profit']]" :key="column" class="px-4 py-3 text-right font-normal">
                                <button type="button" class="hover:text-foreground" :class="{ 'text-foreground': filters.sort === column }" @click="sortBy(column)">
                                    {{ label }}<span v-if="filters.sort === column">{{ filters.dir === 'desc' ? ' ↓' : ' ↑' }}</span>
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="user in users.data" :key="user.user_id" class="transition-colors hover:bg-raised/60">
                            <td class="px-4 py-2.5">{{ user.name }} <span class="text-xs text-muted-foreground">{{ user.email }}</span></td>
                            <td class="receipt px-4 py-2.5 text-right text-muted-foreground">{{ user.succeeded }} · {{ user.failed }} · {{ user.rejected }}</td>
                            <td class="receipt px-4 py-2.5 text-right" :title="exact(user.charged)">{{ usd(user.charged) }}</td>
                            <td class="receipt px-4 py-2.5 text-right" :title="exact(user.cost)">{{ usd(user.cost) }}</td>
                            <td class="receipt px-4 py-2.5 text-right" :title="exact(user.profit)">{{ usd(user.profit) }}</td>
                        </tr>
                        <tr v-if="!users.data.length">
                            <td colspan="5" class="px-4 py-10 text-center text-sm text-muted-foreground">No users with finished generations in this range.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-if="filters.tab === 'days' && summary.quiet_days" class="text-xs text-muted-foreground">
                {{ summary.quiet_days }} quiet day(s) with no generations here or at Pixelforge are left out of the table.
            </p>
            <nav v-for="pages in [filters.tab === 'days' ? days : users]" :key="filters.tab" v-show="pages.links.length > 3" class="flex flex-wrap gap-1" aria-label="Pages">
                <Link v-for="link in pages.links" :key="link.label" :href="link.url ?? ''" preserve-scroll preserve-state
                    class="receipt rounded-lg border px-2.5 py-1 text-xs" :class="[link.active ? 'border-primary bg-accent text-accent-foreground' : '', !link.url ? 'pointer-events-none opacity-40' : '']" v-html="link.label" />
            </nav>
        </section>
    </div>
</template>
