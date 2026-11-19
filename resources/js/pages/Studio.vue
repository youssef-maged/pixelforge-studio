<script setup lang="ts">
import { Head, InfiniteScroll, Link, useForm, usePage, usePoll } from '@inertiajs/vue3';
import { FlaskConical, ImagePlus, X } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import GenerationDialog from '@/components/studio/GenerationDialog.vue';
import GenerationTile from '@/components/studio/GenerationTile.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
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
    { key: '1024x1024', label: '1:1', name: 'Square, 1024×1024', w: 12, h: 12 },
    { key: '1536x1024', label: '3:2', name: 'Landscape, 1536×1024', w: 15, h: 10 },
    { key: '1024x1536', label: '2:3', name: 'Portrait, 1024×1536', w: 10, h: 15 },
];
const QUALITIES = ['low', 'medium', 'high'];
const TEST_KEYWORDS = [
    { keyword: '[fail]', effect: 'Accepted, then fails (free)' },
    { keyword: '[slow]', effect: 'Takes 60–90 s' },
    { keyword: '[duplicate]', effect: 'Every webhook sent twice' },
    { keyword: '[blocked]', effect: 'Rejected by content policy' },
    { keyword: '[overloaded]', effect: 'Pixelforge answers 503' },
    { keyword: '[succeed]', effect: 'Never fails at random' },
];
const EXAMPLES = [
    'A lighthouse on a cliff at dawn, oil painting',
    'Isometric desert observatory at night, soft neon',
    'Paper cranes drifting over Kyoto rooftops, watercolour',
];

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

