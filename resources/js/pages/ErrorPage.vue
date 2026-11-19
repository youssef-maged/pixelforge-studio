<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home, login, studio } from '@/routes';

const props = defineProps<{ status: number }>();
const page = usePage();
const signedIn = computed(() => Boolean(page.props.auth?.user));

const copy = computed(
    () =>
        ({
            403: ['This page is for operators', "Your account doesn't have access to it. If you think it should, ask an admin to grant it."],
            404: ["We couldn't find that page", 'The link may be old, or the page may have moved.'],
            500: ['Something went wrong on our side', "It's been logged. Nothing was charged; try again in a moment."],
            503: ["We're down for a moment", "We'll be right back. Nothing was charged."],
        })[props.status] ?? ['Something went wrong', 'Try again in a moment.'],
);
</script>

<template>
    <Head :title="copy[0]" />
    <main class="grid min-h-dvh place-items-center px-6">
        <div class="flex max-w-md flex-col items-center gap-4 text-center">
            <AppLogoIcon class="size-8 text-primary" />
            <p class="receipt text-xs text-muted-foreground">Error {{ status }}</p>
            <h1 class="text-2xl font-semibold tracking-tight text-balance">{{ copy[0] }}</h1>
            <p class="text-sm text-muted-foreground">{{ copy[1] }}</p>
            <div class="mt-2 flex gap-2">
                <Link :href="signedIn ? studio() : home()" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground">
                    {{ signedIn ? 'Back to the studio' : 'Go to the home page' }}
                </Link>
                <Link v-if="!signedIn" :href="login()" class="rounded-lg px-4 py-2 text-sm text-muted-foreground hover:text-foreground">Log in</Link>
            </div>
        </div>
    </main>
</template>
