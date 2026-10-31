<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowDownUp, LayoutGrid, Map, MapPinned, Radar, Users } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

const coverageItem: NavItem = {
    title: 'Cek Coverage',
    href: '/coverage',
    icon: Radar,
};

const staffNavItems: NavItem[] = [
    {
        title: 'Peta Jaringan',
        href: '/map',
        icon: Map,
    },
    {
        title: 'Dashboard',
        href: '/dashboard',
        icon: LayoutGrid,
    },
    coverageItem,
    {
        title: 'Ekspor & Impor',
        href: '/data',
        icon: ArrowDownUp,
    },
];

// Sales accounts only use the coverage check.
const mainNavItems = computed(() => (page.props.auth.user.role === 'sales' ? [coverageItem] : staffNavItems));

const adminNavItems: NavItem[] = [
    {
        title: 'Area / POP',
        href: '/admin/areas',
        icon: MapPinned,
    },
    {
        title: 'Pengguna',
        href: '/admin/users',
        icon: Users,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('map')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain v-if="page.props.auth.isSuperAdmin" :items="adminNavItems" label="Administrasi" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
