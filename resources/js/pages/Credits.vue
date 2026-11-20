<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { exact, usd } from '@/lib/money';
import { credits } from '@/routes';
import { store } from '@/routes/credits';
import type { Credits } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Credits', href: credits() }] },
});

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

// One key per intended purchase. It's only replaced once that purchase is confirmed (or a different pack is
// chosen), so retrying after a lost response ("couldn't reach the server") can't buy the same pack twice.
let intent: { pack: number; key: string } | null = null;

function buy(pack: number): void {
    if (buying.value !== null) {
        return;
    }
    if (intent?.pack !== pack) {
        intent = { pack, key: crypto.randomUUID() };
    }
    buying.value = pack;
    router.post(
        store().url,
        { pack, idempotency_key: intent.key },
        {
            preserveScroll: true,
            onSuccess: () => (intent = null),
            onFinish: () => (buying.value = null),
        },
    );
}

const when = (iso: string) =>
    new Date(iso).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
</script>

<template>
    <Head title="Credits" />

    <div
        class="mx-auto flex w-full max-w-5xl flex-col gap-10 px-4 pt-10 pb-20 md:px-6"
    >
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Credits</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Credits are US dollars. Generations are charged their real cost
                when they finish.
            </p>
        </div>

        <section class="grid gap-4 lg:grid-cols-[1.1fr_1fr]">
            <div
                class="flex flex-col justify-between gap-8 rounded-2xl border bg-card p-6"
            >
                <div>
                    <h2 class="text-sm text-muted-foreground">
                        Available to spend
                    </h2>
                    <p
                        class="receipt mt-2 text-5xl font-medium tracking-tight"
                        :class="{
                            'text-destructive':
                                wallet.available.startsWith('-'),
                        }"
                        :title="exact(wallet.available)"
                    >
                        {{ usd(wallet.available) }}
                    </p>
                </div>
                <dl
                    class="receipt grid grid-cols-2 gap-4 border-t pt-4 text-sm"
                >
                    <div>
                        <dt class="font-sans text-xs text-muted-foreground">
                            Balance
                        </dt>
                        <dd class="mt-0.5" :title="exact(wallet.balance)">
                            {{ usd(wallet.balance) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-sans text-xs text-muted-foreground">
                            Held for generations in progress
                        </dt>
                        <dd class="mt-0.5" :title="exact(wallet.held)">
                            {{ usd(wallet.held) }}
                        </dd>
                    </div>
                </dl>
                <p
                    v-if="wallet.balance.startsWith('-')"
                    class="rounded-lg bg-destructive/10 p-3 text-sm text-destructive"
                >
                    Pixelforge raised its prices while a generation was running,
                    so it cost a little more than we held. Top up to keep
                    generating.
                </p>
            </div>

            <div class="flex flex-col gap-3 rounded-2xl border bg-card p-6">
                <h2 class="text-sm font-medium">Buy credits</h2>
                <div class="grid grid-cols-3 gap-2">
                    <button
                        v-for="pack in packs"
                        :key="pack"
                        type="button"
                        class="group flex flex-col items-start gap-6 rounded-xl border bg-raised p-4 text-left transition-colors hover:border-primary/60 disabled:opacity-60"
                        :disabled="buying !== null"
                        @click="buy(pack)"
                    >
                        <span class="receipt text-2xl font-medium"
                            >${{ pack }}</span
                        >
                        <span
                            class="flex items-center gap-1.5 text-xs text-muted-foreground group-hover:text-primary"
                        >
                            <Spinner v-if="buying === pack" class="size-3" />
                            {{ buying === pack ? 'Adding…' : 'Add' }}
                        </span>
                    </button>
                </div>
                <p
                    class="mt-auto flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <Check class="size-3.5" /> A pretend purchase: no payment is
                    taken.
                </p>
            </div>
        </section>

        <section class="flex flex-col gap-3" aria-labelledby="ledger-title">
            <h2 id="ledger-title" class="text-sm font-medium">Statement</h2>

            <p
                v-if="!transactions.data.length"
                class="rounded-2xl border border-dashed py-12 text-center text-sm text-muted-foreground"
            >
                No transactions yet. Purchases and generation charges appear
                here.
            </p>

            <div v-else class="overflow-x-auto rounded-2xl border bg-card">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr class="border-b">
                            <th class="px-5 py-3 font-normal">Date</th>
                            <th class="px-5 py-3 font-normal">Description</th>
                            <th class="px-5 py-3 text-right font-normal">
                                Amount
                            </th>
                            <th class="px-5 py-3 text-right font-normal">
                                Balance
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="transaction in transactions.data"
                            :key="transaction.id"
                            class="transition-colors hover:bg-raised/60"
                        >
                            <td
                                class="px-5 py-3 whitespace-nowrap text-muted-foreground"
                            >
                                {{ when(transaction.created_at) }}
                            </td>
                            <td
                                class="max-w-96 truncate px-5 py-3"
                                :title="transaction.prompt ?? undefined"
                            >
                                <template v-if="transaction.type === 'purchase'"
                                    >Credits added</template
                                >
                                <template v-else
                                    ><span class="text-muted-foreground"
                                        >Generation ·</span
                                    >
                                    {{ transaction.prompt }}</template
                                >
                            </td>
                            <td
                                class="receipt px-5 py-3 text-right whitespace-nowrap"
                                :class="
                                    transaction.type === 'purchase'
                                        ? 'text-success'
                                        : ''
                                "
                                :title="exact(transaction.amount)"
                            >
                                {{ transaction.type === 'purchase' ? '+' : ''
                                }}{{ usd(transaction.amount) }}
                            </td>
                            <td
                                class="receipt px-5 py-3 text-right whitespace-nowrap text-muted-foreground"
                                :title="exact(transaction.balance_after)"
                            >
                                {{ usd(transaction.balance_after) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav
                v-if="transactions.links.length > 3"
                class="flex flex-wrap gap-1"
                aria-label="Statement pages"
            >
                <Link
                    v-for="link in transactions.links"
                    :key="link.label"
                    :href="link.url ?? ''"
                    preserve-scroll
                    class="receipt rounded-lg border px-2.5 py-1 text-xs"
                    :class="[
                        link.active
                            ? 'border-primary bg-accent text-accent-foreground'
                            : '',
                        !link.url ? 'pointer-events-none opacity-40' : '',
                    ]"
                    v-html="link.label"
                />
            </nav>
        </section>
    </div>
</template>
