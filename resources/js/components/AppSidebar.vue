<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ChartColumn,
    Gauge,
    Images,
    Users,
    Webhook,
} from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { studio } from '@/routes';
import { generations, report, users, webhooks } from '@/routes/admin';
import type { NavItem } from '@/types';

// The operator console. Customers never see this sidebar; the Studio uses the top bar.
const mainNavItems: NavItem[] = [
    { title: 'Overview', href: report(), icon: ChartColumn },
    { title: 'Generations', href: generations(), icon: Images },
    { title: 'Users', href: users(), icon: Users },
    { title: 'Webhook log', href: webhooks(), icon: Webhook },
];

const footerNavItems: NavItem[] = [
    { title: 'Queue (Horizon)', href: '/horizon', icon: Gauge },
    { title: 'Back to the studio', href: studio(), icon: ArrowLeft },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="report()" class="gap-2">
                            <span
                                class="grid size-8 place-items-center rounded-lg bg-primary/12 text-primary"
                            >
                                <AppLogoIcon class="size-4.5" />
                            </span>
                            <span class="grid leading-tight">
                                <span class="text-sm font-semibold"
                                    >PixelForge</span
                                >
                                <span class="text-xs text-muted-foreground"
                                    >Operator console</span
                                >
                            </span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