const showReferences = ref(false);
const selected = ref<Generation | null>(null);

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
    showReferences.value = generation.reference_images.length > 0;
    selected.value = null;
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
// Keep an open dialog in step with live updates.
watch(prints, (list) => {
    if (selected.value) {
        selected.value = list.find((g) => g.id === selected.value?.id) ?? selected.value;
    }
});
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

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-12 px-4 pt-10 pb-20 md:px-6 md:pt-14">
        <section class="mx-auto flex w-full max-w-3xl flex-col gap-5">
            <div class="text-center">
                <h1 class="text-2xl font-semibold tracking-tight text-balance md:text-3xl">What will you create?</h1>
                <p class="mt-1.5 text-sm text-muted-foreground">Priced before you click. Charged only what it really costs. Failures are free.</p>
            </div>

            <form
                class="rounded-2xl border bg-card p-2 shadow-[0_24px_60px_-32px_rgb(0_0_0/0.45)] transition-colors focus-within:border-primary/50"
                @submit.prevent="submit"
            >
                <label for="prompt" class="sr-only">Prompt</label>
                <textarea
                    id="prompt"
                    ref="promptField"
                    v-model="composer.prompt"
                    rows="3"
                    maxlength="4000"
                    required
                    placeholder="Describe the image you want…"
                    class="field-sizing-content max-h-72 min-h-24 w-full resize-none bg-transparent px-3 pt-2.5 pb-1 text-base leading-relaxed outline-none placeholder:text-muted-foreground"
                    @keydown.meta.enter.prevent="submit"
                    @keydown.ctrl.enter.prevent="submit"
                />

                <div v-if="composer.reference_images.length || showReferences" class="flex flex-wrap items-center gap-2 px-2 pb-2">
                    <div
                        v-for="(url, index) in composer.reference_images"
                        :key="url"
                        class="relative size-14 overflow-hidden rounded-lg border bg-raised"
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
                        <span v-else class="grid size-full place-items-center p-1 text-center text-[10px] leading-tight text-muted-foreground">No preview</span>
                        <button
                            type="button"
                            class="absolute top-0.5 right-0.5 grid size-5 place-items-center rounded-full bg-background/90 shadow-sm"
                            :aria-label="`Remove reference image ${index + 1}`"
                            @click="composer.reference_images.splice(index, 1)"
                        >
                            <X class="size-3" />
                        </button>
                    </div>
                    <div v-if="composer.reference_images.length < 4" class="flex min-w-64 flex-1 gap-2">
                        <Input
                            v-model="referenceDraft"
                            type="url"
                            inputmode="url"
                            placeholder="Paste an image URL to guide the style"
                            aria-label="Reference image URL"
                            class="h-9 rounded-lg"
                            @keydown.enter.prevent="addReference"
                        />
                        <Button type="button" variant="secondary" size="sm" class="h-9" @click="addReference">Add</Button>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-1.5 px-1 pt-1">
                    <TooltipProvider :delay-duration="300">
                        <div class="flex rounded-lg bg-raised p-0.5" role="radiogroup" aria-label="Size">
                            <Tooltip v-for="shape in SIZES" :key="shape.key">
                                <TooltipTrigger as-child>
                                    <label
                                        class="flex cursor-pointer items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs text-muted-foreground transition-colors has-checked:bg-background has-checked:text-foreground has-checked:shadow-sm has-focus-visible:ring-2 has-focus-visible:ring-ring"
                                    >
                                        <input type="radio" name="size" :value="shape.key" :checked="selectedSize === shape.key" class="sr-only" :aria-label="shape.name" @change="setSize(shape.key)" />
                                        <span class="rounded-[2px] border-[1.5px] border-current" :style="{ width: shape.w + 'px', height: shape.h + 'px' }" />
                                        {{ shape.label }}
                                    </label>
                                </TooltipTrigger>
                                <TooltipContent>{{ shape.name }}</TooltipContent>
                            </Tooltip>
                        </div>
                    </TooltipProvider>

                    <div class="flex rounded-lg bg-raised p-0.5" role="radiogroup" aria-label="Quality">
                        <label
                            v-for="level in QUALITIES"
                            :key="level"
                            class="cursor-pointer rounded-md px-2.5 py-1.5 text-xs text-muted-foreground capitalize transition-colors has-checked:bg-background has-checked:text-foreground has-checked:shadow-sm has-focus-visible:ring-2 has-focus-visible:ring-ring"
                        >
                            <input type="radio" name="quality" :value="level" :checked="selectedQuality === level" class="sr-only" @change="setQuality(level)" />
                            {{ level }}
                        </label>
                    </div>

                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="h-8 text-xs text-muted-foreground"
                        :aria-pressed="showReferences"
                        @click="showReferences = !showReferences"
                    >
                        <ImagePlus /> References<span v-if="composer.reference_images.length" class="receipt">· {{ composer.reference_images.length }}</span>
                    </Button>

                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button type="button" variant="ghost" size="sm" class="h-8 text-xs text-muted-foreground"><FlaskConical /> Test</Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" class="w-64">
                            <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">Pixelforge test keywords, added to the prompt</DropdownMenuLabel>
                            <DropdownMenuItem v-for="item in TEST_KEYWORDS" :key="item.keyword" @select="appendKeyword(item.keyword)">
                                <span class="receipt text-xs">{{ item.keyword }}</span>
                                <span class="ml-auto text-xs text-muted-foreground">{{ item.effect }}</span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <div class="ml-auto flex items-center gap-3">
                        <div class="text-right leading-tight" aria-live="polite">
                            <div class="receipt text-sm font-medium" :title="exact(usd(estimate, { places: 9 }).slice(1))">
                                <span class="text-xs font-normal text-muted-foreground">up to </span>{{ usd(estimate, { round: 'up' }) }}
                            </div>
                            <div class="receipt text-[11px] text-muted-foreground" :title="exact(availableDecimal)">{{ usd(availableDecimal) }} available</div>
                        </div>
                        <Button type="submit" class="h-9 rounded-lg px-4" :disabled="!canSubmit">
                            <Spinner v-if="busy" />
                            Generate
                            <kbd class="receipt hidden rounded border border-current/25 px-1 text-[10px] leading-4 opacity-75 sm:inline">⌘↵</kbd>
                        </Button>
                    </div>
                </div>
            </form>

            <div v-if="!canAfford || errors.credits" class="flex items-center justify-between gap-4 rounded-xl border border-destructive/30 bg-destructive/8 px-4 py-3 text-sm">
                <span>{{ errors.credits ?? `You need ${usd(shortBy, { round: 'up' })} more credit for this one.` }}</span>
                <Link :href="creditsPage()" class="shrink-0 font-medium text-primary hover:underline">Buy credits</Link>
            </div>
            <InputError :message="errors.prompt ?? referenceError ?? errors.reference_images" class="px-1" />
            <InputError v-for="(url, index) in composer.reference_images" :key="url" :message="errors[`reference_images.${index}`]" class="px-1" />
        </section>

        <section aria-labelledby="results-title" class="flex flex-col gap-4">
            <div class="flex items-baseline justify-between">
                <h2 id="results-title" class="text-sm font-medium">Your generations</h2>
                <span v-if="inFlightIds.length" class="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span class="size-1.5 animate-pulse rounded-full bg-primary motion-reduce:animate-none" />
                    {{ inFlightIds.length }} in progress
                </span>
            </div>

            <div v-if="!prints.length" class="flex flex-col items-center gap-4 rounded-2xl border border-dashed px-6 py-16 text-center">
                <p class="text-sm text-muted-foreground">Nothing here yet. Try one of these:</p>
                <div class="flex flex-wrap justify-center gap-2">
                    <button
                        v-for="example in EXAMPLES"
                        :key="example"
                        type="button"
                        class="rounded-full border bg-card px-3.5 py-1.5 text-sm transition-colors hover:border-primary/50"
                        @click="composer.prompt = example; promptField?.focus()"
                    >
                        {{ example }}
                    </button>
                </div>
            </div>

            <InfiniteScroll data="generations" class="grid grid-cols-1 gap-3 min-[460px]:grid-cols-2 md:grid-cols-3 xl:grid-cols-4">
                <GenerationTile v-for="generation in prints" :key="generation.id" :generation="generation" @open="selected = $event" @retry="retry" />
            </InfiniteScroll>
        </section>

        <GenerationDialog :generation="selected" @close="selected = null" @retry="retry" />
    </div>
</template>
