<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { ref } from 'vue';
import Pagination from '@/components/admin/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { exact, usd } from '@/lib/money';
import { users as usersRoute } from '@/routes/admin';

type Row = {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    balance: string;
    held: string;
    available: string;
    purchased: string;
    spent: string;
    generations: number;
    in_flight: number;
    joined_at: string | null;
};

const props = defineProps<{
    filters: { q: string | null };
    users: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Users', href: usersRoute() }] },
});

const q = ref(props.filters.q ?? '');
const search = useDebounceFn(
    () =>
        router.get(
            usersRoute().url,
            { q: q.value || undefined },
            { preserveState: true, replace: true },
        ),
    350,
);
</script>

<template>
    <Head title="Users" />
    <div class="flex flex-col gap-5 px-4 pt-6 pb-16 md:px-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Users</h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Balances are exact; "held" is reserved for generations still in
                progress.
            </p>
        </div>
        <Input
            v-model="q"
            type="search"
            placeholder="Search name or email"
            class="h-8 max-w-80 rounded-lg"
            aria-label="Search users"
            @input="search"
        />

        <div class="overflow-x-auto rounded-xl border bg-card">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted-foreground">
                    <tr class="border-b">
                        <th class="px-4 py-3 font-normal">User</th>
                        <th class="px-4 py-3 text-right font-normal">
                            Balance
                        </th>
                        <th class="px-4 py-3 text-right font-normal">Held</th>
                        <th class="px-4 py-3 text-right font-normal">
                            Available
                        </th>
                        <th class="px-4 py-3 text-right font-normal">Bought</th>
                        <th class="px-4 py-3 text-right font-normal">Spent</th>
                        <th class="px-4 py-3 text-right font-normal">
                            Generations
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="user in users.data"
                        :key="user.id"
                        class="transition-colors hover:bg-raised/60"
                    >
                        <td class="px-4 py-2.5">
                            <span class="flex items-center gap-2"
                                >{{ user.name }}
                                <span
                                    v-if="user.is_admin"
                                    class="rounded-full bg-accent px-2 py-0.5 text-[11px] text-accent-foreground"
                                    >Admin</span
                                >
                            </span>
                            <span class="text-xs text-muted-foreground">{{
                                user.email
                            }}</span>
                        </td>
                        <td
                            class="receipt px-4 py-2.5 text-right"
                            :class="{
                                'text-destructive':
                                    user.balance.startsWith('-'),
                            }"
                            :title="exact(user.balance)"
                        >
                            {{ usd(user.balance) }}
                        </td>
                        <td
                            class="receipt px-4 py-2.5 text-right text-muted-foreground"
                            :title="exact(user.held)"
                        >
                            {{ usd(user.held) }}
                        </td>
                        <td
                            class="receipt px-4 py-2.5 text-right"
                            :title="exact(user.available)"
                        >
                            {{ usd(user.available) }}
                        </td>
                        <td
                            class="receipt px-4 py-2.5 text-right text-muted-foreground"
                            :title="exact(user.purchased)"
                        >
                            {{ usd(user.purchased) }}
                        </td>
                        <td
                            class="receipt px-4 py-2.5 text-right text-muted-foreground"
                            :title="exact(user.spent)"
                        >
                            {{ usd(user.spent) }}
                        </td>
                        <td class="receipt px-4 py-2.5 text-right">
                            {{ user.generations
                            }}<span v-if="user.in_flight" class="text-primary">
                                · {{ user.in_flight }} running</span
                            >
                        </td>
                    </tr>
                    <tr v-if="!users.data.length">
                        <td colspan="7" class="px-4 py-12 text-center">
                            <p class="text-sm">
                                No users match "{{ filters.q }}"
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Search matches names and email addresses. Try
                                part of an email.
                            </p>
                            <Button
                                size="sm"
                                variant="secondary"
                                class="mt-3"
                                @click="
                                    q = '';
                                    search();
                                "
                                >Clear search</Button
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Pagination :links="users.links" :total="users.total" />
    </div>
</template>
