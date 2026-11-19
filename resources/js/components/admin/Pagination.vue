<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    links: { url: string | null; label: string; active: boolean }[];
    total?: number;
}>();
</script>

<template>
    <nav
        v-if="links.length > 3"
        class="flex flex-wrap items-center gap-1"
        aria-label="Pages"
    >
        <Link
            v-for="link in links"
            :key="link.label"
            :href="link.url ?? ''"
            preserve-scroll
            preserve-state
            class="receipt rounded-lg border px-2.5 py-1 text-xs"
            :class="[
                link.active
                    ? 'border-primary bg-accent text-accent-foreground'
                    : '',
                !link.url ? 'pointer-events-none opacity-40' : '',
            ]"
            v-html="link.label"
        />
        <span
            v-if="total !== undefined"
            class="ml-2 text-xs text-muted-foreground"
            >{{ total.toLocaleString() }} total</span
        >
    </nav>
</template>
