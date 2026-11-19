<script setup lang="ts">
import { RotateCcw } from '@lucide/vue';
import { useTimeAgo } from '@vueuse/core';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { exact, usd } from '@/lib/money';
import type { Generation } from '@/types';

const props = defineProps<{ generation: Generation }>();
defineEmits<{ retry: [generation: Generation] }>();

const g = computed(() => props.generation);
const created = useTimeAgo(() => g.value.created_at);
const [width, height] = g.value.size.split('x').map(Number);

const stage = computed(
    () =>
        ({
            pending: 'Waiting for a free slot',
            queued: 'Queued at Pixelforge',
            processing: 'Developing',
            succeeded: 'Done',
            failed: 'Failed · free',
        })[g.value.status],
);
</script>

<template>
    <article
        class="group flex flex-col gap-3 rounded-sm border bg-card p-3 pb-4 shadow-[0_1px_0_var(--border),0_8px_24px_-18px_oklch(0.2_0.02_60/0.35)]"
        :aria-busy="g.in_flight"
    >
        <div
            class="relative overflow-hidden rounded-[2px] bg-muted"
            :style="{ aspectRatio: `${width} / ${height}` }"
        >
            <img
                v-if="g.image"
                :src="g.image"
                :alt="g.prompt"
                loading="lazy"
                class="size-full object-cover"
            />
            <div
                v-else-if="g.in_flight"
                class="developing absolute inset-0 grid place-items-center"
            >
                <span class="receipt text-xs tracking-wide text-muted-foreground">
                    {{ stage }}…
                </span>
            </div>
            <div
                v-else-if="g.status === 'failed'"
                class="absolute inset-0 grid place-items-center bg-[repeating-linear-gradient(-45deg,transparent_0_10px,color-mix(in_oklch,var(--destructive)_7%,transparent)_10px_20px)]"
            >
                <span
                    class="-rotate-6 rounded-[2px] border-2 border-destructive px-3 py-1 font-display text-lg font-semibold text-destructive italic"
                >
                    Not charged
                </span>
            </div>
            <div v-else class="absolute inset-0 grid place-items-center">
                <span class="text-xs text-muted-foreground">Image link expired</span>
            </div>
        </div>

        <p class="line-clamp-2 font-display text-[15px] leading-snug" :title="g.prompt">
            {{ g.prompt }}
        </p>

        <dl class="receipt grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-xs">
            <dt class="text-muted-foreground">Status</dt>
            <dd
                class="text-right"
                :class="{
                    'text-success': g.status === 'succeeded',
                    'text-destructive': g.status === 'failed',
                    'text-primary': g.in_flight,
                }"
            >
                <span v-if="g.in_flight" class="mr-1 inline-block size-1.5 animate-pulse rounded-full bg-primary align-middle motion-reduce:animate-none" />
                {{ stage }}
            </dd>
            <dt class="text-muted-foreground">Estimate</dt>
            <dd class="text-right" :title="exact(g.estimate)">
                up to {{ usd(g.estimate, { round: 'up' }) }}
            </dd>
            <dt class="text-muted-foreground">Charged</dt>
            <dd
                class="text-right font-medium"
                :title="g.charge ? exact(g.charge) : undefined"
            >
                <template v-if="g.charge">{{ usd(g.charge) }}</template>
                <template v-else-if="g.status === 'failed'">$0.0000</template>
                <template v-else>—</template>
            </dd>
            <dt class="text-muted-foreground">Spec</dt>
            <dd class="text-right text-muted-foreground">
                {{ g.size }} · {{ g.quality }}<template v-if="g.reference_images.length">
                    · {{ g.reference_images.length }} ref</template>
            </dd>
        </dl>

        <div
            v-if="g.status === 'failed'"
            class="flex items-start justify-between gap-3 border-t border-dashed pt-3 text-xs"
        >
            <p class="text-muted-foreground">{{ g.failure_reason }}</p>
            <Button size="sm" variant="outline" class="shrink-0" @click="$emit('retry', g)">
                <RotateCcw /> Try again
            </Button>
        </div>

        <time
            class="receipt -mt-1 text-[11px] text-muted-foreground"
            :datetime="g.created_at"
            :title="new Date(g.created_at).toLocaleString()"
        >
            {{ created }}
        </time>
    </article>
</template>
