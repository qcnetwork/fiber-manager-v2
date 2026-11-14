<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowDownUp, ClipboardCheck, Download, ClipboardPen, History, LayoutGrid, Map, MapPinned, Radar, Users } from '@lucide/vue';
import { computed } from 'vue';
import { useInstallPrompt } from '@/composables/usePwa';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();
const { canInstall, install } = useInstallPrompt();

const coverageItem: NavItem = {
    title: 'Cek Coverage',
    href: '/coverage',
    icon: Radar,
};

// Temporary: installation requests go through the website form until the customer list feature exists here.
const installRequestItem: NavItem = {
    title: 'Request Pemasangan',
    href: 'https://qcnetwork.net/daftar-adm',
    icon: ClipboardPen,
    external: true,
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

const activityItem: NavItem = {
    title: 'Riwayat Aktivitas',
    href: '/activity',
    icon: History,
};

// Approvers see requests waiting for them; technicians see their own requests and new decisions.
const approvalItem = computed<NavItem>(() => {
    const counts = page.props.approvals;
    return {
        title: page.props.auth.canApprove ? 'Approval' : 'Request Saya',
        href: '/approvals',
        icon: ClipboardCheck,
        badge: page.props.auth.canApprove ? counts?.pending : counts?.decided_unseen,
    };
});

// Sales accounts only use the coverage check; the history is for the admins who can delete data.
const mainNavItems = computed(() => {
    const role = page.props.auth.user.role;
    if (role === 'sales') return [coverageItem, installRequestItem];
    if (role === 'viewer') return [...staffNavItems.filter((item) => item.href !== '/data'), installRequestItem]; // export holds customer data
    const items =
        role === 'super_admin' || role === 'admin_area'
            ? [...staffNavItems, approvalItem.value, activityItem]
            : [...staffNavItems, approvalItem.value];
    return [...items, installRequestItem];
});

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
            <SidebarMenu v-if="canInstall">
                <SidebarMenuItem>
                    <SidebarMenuButton tooltip="Install aplikasi" @click="install">
                        <Download />
                        <span>Install aplikasi</span>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
