<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { ImageUp, Link2, X } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usd } from '@/lib/money';
import { store as uploadRoute } from '@/routes/reference-images';

const MAX = 4;
const MAX_BYTES = 10 * 1024 * 1024;
const TYPES = ['image/png', 'image/jpeg', 'image/webp'];

const images = defineModel<string[]>({ required: true });
const props = defineProps<{
    costEach: bigint;
    errors: Record<string, string | undefined>;
}>();

const uploaded = reactive(new Set<string>()); // links that came from an upload, for the badge
const unpreviewable = reactive(new Set<string>());
const problem = ref('');
const linkDraft = ref('');
const dragging = ref(false);
const fileInput = ref<HTMLInputElement>();

const http = useHttp<{ image: File | null }, { url: string }>({ image: null });
const slotsLeft = computed(() => MAX - images.value.length);

function add(url: string): boolean {
    if (images.value.includes(url)) {
        problem.value =
            'That image is already added. Pixelforge would bill it twice.';
        return false;
    }
    images.value = [...images.value, url];
    return true;
}

function addLink(): void {
    problem.value = '';
    const url = linkDraft.value.trim();
    if (!/^https?:\/\/[^\s]+\.[^\s]+/i.test(url)) {
        problem.value =
            'Paste a full http(s) link to an image, like https://example.com/style.png.';
        return;
    }
    if (add(url)) {
        linkDraft.value = '';
    }
}

/** The same rules the server enforces, checked first so mistakes are caught instantly. */
async function check(file: File): Promise<string | null> {
    if (!TYPES.includes(file.type)) {
        return `${file.name}: use a PNG, JPEG or WebP image.`;
    }
    if (file.size > MAX_BYTES) {
        return `${file.name}: images can be at most 10 MB.`;
    }
    try {
        const bitmap = await createImageBitmap(file);
        const ok = bitmap.width >= 64 && bitmap.height >= 64;
        bitmap.close();
        return ok
            ? null
            : `${file.name}: images must be at least 64×64 pixels.`;
    } catch {
        return `${file.name}: that file isn't an image we can read.`;
    }
}

async function upload(
    files: FileList | File[] | null | undefined,
): Promise<void> {
    problem.value = '';
    const queue = Array.from(files ?? []);
    if (queue.length > slotsLeft.value) {
        problem.value = `Only ${slotsLeft.value} more image(s) fit (Pixelforge takes up to ${MAX}). The first ${slotsLeft.value} will be added.`;
    }

    for (const file of queue.slice(0, Math.max(0, slotsLeft.value))) {
        const error = await check(file);
        if (error) {
            problem.value = error;
            continue;
        }
        http.image = file;
        let status = 0;
        try {
            const response = await http.post(uploadRoute().url, {
                onHttpException: (failed) => {
                    status = failed.status;
                },
            });
            uploaded.add(response.url);
            add(response.url);
        } catch {
            problem.value = uploadProblem(status);
        }
    }
    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

function uploadProblem(status: number): string {
    if (status === 401 || status === 419) {
        return 'You were signed out. Reload the page and sign in again to continue.';
    }
    if (status === 429) {
        return 'Too many uploads in a short time. Wait a minute and try again.';
    }
    if (http.errors.image) {
        return http.errors.image;
    }

    return navigator.onLine
        ? "The upload didn't finish. Try again."
        : "You're offline. Try again when you're back.";
}

function remove(index: number): void {
    images.value = images.value.filter((_, i) => i !== index);
}

const serverError = computed(
    () =>
        Object.entries(props.errors).find(
            ([key, value]) => key.startsWith('reference_images') && value,
        )?.[1],
);
</script>

<template>
    <div class="flex flex-col gap-3 border-t border-dashed px-3 pt-3 pb-1">
        <div
            class="flex flex-wrap items-baseline justify-between gap-2 text-xs text-muted-foreground"
        >
            <span
                >Reference images guide the style. {{ images.length }}/{{
                    MAX
                }}
                added.</span
            >
            <span class="receipt"
                >+{{ usd(costEach, { round: 'up' }) }} each (765 tokens)</span
            >
        </div>

        <ul
            v-if="images.length"
            class="flex flex-wrap gap-2"
            aria-label="Reference images"
        >
            <li
                v-for="(url, index) in images"
                :key="url"
                class="group relative size-20 overflow-hidden rounded-lg border bg-raised"
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
                <span
                    v-else
                    class="grid size-full place-items-center p-1.5 text-center text-[10px] leading-tight text-muted-foreground"
                    >No preview (still billed)</span
                >
                <span
                    class="absolute bottom-1 left-1 rounded bg-background/85 px-1 text-[10px] backdrop-blur"
                    >{{ uploaded.has(url) ? 'Upload' : 'Link' }}</span
                >
                <button
                    type="button"
                    class="absolute top-1 right-1 grid size-5 place-items-center rounded-full bg-background/90 shadow-sm transition-colors hover:bg-destructive hover:text-white"
                    :aria-label="`Remove reference image ${index + 1}`"
                    @click="remove(index)"
                >
                    <X class="size-3" />
                </button>
            </li>
        </ul>

        <div v-if="slotsLeft > 0" class="grid gap-2 sm:grid-cols-2">
            <label
                class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border border-dashed px-3 py-3 text-center text-xs transition-colors hover:border-primary/60"
                :class="dragging ? 'border-primary bg-accent/40' : ''"
                @dragover.prevent="dragging = true"
                @dragleave="dragging = false"
                @drop.prevent="
                    dragging = false;
                    upload($event.dataTransfer?.files);
                "
            >
                <input
                    ref="fileInput"
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    multiple
                    class="sr-only"
                    :disabled="http.processing"
                    @change="upload(($event.target as HTMLInputElement).files)"
                />
                <template v-if="http.processing">
                    <span class="font-medium"
                        >Uploading… {{ http.progress?.percentage ?? 0 }}%</span
                    >
                    <span
                        class="h-1 w-full max-w-40 overflow-hidden rounded-full bg-secondary"
                    >
                        <span
                            class="block h-full rounded-full bg-primary transition-[width]"
                            :style="{
                                width: `${http.progress?.percentage ?? 0}%`,
                            }"
                        />
                    </span>
                </template>
                <template v-else>
                    <span class="flex items-center gap-1.5 font-medium"
                        ><ImageUp class="size-4" /> Upload or drop images</span
                    >
                    <span class="text-muted-foreground"
                        >PNG, JPEG or WebP · up to 10 MB · at least 64×64</span
                    >
                </template>
            </label>

            <div class="flex flex-col justify-center gap-1.5">
                <div class="flex gap-2">
                    <Input
                        v-model="linkDraft"
                        type="url"
                        inputmode="url"
                        placeholder="https://example.com/style.png"
                        aria-label="Reference image link"
                        class="h-9 rounded-lg"
                        @keydown.enter.prevent="addLink"
                    />
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        class="h-9"
                        @click="addLink"
                        ><Link2 /> Add</Button
                    >
                </div>
                <span class="text-[11px] text-muted-foreground"
                    >Or paste a link to an image online.</span
                >
            </div>
        </div>

        <p
            v-if="problem || serverError"
            class="text-xs text-destructive"
            role="alert"
        >
            {{ problem || serverError }}
        </p>
    </div>
</template>
