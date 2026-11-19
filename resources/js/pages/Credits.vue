<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { exact, usd } from '@/lib/money';
import { credits } from '@/routes';
import { store } from '@/routes/credits';
import type { Credits } from '@/types';

defineOptions({ layout: { breadcrumbs: [{ title: 'Credits', href: credits() }] } });

type Transaction = {
    id: number;
    type: 'purchase' | 'charge';
    amount: string;
    balance_after: string;
    prompt: string | null;
    created_at: string;
};

defineProps<{
    packs: number[];
    transactions: {
        data: Transaction[];
        links: { url: string | null; label: string; active: boolean }[];
    };
}>();

const page = usePage<{ credits: Credits }>();
const wallet = computed(() => page.props.credits);
const buying = ref<number | null>(null);

function buy(pack: number): void {
    if (buying.value !== null) {
        return;
    }
    buying.value = pack;
    // A fresh key per click; a retried or doubled request with the same key buys once.
    router.post(
        store().url,
        { pack, idempotency_key: crypto.randomUUID() },
        { preserveScroll: true, onFinish: () => (buying.value = null) },
    );
}

const when = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
</script>

<template>
    <Head title="Credits" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-10 px-4 py-6 md:px-8 md:py-10">
        <section class="grid gap-6 rounded-sm border bg-card p-6 md:grid-cols-[1fr_auto] md:items-end">
            <div class="flex flex-col gap-1">
                <h1 class="text-sm text-muted-foreground">Balance</h1>
                <p
                    class="receipt text-4xl font-medium tracking-tight md:text-5xl"
                    :class="{ 'text-destructive': wallet.balance.startsWith('-') }"
                    :title="exact(wallet.balance)"
                >
                    {{ usd(wallet.balance) }}
                </p>
                <p class="receipt text-xs text-muted-foreground">
                    <span :title="exact(wallet.held)">{{ usd(wallet.held) }} held</span> for generations in progress ·
                    <span :title="exact(wallet.available)">{{ usd(wallet.available) }} available</span>
                </p>
                <p v-if="wallet.balance.startsWith('-')" class="mt-2 max-w-prose text-sm text-destructive">
                    Pixelforge raised its prices while a generation was running, so it cost more than the amount held.
                    Top up to keep generating.
                </p>
            </div>

            <div class="flex flex-col gap-2">
                <span class="text-sm font-medium">Buy credits</span>
                <div class="flex gap-2">
                    <Button
                        v-for="pack in packs"
                        :key="pack"
                        variant="outline"
                        class="receipt min-w-20"
                        :disabled="buying !== null"
                        @click="buy(pack)"
                    >
                        <Spinner v-if="buying === pack" />
                        +${{ pack }}
                    </Button>
                </div>
                <span class="text-xs text-muted-foreground">A pretend purchase: no payment is taken.</span>
            </div>
        </section>

        <section class="flex flex-col gap-3" aria-labelledby="ledger-title">
            <h2 id="ledger-title" class="font-display text-xl">Statement</h2>

            <p v-if="!transactions.data.length" class="py-10 text-center text-sm text-muted-foreground">
                No transactions yet. Purchases and generation charges appear here.
            </p>

            <div v-else class="overflow-x-auto rounded-sm border bg-card">
                <table class="w-full text-sm">
                    <thead class="border-b text-left text-xs text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">When</th>
                            <th class="px-4 py-2.5 font-medium">What</th>
                            <th class="px-4 py-2.5 text-right font-medium">Amount</th>
                            <th class="px-4 py-2.5 text-right font-medium">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="transaction in transactions.data" :key="transaction.id">
                            <td class="px-4 py-2.5 whitespace-nowrap text-muted-foreground">{{ when(transaction.created_at) }}</td>
                            <td class="max-w-80 truncate px-4 py-2.5" :title="transaction.prompt ?? undefined">
                                <template v-if="transaction.type === 'purchase'">Bought credits</template>
                                <template v-else>Generation · <span class="text-muted-foreground">{{ transaction.prompt }}</span></template>
                            </td>
                            <td
                                class="receipt px-4 py-2.5 text-right whitespace-nowrap"
                                :class="transaction.type === 'purchase' ? 'text-success' : ''"
                                :title="exact(transaction.amount)"
                            >
                                {{ transaction.type === 'purchase' ? '+' : '' }}{{ usd(transaction.amount) }}
                            </td>
                            <td class="receipt px-4 py-2.5 text-right whitespace-nowrap text-muted-foreground" :title="exact(transaction.balance_after)">
                                {{ usd(transaction.balance_after) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav v-if="transactions.links.length > 3" class="flex flex-wrap gap-1" aria-label="Statement pages">
                <Link
                    v-for="link in transactions.links"
                    :key="link.label"
                    :href="link.url ?? ''"
                    preserve-scroll
                    class="receipt rounded-sm border px-2.5 py-1 text-xs"
                    :class="[link.active ? 'border-primary bg-accent text-accent-foreground' : '', !link.url ? 'pointer-events-none opacity-40' : '']"
                    v-html="link.label"
                />
            </nav>
        </section>
    </div>
</template>
