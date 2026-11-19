<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import PrintWall from '@/components/marketing/PrintWall.vue';
import { home } from '@/routes';

defineProps<{ title?: string; description?: string }>();

const PROMISES = [
    ['Priced before you click', 'See the most an image can cost before you generate it.'],
    ['Failures are free', 'If Pixelforge fails, nothing is charged and your credits come back.'],
    ['Charged exactly once', 'Every cent is recorded to the nanodollar and reconciled with Pixelforge.'],
];
</script>

<template>
    <div class="grid min-h-dvh lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
        <div class="flex flex-col px-6 py-6 sm:px-10">
            <Link :href="home()" class="flex items-center gap-2 self-start text-sm font-semibold tracking-tight">
                <AppLogoIcon class="size-5 text-primary" />
                PixelForge Studio
            </Link>

            <div class="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center gap-8 py-12">
                <div class="flex flex-col gap-1.5">
                    <h1 v-if="title" class="text-2xl font-semibold tracking-tight">{{ title }}</h1>
                    <p v-if="description" class="text-sm text-muted-foreground">{{ description }}</p>
                </div>
                <slot />
            </div>
        </div>

        <aside class="relative hidden overflow-hidden border-l bg-black lg:block">
            <PrintWall />
            <div class="absolute inset-0 bg-linear-to-t from-black via-black/55 to-black/10" />
            <ul class="absolute inset-x-0 bottom-0 grid gap-5 p-10 text-white xl:p-14">
                <li v-for="[label, body] in PROMISES" :key="label" class="max-w-md">
                    <p class="font-medium">{{ label }}</p>
                    <p class="mt-0.5 text-sm text-white/70">{{ body }}</p>
                </li>
            </ul>
        </aside>
    </div>
</template>
