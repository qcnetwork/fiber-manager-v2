<script setup lang="ts">
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, X } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive } from 'vue';

type Change = [unknown, unknown];

interface Log {
    id: number;
    user_name: string | null;
    action: 'created' | 'updated' | 'deleted' | 'moved' | 'imported';
    subject_type: string;
    subject_id: number | null;
    subject_label: string;
    changes: Record<string, Change> | null;
    ip_address: string | null;
    created_at: string;
    area: string | null;
}

interface Filters {
    q?: string;
    user_id?: number;
    type?: string;
    subject_id?: number;
    action?: string;
    date?: string;
}

const props = defineProps<{
    logs: { data: Log[]; current_page: number; last_page: number; total: number; prev_page_url: string | null; next_page_url: string | null };
    filters: Filters;
    users: { id: number; name: string }[];
    showArea: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Riwayat Aktivitas', href: '/activity' }];

const types: Record<string, string> = {
    pop: 'POP',
    olt: 'OLT',
    olt_port: 'Port OLT',
    odc: 'ODC',
    odp: 'ODP',
    odp_port: 'Port ODP',
    customer: 'Pelanggan',
    cable: 'Kabel',
    photo: 'Foto',
    user: 'Pengguna',
    import: 'Impor file',
};

const actions: Record<Log['action'], { label: string; class: string }> = {
    created: { label: 'Tambah', class: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300' },
    updated: { label: 'Ubah', class: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300' },
    deleted: { label: 'Hapus', class: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' },
    moved: { label: 'Pindah', class: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' },
    imported: { label: 'Impor', class: 'bg-purple-100 text-purple-800 dark:bg-purple-950 dark:text-purple-300' },
};

const fields: Record<string, string> = {
    code: 'Kode',
    name: 'Nama',
    lat: 'Latitude',
    lng: 'Longitude',
    center_lat: 'Latitude',
    center_lng: 'Longitude',
    address: 'Alamat',
    pole_number: 'No. tiang',
    capacity: 'Kapasitas',
    splitter_ratio: 'Splitter',
    status: 'Status',
    installed_at: 'Tanggal pasang',
    notes: 'Catatan',
    area_id: 'Area',
    odc_id: 'ODC',
    odc_port: 'Port ODC',
    odp_id: 'ODP',
    olt_id: 'OLT',
    olt_port_id: 'Port OLT',
    customer_id: 'Pelanggan',
    customer_code: 'Kode pelanggan',
    phone: 'Telepon',
    package: 'Paket',
    ont_sn: 'SN ONT',
    type: 'Jenis',
    cores: 'Core',
    path: 'Jalur',
    length_m: 'Panjang (m)',
    from_type: 'Dari (jenis)',
    from_id: 'Dari (id)',
    to_type: 'Ke (jenis)',
    to_id: 'Ke (id)',
    caption: 'Keterangan',
    username: 'Username',
    email: 'Email',
    role: 'Role',
    password: 'Password',
    brand: 'Merek',
    ip_address: 'IP',
    pon_ports: 'Jumlah PON',
    slot: 'Slot',
    port: 'Port',
    number: 'Nomor',
    odc: 'ODC',
    odp: 'ODP',
    cable: 'Kabel',
    skipped: 'Dilewati',
};

// ---- Filters: applied as query parameters so the page can be bookmarked or linked to
const form = reactive({
    q: props.filters.q ?? '',
    user_id: props.filters.user_id ?? null,
    type: props.filters.type ?? '',
    action: props.filters.action ?? '',
    date: props.filters.date ?? '',
});

const hasFilters = computed(() => Object.values(form).some((v) => v !== '' && v !== null) || !!props.filters.subject_id);

function apply() {
    const query = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null));
    router.get(route('activity.index'), query, { preserveState: true, preserveScroll: true, replace: true });
}

watchDebounced(() => form.q, apply, { debounce: 400 });

function clear() {
    Object.assign(form, { q: '', user_id: null, type: '', action: '', date: '' });
    apply();
}

// ---- Display
const formatTime = (iso: string) =>
    new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

const show = (value: unknown) => (value === null || value === undefined || value === '' ? '–' : String(value));

/** One readable line per changed field. */
function changeLines(log: Log): { field: string; text: string }[] {
    return Object.entries(log.changes ?? {}).map(([key, [before, after]]) => {
        const field = fields[key] ?? key;
        if (log.action === 'deleted') return { field, text: show(before) };
        if (log.action === 'imported') return { field, text: show(after) };
        // Masked columns (password, cable route) are stored without values.
        if (before === null && after === null) return { field, text: 'diubah' };
        return { field, text: `${show(before)} → ${show(after)}` };
    });
}
</script>

<template>
    <Head title="Riwayat Aktivitas" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-[1fr_12rem_10rem_9rem_10rem_auto]">
                <Input v-model="form.q" placeholder="Cari kode / nama…" class="h-10" />
                <NativeSelect v-model="form.user_id" @change="apply">
                    <option :value="null">Semua pengguna</option>
                    <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
                </NativeSelect>
                <NativeSelect v-model="form.type" @change="apply">
                    <option value="">Semua jenis</option>
                    <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option>
                </NativeSelect>
                <NativeSelect v-model="form.action" @change="apply">
                    <option value="">Semua aksi</option>
                    <option v-for="(a, key) in actions" :key="key" :value="key">{{ a.label }}</option>
                </NativeSelect>
                <Input v-model="form.date" type="date" class="h-10" @change="apply" />
                <Button v-if="hasFilters" variant="ghost" class="h-10" @click="clear"><X class="size-4" /> Reset</Button>
            </div>

            <Card class="py-0">
                <CardContent class="p-0">
                    <ul v-if="logs.data.length" class="divide-y">
                        <li v-for="log in logs.data" :key="log.id" class="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:gap-4">
                            <div class="w-40 shrink-0 text-xs text-muted-foreground">
                                <div>{{ formatTime(log.created_at) }}</div>
                                <div class="font-medium text-foreground">{{ log.user_name ?? 'Tidak diketahui' }}</div>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2 text-sm">
                                    <span class="rounded px-1.5 py-0.5 text-xs font-medium" :class="actions[log.action].class">
                                        {{ actions[log.action].label }}
                                    </span>
                                    <span class="text-muted-foreground">{{ types[log.subject_type] ?? log.subject_type }}</span>
                                    <span class="font-medium break-all">{{ log.subject_label }}</span>
                                    <span v-if="showArea && log.area" class="text-xs text-muted-foreground">· {{ log.area }}</span>
                                </div>
                                <dl v-if="changeLines(log).length" class="mt-1 grid gap-x-3 text-xs sm:grid-cols-[auto_1fr]">
                                    <template v-for="line in changeLines(log)" :key="line.field">
                                        <dt class="text-muted-foreground">{{ line.field }}</dt>
                                        <dd class="mb-0.5 break-all">{{ line.text }}</dd>
                                    </template>
                                </dl>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="p-6 text-center text-sm text-muted-foreground">
                        Belum ada aktivitas{{ hasFilters ? ' yang cocok dengan filter' : '' }}.
                    </p>
                </CardContent>
            </Card>

            <div v-if="logs.last_page > 1" class="flex items-center justify-between text-sm">
                <span class="text-muted-foreground">Halaman {{ logs.current_page }} dari {{ logs.last_page }} · {{ logs.total }} catatan</span>
                <div class="flex gap-2">
                    <Button as-child variant="outline" size="sm" :disabled="!logs.prev_page_url">
                        <component :is="logs.prev_page_url ? Link : 'span'" :href="logs.prev_page_url ?? undefined" preserve-scroll>
                            <ChevronLeft class="size-4" /> Sebelumnya
                        </component>
                    </Button>
                    <Button as-child variant="outline" size="sm" :disabled="!logs.next_page_url">
                        <component :is="logs.next_page_url ? Link : 'span'" :href="logs.next_page_url ?? undefined">
                            Berikutnya <ChevronRight class="size-4" />
                        </component>
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
