<script setup lang="ts">
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuBadge, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';

withDefaults(
    defineProps<{
        items: NavItem[];
        label?: string;
    }>(),
    { label: 'Menu' },
);

const page = usePage<SharedData>();
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>{{ label }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton v-if="item.external" as-child :tooltip="item.title">
                    <a :href="item.href" target="_blank" rel="noopener noreferrer">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                        <ExternalLink class="ml-auto size-3.5 opacity-60" />
                    </a>
                </SidebarMenuButton>
                <SidebarMenuButton v-else as-child :is-active="page.url.startsWith(item.href)">
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
                <SidebarMenuBadge v-if="item.badge" class="rounded-full bg-red-600 px-1.5 text-white peer-hover/menu-button:text-white">
                    {{ item.badge > 99 ? '99+' : item.badge }}
                </SidebarMenuBadge>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
