<script setup lang="ts">
import { AlertTriangle, RotateCcw } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { exact, usd } from '@/lib/money';
import type { Generation } from '@/types';

const props = defineProps<{ generation: Generation }>();
defineEmits<{ open: [generation: Generation]; retry: [generation: Generation] }>();

const g = computed(() => props.generation);

const stage = computed(
    () =>
        ({
            pending: 'Waiting for a slot',
            queued: 'Queued',
            processing: 'Generating',
            succeeded: 'Done',
            failed: 'Failed',
        })[g.value.status],
);
</script>

<template>
    <article class="group relative aspect-square overflow-hidden rounded-xl border bg-card">
        <!-- Finished: the image is the tile. -->
        <button
            v-if="g.status === 'succeeded'"
            type="button"
            class="block size-full cursor-zoom-in focus-visible:outline-none"
            :aria-label="`Open details: ${g.prompt}`"
            @click="$emit('open', g)"
        >
            <img
                v-if="g.image"
                :src="g.image"
                :alt="g.prompt"
                loading="lazy"
                class="size-full object-cover transition-[filter] duration-200 group-hover:brightness-110"
            />
            <span v-else class="grid size-full place-items-center bg-raised text-xs text-muted-foreground">
                Image link expired
            </span>
            <span
                class="absolute inset-x-0 bottom-0 flex items-end justify-between gap-3 bg-linear-to-t from-black/80 via-black/40 to-transparent px-3 pt-10 pb-2.5 text-left text-white"
            >
                <span class="line-clamp-1 text-[13px] leading-snug">{{ g.prompt }}</span>
                <span class="receipt shrink-0 text-xs text-white/85" :title="g.charge ? exact(g.charge) : undefined">
                    {{ g.charge ? usd(g.charge) : '' }}
                </span>
            </span>
        </button>

        <!-- In flight: a print developing. -->
        <button
            v-else-if="g.in_flight"
            type="button"
            class="developing flex size-full flex-col justify-between p-3.5 text-left"
            :aria-label="`${stage}: ${g.prompt}`"
            @click="$emit('open', g)"
        >
            <span class="inline-flex items-center gap-1.5 self-start rounded-full bg-background/70 px-2.5 py-1 text-xs font-medium backdrop-blur">
                <span class="size-1.5 animate-pulse rounded-full bg-primary motion-reduce:animate-none" />
                {{ stage }}
            </span>
            <span class="flex flex-col gap-1">
                <span class="line-clamp-2 text-sm">{{ g.prompt }}</span>
                <span class="receipt text-xs text-muted-foreground" :title="exact(g.estimate)">
                    up to {{ usd(g.estimate, { round: 'up' }) }}
                </span>
            </span>
        </button>

        <!-- Failed: free, with the reason and a way forward. -->
        <div v-else class="flex size-full flex-col justify-between bg-raised p-3.5">
            <span class="inline-flex items-center gap-1.5 self-start rounded-full bg-destructive/12 px-2.5 py-1 text-xs font-medium text-destructive">
                <AlertTriangle class="size-3.5" /> Failed · not charged
            </span>
            <span class="flex flex-col gap-2">
                <span class="line-clamp-2 text-sm text-muted-foreground">{{ g.prompt }}</span>
                <span class="line-clamp-3 text-xs leading-relaxed text-foreground/80">{{ g.failure_reason }}</span>
                <span class="flex gap-2">
                    <Button size="sm" variant="secondary" @click="$emit('retry', g)"><RotateCcw /> Try again</Button>
                    <Button size="sm" variant="ghost" @click="$emit('open', g)">Details</Button>
                </span>
            </span>
        </div>
    </article>
</template>
