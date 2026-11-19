<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Aperture, ChartColumn, Coins, FileText } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
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
import { credits, studio } from '@/routes';
import { report } from '@/routes/admin';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Studio', href: studio(), icon: Aperture },
    { title: 'Credits', href: credits(), icon: Coins },
    ...(page.props.auth.user.is_admin
        ? [{ title: 'Admin report', href: report(), icon: ChartColumn }]
        : []),
]);

const footerNavItems: NavItem[] = [
    {
        title: 'Pixelforge API docs',
        href: 'https://pixelforge.promptedit.dev/docs',
        icon: FileText,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="studio()">
                            <AppLogo />
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
