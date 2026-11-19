<script setup lang="ts">
import { Check, ExternalLink, RotateCcw } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { exact, toNano, usd } from '@/lib/money';
import type { Generation } from '@/types';

const props = defineProps<{ generation: Generation | null }>();
const emit = defineEmits<{ close: []; retry: [generation: Generation] }>();

const g = computed(() => props.generation);

const TOKEN_LABELS: Record<string, string> = {
    input_text_tokens: 'Prompt text',
    input_image_tokens: 'Reference images',
    output_tokens: 'Image output',
    reasoning_tokens: 'Model reasoning',
};

/** Each token type × its rate, exactly as Pixelforge bills it (rates are per 1M tokens). */
const lines = computed(() => {
    const usage = g.value?.usage;
    const rates = g.value?.rates;
    if (!usage || !rates) {
        return [];
    }

    return Object.entries(TOKEN_LABELS).map(([type, label]) => {
        const tokens = usage[type] ?? 0;
        const [whole, fraction = ''] = rates[type].split('.');
        const nanoPerToken =
            BigInt(whole) * 1000n + BigInt(fraction.padEnd(3, '0').slice(0, 3));

        return {
            label,
            tokens,
            rate: rates[type],
            cost: BigInt(tokens) * nanoPerToken,
        };
    });
});

const fee = computed(() =>
    g.value?.charge && g.value.provider_cost
        ? toNano(g.value.charge) - toNano(g.value.provider_cost)
        : null,
);

const STEPS = ['pending', 'queued', 'processing', 'succeeded'] as const;
function reached(step: (typeof STEPS)[number]): boolean {
    const status = g.value?.status;
    if (!status) {
        return false;
    }

    return (
        status === 'succeeded' ||
        STEPS.indexOf(step) <= STEPS.indexOf(status as (typeof STEPS)[number])
    );
}

const when = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'medium',
          })
        : '';
</script>

<template>
    <Dialog
        :open="g !== null"
        @update:open="(open: boolean) => !open && emit('close')"
    >
        <DialogContent
            v-if="g"
            class="max-h-[90svh] gap-0 overflow-y-auto p-0 sm:max-w-4xl"
        >
            <div class="grid md:grid-cols-[1.15fr_1fr]">
                <div
                    class="grid min-h-60 place-items-center bg-black/90 md:rounded-l-lg"
                >
                    <img
                        v-if="g.image"
                        :src="g.image"
                        :alt="g.prompt"
                        class="max-h-[80svh] w-full object-contain"
                    />
                    <span v-else class="p-8 text-center text-sm text-white/60">
                        {{
                            g.status === 'failed'
                                ? 'No image: this generation failed.'
                                : g.in_flight
                                  ? 'Still generating…'
                                  : 'The image link has expired.'
                        }}
                    </span>
                </div>

                <div class="flex flex-col gap-6 p-6">
                    <div class="flex flex-col gap-1.5 pr-6">
                        <DialogTitle
                            class="text-base leading-snug font-medium"
                            >{{ g.prompt }}</DialogTitle
                        >
                        <DialogDescription class="receipt text-xs">
                            {{ g.size }} · {{ g.quality
                            }}<template v-if="g.reference_images.length">
                                · {{ g.reference_images.length }} reference
                                image(s)</template
                            >
                        </DialogDescription>
                    </div>

                    <ol
                        v-if="g.status !== 'failed'"
                        class="grid grid-cols-4 gap-1 text-[11px]"
                        aria-label="Progress"
                    >
                        <li
                            v-for="step in STEPS"
                            :key="step"
                            class="flex flex-col gap-1.5"
                        >
                            <span
                                class="h-1 rounded-full"
                                :class="
                                    reached(step)
                                        ? 'bg-primary'
                                        : 'bg-secondary'
                                "
                            />
                            <span
                                :class="
                                    reached(step)
                                        ? 'text-foreground'
                                        : 'text-muted-foreground'
                                "
                                class="capitalize"
                            >
                                {{
                                    step === 'pending'
                                        ? 'Sent'
                                        : step === 'succeeded'
                                          ? 'Done'
                                          : step
                                }}
                            </span>
                        </li>
                    </ol>
                    <p
                        v-else
                        class="rounded-lg bg-destructive/10 p-3 text-sm text-destructive"
                    >
                        {{ g.failure_reason }} You were not charged.
                    </p>

                    <section
                        class="receipt flex flex-col gap-1.5 text-xs"
                        aria-label="Receipt"
                    >
                        <template v-if="lines.length">
                            <div
                                v-for="line in lines"
                                :key="line.label"
                                class="flex justify-between gap-4"
                            >
                                <span class="text-muted-foreground"
                                    >{{ line.label }} ·
                                    {{ line.tokens.toLocaleString() }} × ${{
                                        line.rate
                                    }}/1M</span
                                >
                                <span>{{ usd(line.cost, { places: 6 }) }}</span>
                            </div>
                            <div
                                class="mt-1 flex justify-between border-t pt-2"
                            >
                                <span class="text-muted-foreground"
                                    >Pixelforge cost</span
                                >
                                <span>{{
                                    g.provider_cost
                                        ? usd(g.provider_cost, { places: 6 })
                                        : ''
                                }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-muted-foreground"
                                    >Service fee (25%)</span
                                >
                                <span>{{
                                    fee !== null ? usd(fee, { places: 6 }) : ''
                                }}</span>
                            </div>
                        </template>
                        <div class="flex justify-between">
                            <span class="text-muted-foreground">Estimated</span>
                            <span :title="exact(g.estimate)"
                                >up to
                                {{ usd(g.estimate, { round: 'up' }) }}</span
                            >
                        </div>
                        <div
                            class="flex items-baseline justify-between border-t pt-2 text-sm font-medium"
                        >
                            <span>Charged</span>
                            <span
                                :title="g.charge ? exact(g.charge) : undefined"
                            >
                                {{
                                    g.charge
                                        ? usd(g.charge, { places: 6 })
                                        : g.status === 'failed'
                                          ? '$0.00'
                                          : 'when it finishes'
                                }}
                            </span>
                        </div>
                    </section>

                    <dl
                        class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-xs text-muted-foreground"
                    >
                        <dt>Requested</dt>
                        <dd class="text-right">{{ when(g.created_at) }}</dd>
                        <template v-if="g.completed_at">
                            <dt>Finished</dt>
                            <dd class="text-right">
                                {{ when(g.completed_at) }}
                            </dd>
                        </template>
                    </dl>

                    <div class="mt-auto flex flex-wrap gap-2">
                        <Button variant="secondary" @click="emit('retry', g)">
                            <RotateCcw v-if="g.status === 'failed'" />
                            <Check v-else />
                            {{
                                g.status === 'failed'
                                    ? 'Try again'
                                    : 'Reuse prompt'
                            }}
                        </Button>
                        <Button v-if="g.image" variant="ghost" as-child>
                            <a :href="g.image" target="_blank" rel="noopener"
                                ><ExternalLink /> Open full size</a
                            >
                        </Button>
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
