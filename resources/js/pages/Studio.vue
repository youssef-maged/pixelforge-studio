<script setup lang="ts">
import { Head, InfiniteScroll, Link, useForm, usePage, usePoll } from '@inertiajs/vue3';
import { ImagePlus, X } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import GenerationPrint from '@/components/studio/GenerationPrint.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { exact, toNano, usd } from '@/lib/money';
import { maxUserPriceNano } from '@/lib/pricing';
import { credits as creditsPage, studio } from '@/routes';
import { store } from '@/routes/generations';
import type { Credits, Generation, PricingList } from '@/types';

defineOptions({ layout: { breadcrumbs: [{ title: 'Studio', href: studio() }] } });

const props = defineProps<{
    generations: { data: Generation[] };
    watched?: { data: Generation[] };
    pricing: PricingList;
}>();

const page = usePage<{ credits: Credits }>();

const SIZES = [
    { key: '1024x1024', label: 'Square', w: 14, h: 14 },
    { key: '1536x1024', label: 'Landscape', w: 18, h: 12 },
    { key: '1024x1536', label: 'Portrait', w: 12, h: 18 },
];
const QUALITIES = ['low', 'medium', 'high'];
const TEST_KEYWORDS = ['[fail]', '[slow]', '[duplicate]', '[blocked]', '[overloaded]', '[succeed]'];

const composer = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
    client_request_id: crypto.randomUUID(), // one per submission: a double-click creates one generation
});

// --- Price, before anything is submitted -----------------------------------------------------
const estimate = computed(() =>
    maxUserPriceNano(props.pricing, composer.prompt, composer.size, composer.quality, composer.reference_images.length),
);
const availableDecimal = computed(() => page.props.credits.available);
const available = computed(() => toNano(availableDecimal.value));
// Server-side errors the form has no field for (balance) and per-list errors.
const errors = computed(() => composer.errors as Record<string, string | undefined>);
const shortBy = computed(() => estimate.value - available.value);
const canAfford = computed(() => shortBy.value <= 0n);
const busy = computed(() => composer.processing);
const selectedSize = computed(() => composer.size);
const selectedQuality = computed(() => composer.quality);
const canSubmit = computed(() => !busy.value && canAfford.value && composer.prompt.trim() !== '');
const promptLength = computed(() => [...composer.prompt].length);

// --- Reference images ---------------------------------------------------------------------------
const referenceDraft = ref('');
const referenceError = ref('');
const unpreviewable = reactive(new Set<string>());

function addReference(): void {
    const url = referenceDraft.value.trim();
    referenceError.value = '';
    if (!/^https?:\/\/\S+$/i.test(url)) {
        referenceError.value = 'Use an http(s) link to an image.';
        return;
    }
    if (composer.reference_images.includes(url)) {
        referenceError.value = 'Already added. Pixelforge would bill it twice.';
        return;
    }
    composer.reference_images.push(url);
    referenceDraft.value = '';
}

function setSize(size: string): void {
    composer.size = size;
}

function setQuality(quality: string): void {
    composer.quality = quality;
}

function appendKeyword(keyword: string): void {
    composer.prompt = `${composer.prompt.trimEnd()} ${keyword}`.trim();
}

// --- Submit ----------------------------------------------------------------------------------------
const promptField = ref<HTMLTextAreaElement>();

function submit(): void {
    if (!canSubmit.value) {
        return;
    }
    composer.post(store().url, {
        preserveScroll: true,
        reset: ['generations'],
        onSuccess: () => {
            composer.reset('prompt', 'reference_images');
            composer.client_request_id = crypto.randomUUID();
        },
    });
}

function retry(generation: Generation): void {
    composer.clearErrors();
    composer.prompt = generation.prompt;
    composer.size = generation.size;
    composer.quality = generation.quality;
    composer.reference_images = [...generation.reference_images];
    composer.client_request_id = crypto.randomUUID();
    window.scrollTo({ top: 0, behavior: 'smooth' });
    promptField.value?.focus({ preventScroll: true });
}

