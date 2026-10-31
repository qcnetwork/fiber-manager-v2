import type { LucideIcon } from '@lucide/vue';

export * from './network';

export interface Auth {
    user: User;
    isSuperAdmin: boolean;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData {
    [key: string]: unknown;
    name: string;
    auth: Auth;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export type Role = 'super_admin' | 'admin_area' | 'teknisi' | 'viewer' | 'sales';

export interface User {
    id: number;
    name: string;
    username: string | null;
    email: string | null;
    avatar?: string;
    role: Role;
    area_id: number | null;
    area?: { id: number; code: string; name: string } | null;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;
