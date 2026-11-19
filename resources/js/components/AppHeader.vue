<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { getInitials } from '@/composables/useInitials';
import { exact, usd } from '@/lib/money';
import { credits, studio } from '@/routes';
import { report } from '@/routes/admin';
import type { Credits } from '@/types';

defineProps<{ breadcrumbs?: unknown[] }>();

const page = usePage<{ credits: Credits }>();
const user = computed(() => page.props.auth.user);
const wallet = computed(() => page.props.credits);
const { isCurrentUrl } = useCurrentUrl();

const links = computed(() => [
    { title: 'Studio', href: studio() },
    { title: 'Credits', href: credits() },
    ...(user.value.is_admin ? [{ title: 'Admin', href: report() }] : []),
]);
</script>

<template>
    <header
        class="sticky top-0 z-30 border-b bg-background/80 backdrop-blur-xl supports-[backdrop-filter]:bg-background/65"
    >
        <div
            class="mx-auto flex h-14 max-w-7xl items-center gap-2 px-4 md:gap-6 md:px-6"
        >
            <Link
                :href="studio()"
                class="flex shrink-0 items-center gap-2 text-sm font-semibold tracking-tight"
            >
                <AppLogoIcon class="size-5 text-primary" />
                <span class="hidden sm:inline">PixelForge Studio</span>
            </Link>

            <nav class="flex items-center gap-0.5 text-sm" aria-label="Main">
                <Link
                    v-for="link in links"
                    :key="link.title"
                    :href="link.href"
                    class="rounded-lg px-2.5 py-1.5 transition-colors"
                    :class="
                        isCurrentUrl(link.href)
                            ? 'bg-secondary text-foreground'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :aria-current="isCurrentUrl(link.href) ? 'page' : undefined"
                >
                    {{ link.title }}
                </Link>
            </nav>

            <div class="ml-auto flex items-center gap-2">
                <Link
                    v-if="wallet"
                    :href="credits()"
                    class="receipt hidden items-center gap-1.5 rounded-full border bg-card px-3 py-1 text-xs transition-colors hover:border-primary/50 sm:flex"
                    :title="exact(wallet.available)"
                >
                    <span
                        :class="
                            wallet.available.startsWith('-')
                                ? 'text-destructive'
                                : 'text-primary'
                        "
                        >{{ usd(wallet.available) }}</span
                    >
                    <span class="text-muted-foreground">available</span>
                </Link>

                <DropdownMenu>
                    <DropdownMenuTrigger
                        class="flex items-center gap-1 rounded-full p-0.5 pr-1.5 transition-colors hover:bg-secondary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        aria-label="Account menu"
                    >
                        <Avatar class="size-7">
                            <AvatarFallback
                                class="bg-accent text-[11px] font-medium text-accent-foreground"
                            >
                                {{ getInitials(user.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <ChevronDown class="size-3.5 text-muted-foreground" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <UserMenuContent :user="user" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    </header>
</template>