// --- Live status: poll only the generations still in flight, until they settle ---------------
const latest = reactive(new Map<string, Generation>());
watch(
    () => props.watched,
    (watched) => watched?.data.forEach((g) => latest.set(g.id, g)),
);
const prints = computed(() => props.generations.data.map((g) => latest.get(g.id) ?? g));
const inFlightIds = computed(() => prints.value.filter((g) => g.in_flight).map((g) => g.id));

const poll = usePoll(
    2000,
    () => ({ only: ['watched', 'credits'], data: { watch: inFlightIds.value }, preserveUrl: true }),
    { autoStart: false, mode: 'rest' },
);
watch(inFlightIds, (ids) => (ids.length ? poll.start() : poll.stop()), { immediate: true });
</script>

<template>
    <Head title="Studio" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-10 px-4 py-6 md:px-8 md:py-10">
        <!-- Composer ------------------------------------------------------------------------------ -->
        <form
            class="grid gap-6 rounded-sm border bg-card p-5 md:grid-cols-[1fr_17rem] md:p-6"
            @submit.prevent="submit"
        >
            <div class="flex flex-col gap-4">
                <div class="flex items-baseline justify-between gap-4">
                    <label for="prompt" class="font-display text-2xl tracking-tight">What should we print?</label>
                    <span class="receipt text-xs text-muted-foreground">{{ promptLength }}/4000</span>
                </div>
                <textarea
                    id="prompt"
                    ref="promptField"
                    v-model="composer.prompt"
                    rows="4"
                    maxlength="4000"
                    required
                    placeholder="A lighthouse on a cliff at dawn, oil painting"
                    class="min-h-28 w-full resize-y rounded-sm border border-input bg-background px-3 py-2.5 text-base leading-relaxed outline-none placeholder:text-muted-foreground/70 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/25"
                    @keydown.meta.enter.prevent="submit"
                    @keydown.ctrl.enter.prevent="submit"
                />
                <InputError :message="composer.errors.prompt" />

                <div class="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                    <span>Pixelforge test levers:</span>
                    <button
                        v-for="keyword in TEST_KEYWORDS"
                        :key="keyword"
                        type="button"
                        class="receipt rounded-[2px] border border-dashed px-1.5 py-0.5 hover:border-primary hover:text-primary"
                        @click="appendKeyword(keyword)"
                    >
                        {{ keyword }}
                    </button>
                </div>

                <fieldset class="flex flex-col gap-2">
                    <legend class="mb-2 text-sm font-medium">
                        Reference images <span class="font-normal text-muted-foreground">(optional, up to 4, each billed as 765 tokens)</span>
                    </legend>
                    <div v-if="composer.reference_images.length" class="flex flex-wrap gap-2">
                        <div
                            v-for="(url, index) in composer.reference_images"
                            :key="url"
                            class="group relative size-16 overflow-hidden rounded-[2px] border bg-muted"
                            :title="url"
                        >
                            <img
                                v-if="!unpreviewable.has(url)"
                                :src="url"
                                alt=""
                                class="size-full object-cover"
                                referrerpolicy="no-referrer"
                                @error="unpreviewable.add(url)"
                            />
                            <span v-else class="grid size-full place-items-center p-1 text-center text-[10px] leading-tight text-muted-foreground">
                                can't preview
                            </span>
                            <button
                                type="button"
                                class="absolute top-0.5 right-0.5 grid size-5 place-items-center rounded-full bg-background/90 text-foreground shadow-sm"
                                :aria-label="`Remove reference image ${index + 1}`"
                                @click="composer.reference_images.splice(index, 1)"
                            >
                                <X class="size-3" />
                            </button>
                            <InputError :message="errors[`reference_images.${index}`]" />
                        </div>
                    </div>
                    <div v-if="composer.reference_images.length < 4" class="flex gap-2">
                        <Input
                            v-model="referenceDraft"
                            type="url"
                            inputmode="url"
                            placeholder="https://example.com/style.png"
                            aria-label="Reference image URL"
                            @keydown.enter.prevent="addReference"
                        />
                        <Button type="button" variant="outline" @click="addReference"><ImagePlus /> Add</Button>
                    </div>
                    <InputError :message="referenceError || errors.reference_images" />
                </fieldset>
            </div>

            <!-- Controls + receipt --------------------------------------------------------------- -->
            <div class="flex flex-col gap-5 md:border-l md:border-dashed md:pl-6">
                <fieldset>
                    <legend class="mb-2 text-sm font-medium">Size</legend>
                    <div class="grid grid-cols-3 gap-1.5">
                        <label
                            v-for="shape in SIZES"
                            :key="shape.key"
                            class="flex cursor-pointer flex-col items-center gap-1.5 rounded-sm border px-1 py-2 text-xs has-checked:border-primary has-checked:bg-accent has-checked:text-accent-foreground has-focus-visible:ring-[3px] has-focus-visible:ring-ring/25"
                        >
                            <input type="radio" name="size" :value="shape.key" :checked="selectedSize === shape.key" class="sr-only" @change="setSize(shape.key)" />
                            <span class="block rounded-[1px] border-[1.5px] border-current" :style="{ width: shape.w + 'px', height: shape.h + 'px' }" />
                            {{ shape.label }}
                        </label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="mb-2 text-sm font-medium">Quality</legend>
                    <div class="grid grid-cols-3 gap-1.5">
                        <label
                            v-for="level in QUALITIES"
                            :key="level"
                            class="cursor-pointer rounded-sm border py-1.5 text-center text-xs capitalize has-checked:border-primary has-checked:bg-accent has-checked:text-accent-foreground has-focus-visible:ring-[3px] has-focus-visible:ring-ring/25"
                        >
                            <input type="radio" name="quality" :value="level" :checked="selectedQuality === level" class="sr-only" @change="setQuality(level)" />
                            {{ level }}
                        </label>
                    </div>
                </fieldset>

                <div class="receipt mt-auto flex flex-col gap-1 border-t border-dashed pt-4 text-sm" aria-live="polite">
                    <div class="flex items-baseline justify-between">
                        <span class="text-muted-foreground">Costs up to</span>
                        <span class="text-xl font-medium" :title="exact(usd(estimate, { places: 9 }).slice(1))">
                            {{ usd(estimate, { round: 'up' }) }}
                        </span>
                    </div>
                    <p class="font-sans text-xs leading-snug text-muted-foreground">
                        You pay the real cost when it finishes, never more than this. Failures are free.
                    </p>
                    <div class="mt-2 flex items-baseline justify-between text-xs">
                        <span class="text-muted-foreground">Available</span>
                        <span :title="exact(availableDecimal)">{{ usd(availableDecimal) }}</span>
                    </div>
                </div>

                <div v-if="!canAfford" class="rounded-sm border border-destructive/30 bg-destructive/5 p-3 text-xs">
                    <InputError :message="errors.credits" class="mb-1" />
                    <p v-if="!errors.credits">
                        You need {{ usd(shortBy, { round: 'up' }) }} more for this one.
                    </p>
                    <Link :href="creditsPage()" class="font-medium text-primary underline underline-offset-2">Buy credits</Link>
                </div>
                <InputError v-else :message="errors.credits" />

                <Button type="submit" size="lg" :disabled="!canSubmit">
                    <Spinner v-if="busy" />
                    Generate
                    <kbd class="receipt ml-1 hidden text-[10px] opacity-70 md:inline">⌘↵</kbd>
                </Button>
            </div>
        </form>

        <!-- Contact sheet ------------------------------------------------------------------------- -->
        <section aria-labelledby="sheet-title" class="flex flex-col gap-4">
            <div class="flex items-baseline justify-between border-b pb-2">
                <h2 id="sheet-title" class="font-display text-xl">Contact sheet</h2>
                <span v-if="inFlightIds.length" class="receipt text-xs text-primary">
                    {{ inFlightIds.length }} developing
                </span>
            </div>

            <p v-if="!prints.length" class="py-16 text-center text-sm text-muted-foreground">
                Nothing printed yet. Your generations appear here, live, with what each one cost.
            </p>

            <InfiniteScroll data="generations" class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <GenerationPrint v-for="generation in prints" :key="generation.id" :generation="generation" @retry="retry" />
            </InfiniteScroll>
        </section>
    </div>
</template>
