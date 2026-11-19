<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import PrintWall from '@/components/marketing/PrintWall.vue';
import { login, register } from '@/routes';

defineProps<{ canRegister?: boolean }>();

const PROMISES = [
    ['Priced before you click', 'Pixelforge bills per token, and some tokens are only known afterwards. You see the most an image can cost up front, then pay what it really cost.'],
    ['Failures are free', 'Rejected prompts, provider errors and generations that fail midway cost nothing. Held credits come straight back.'],
    ['Charged exactly once', 'Webhooks arrive twice and out of order. Every charge is still recorded once, to the nanodollar, and reconciled with Pixelforge.'],
];
</script>

<template>
    <Head title="Image generation with honest pricing" />

    <div class="min-h-dvh">
        <header class="mx-auto flex max-w-6xl items-center justify-between px-6 py-5">
            <span class="flex items-center gap-2 text-sm font-semibold tracking-tight">
                <AppLogoIcon class="size-5 text-primary" />
                PixelForge Studio
            </span>
            <nav class="flex items-center gap-1 text-sm">
                <Link :href="login()" class="rounded-lg px-3 py-1.5 text-muted-foreground transition-colors hover:text-foreground">Log in</Link>
                <Link :href="register()" class="rounded-lg bg-primary px-3.5 py-1.5 font-medium text-primary-foreground transition-opacity hover:opacity-90">Sign up</Link>
            </nav>
        </header>

        <main class="mx-auto max-w-6xl px-6">
            <section class="grid items-center gap-12 py-12 lg:grid-cols-[1fr_1.05fr] lg:py-20">
                <div>
                    <h1 class="text-4xl font-semibold tracking-tight text-balance md:text-6xl md:leading-[1.02]">
                        Generate images, <span class="text-muted-foreground">know the price first</span>
                    </h1>
                    <p class="mt-6 max-w-lg text-lg text-pretty text-muted-foreground">
                        Write a prompt, pick a size and quality, and watch it generate live, with the cost on every image down to the fraction of a cent.
                    </p>
                    <div class="mt-8 flex flex-wrap items-center gap-3">
                        <Link :href="register()" class="inline-flex items-center gap-2 rounded-xl bg-primary px-5 py-3 font-medium text-primary-foreground transition-opacity hover:opacity-90">
                            Start generating <ArrowRight class="size-4" />
                        </Link>
                        <Link :href="login()" class="rounded-xl px-4 py-3 text-sm text-muted-foreground transition-colors hover:text-foreground">I have an account</Link>
                    </div>
                </div>

                <div class="relative aspect-[5/4] overflow-hidden rounded-3xl border bg-black shadow-[0_40px_120px_-40px_rgb(0_0_0/0.6)]">
                    <PrintWall />
                    <div class="absolute inset-0 bg-linear-to-t from-black/70 via-transparent to-transparent" />
                    <div class="receipt absolute right-4 bottom-4 left-4 flex items-center justify-between rounded-xl border border-white/10 bg-black/60 px-4 py-3 text-xs text-white backdrop-blur-md">
                        <span class="text-white/70">A lighthouse on a cliff at dawn, oil painting</span>
                        <span>up to $0.0864 · charged $0.0807</span>
                    </div>
                </div>
            </section>

            <section class="grid gap-x-10 gap-y-8 border-t py-14 md:grid-cols-3">
                <div v-for="[label, body] in PROMISES" :key="label">
                    <h2 class="font-medium">{{ label }}</h2>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">{{ body }}</p>
                </div>
            </section>
        </main>

        <footer class="mx-auto max-w-6xl px-6 py-8 text-xs text-muted-foreground">
            Built on the Pixelforge API. Credits are fake US dollars; no payment is ever taken.
        </footer>
    </div>
</template>
