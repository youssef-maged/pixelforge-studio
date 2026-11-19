<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { login, register } from '@/routes';

defineProps<{ canRegister?: boolean }>();

const promises = [
    { label: 'Priced before you print', body: 'See the most a generation can cost before you click. You pay the real cost when it finishes, never more.' },
    { label: 'Failures are free', body: 'If Pixelforge rejects or fails a generation, nothing is charged and your held credits come straight back.' },
    { label: 'Charged exactly once', body: 'Every cent is recorded to the nanodollar and reconciled with Pixelforge, however many times its webhooks arrive.' },
];
</script>

<template>
    <Head title="Welcome" />

    <div class="min-h-svh bg-background text-foreground">
        <header class="mx-auto flex max-w-5xl items-center justify-between px-6 py-6">
            <span class="flex items-center gap-2 font-medium">
                <AppLogoIcon class="size-6 text-primary" />
                PixelForge Studio
            </span>
            <nav class="flex items-center gap-2 text-sm">
                <Link :href="login()" class="rounded-sm px-3 py-1.5 hover:bg-secondary">Log in</Link>
                <Link :href="register()" class="rounded-sm bg-primary px-3 py-1.5 text-primary-foreground hover:bg-primary/90">
                    Create an account
                </Link>
            </nav>
        </header>

        <main class="mx-auto grid max-w-5xl gap-14 px-6 pt-12 pb-24 md:pt-24">
            <section class="max-w-2xl">
                <h1 class="font-display text-5xl leading-[1.05] tracking-tight text-balance md:text-6xl">
                    Write a prompt. <em class="text-primary">Watch it develop.</em>
                </h1>
                <p class="mt-6 max-w-xl text-lg text-pretty text-muted-foreground">
                    An image studio on top of Pixelforge, with prices you can read like a receipt.
                </p>
                <Link
                    :href="register()"
                    class="mt-8 inline-flex rounded-sm bg-primary px-5 py-2.5 font-medium text-primary-foreground hover:bg-primary/90"
                >
                    Start printing
                </Link>
            </section>

            <ul class="grid gap-px overflow-hidden rounded-sm border bg-border md:grid-cols-3">
                <li v-for="promise in promises" :key="promise.label" class="bg-card p-6">
                    <h2 class="font-display text-lg">{{ promise.label }}</h2>
                    <p class="mt-2 text-sm leading-relaxed text-muted-foreground">{{ promise.body }}</p>
                </li>
            </ul>
        </main>
    </div>
</template>
