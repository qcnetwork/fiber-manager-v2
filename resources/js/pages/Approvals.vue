<script setup lang="ts">
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { activityActions, activityTypes, changeLines, type ActivityAction, type ActivityChange } from '@/lib/activity';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, ChevronLeft, ChevronRight, MapPin, Pencil, TriangleAlert, Undo2, X } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';

type Status = 'pending' | 'approved' | 'rejected' | 'cancelled';

interface PreviewEntry {
    action: ActivityAction;
    type: string;
    label: string;
    changes: Record<string, ActivityChange> | null;
}

interface ChangeRequest {
    id: number;
    action: ActivityAction;
    subject_type: string;
    subject_id: number | null;
    subject_label: string | null;
    status: Status;
    preview: PreviewEntry[] | null;
    review_note: string | null;
    created_at: string;
    reviewed_at: string | null;
    user: { id: number; name: string } | null;
    reviewer: { id: number; name: string } | null;
    area: { id: number; code: string; name: string } | null;
    photos: string[];
    conflict: string | null;
    editable: boolean;
    map: string | null;
}

const props = defineProps<{
    requests: {
        data: ChangeRequest[];
        current_page: number;
        last_page: number;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { tab: 'pending' | 'history'; area_id: number | null; type: string | null };
    areas: { id: number; code: string; name: string }[];
    canApprove: boolean;
}>();

const title = props.canApprove ? 'Approval' : 'Request Saya';
const breadcrumbs: BreadcrumbItem[] = [{ title, href: '/approvals' }];

const statuses: Record<Status, { label: string; class: string }> = {
    pending: { label: 'Menunggu', class: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' },
    approved: { label: 'Disetujui', class: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300' },
    rejected: { label: 'Ditolak', class: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' },
    cancelled: { label: 'Dibatalkan', class: 'bg-muted text-muted-foreground' },
};

// Kinds of requests technicians can send, for the filter.
const requestTypes = ['odc', 'odp', 'joint', 'cable', 'odp_port', 'customer', 'splice', 'cable_core', 'photo', 'olt_port'];

// ---- Tabs and filters, kept in the URL
const filter = reactive({ area_id: props.filters.area_id, type: props.filters.type ?? '' });

function visit(tab: string, page?: number) {
    const query = Object.fromEntries(
        Object.entries({ tab, area_id: filter.area_id, type: filter.type, page }).filter(([, v]) => v !== '' && v !== null && v !== undefined),
    );
    router.get(route('approvals.index'), query, { preserveScroll: true, replace: true });
}

// ---- Decisions
const busy = ref<number | null>(null);
const rejecting = ref<number | null>(null);
const note = ref('');

const done = { preserveScroll: true, onFinish: () => (busy.value = null) };

function approve(cr: ChangeRequest) {
    busy.value = cr.id;
    router.post(route('approvals.approve', { changeRequest: cr.id }), {}, done);
}

function startReject(cr: ChangeRequest) {
    rejecting.value = cr.id;
    note.value = '';
}

function reject(cr: ChangeRequest) {
    busy.value = cr.id;
    router.post(route('approvals.reject', { changeRequest: cr.id }), { note: note.value }, { ...done, onSuccess: () => (rejecting.value = null) });
}

function cancel(cr: ChangeRequest) {
    busy.value = cr.id;
    router.post(route('approvals.cancel', { changeRequest: cr.id }), {}, done);
}

// ---- Display
const formatTime = (iso: string | null) =>
    iso ? new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';

const typeLabel = (type: string) => activityTypes[type] ?? type;

/** The main change, then the records it touches along the way (ports of a new ODP, …). */
const mainEntry = (cr: ChangeRequest) => cr.preview?.[0] ?? null;
const relatedEntries = (cr: ChangeRequest) => cr.preview?.slice(1) ?? [];

const emptyText = computed(() =>
    props.filters.tab === 'pending'
        ? props.canApprove
            ? 'Tidak ada request yang menunggu persetujuan.'
            : 'Tidak ada request yang sedang menunggu.'
        : 'Belum ada riwayat request.',
);
</script>

<template>
    <Head :title="title" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="flex flex-wrap items-center gap-2">
                <div class="inline-flex rounded-md border p-0.5">
                    <button
                        v-for="tab in [
                            { key: 'pending', label: 'Menunggu' },
                            { key: 'history', label: 'Riwayat' },
                        ]"
                        :key="tab.key"
                        type="button"
                        class="rounded px-3 py-1.5 text-sm"
                        :class="filters.tab === tab.key ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
                        @click="visit(tab.key)"
                    >
                        {{ tab.label }}
                    </button>
                </div>
                <NativeSelect v-if="areas.length" v-model="filter.area_id" class="w-auto" @change="visit(filters.tab)">
                    <option :value="null">Semua area</option>
                    <option v-for="area in areas" :key="area.id" :value="area.id">{{ area.name }}</option>
                </NativeSelect>
                <NativeSelect v-model="filter.type" class="w-auto" @change="visit(filters.tab)">
                    <option value="">Semua jenis</option>
                    <option v-for="type in requestTypes" :key="type" :value="type">{{ typeLabel(type) }}</option>
                </NativeSelect>
                <span class="ml-auto text-sm text-muted-foreground">{{ requests.total }} request</span>
            </div>

            <p v-if="!canApprove && filters.tab === 'pending'" class="text-sm text-muted-foreground">
                Perubahan yang kamu kirim dari peta menunggu persetujuan admin di sini sebelum tersimpan.
            </p>

            <Card v-if="!requests.data.length" class="py-0">
                <CardContent class="p-6 text-center text-sm text-muted-foreground">{{ emptyText }}</CardContent>
            </Card>

            <Card v-for="cr in requests.data" :key="cr.id" class="gap-0 py-0">
                <CardContent class="flex flex-col gap-3 p-4">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="text-xs text-muted-foreground tabular-nums">#{{ cr.id }}</span>
                        <span class="rounded px-1.5 py-0.5 text-xs font-medium" :class="activityActions[cr.action]?.class">
                            {{ activityActions[cr.action]?.label ?? cr.action }}
                        </span>
                        <span class="text-muted-foreground">{{ typeLabel(cr.subject_type) }}</span>
                        <span class="font-medium break-all">{{ cr.subject_label }}</span>
                        <span v-if="cr.area" class="text-xs text-muted-foreground">· {{ cr.area.name }}</span>
                        <span class="ml-auto rounded px-1.5 py-0.5 text-xs font-medium" :class="statuses[cr.status].class">
                            {{ statuses[cr.status].label }}
                        </span>
                    </div>

                    <div class="text-xs text-muted-foreground">
                        Dikirim <span class="font-medium text-foreground">{{ cr.user?.name ?? 'pengguna terhapus' }}</span> ·
                        {{ formatTime(cr.created_at) }}
                        <template v-if="cr.status !== 'pending' && cr.reviewed_at">
                            <br />
                            {{ statuses[cr.status].label }}
                            <template v-if="cr.reviewer"
                                >oleh <span class="font-medium text-foreground">{{ cr.reviewer.name }}</span></template
                            >
                            · {{ formatTime(cr.reviewed_at) }}
                        </template>
                    </div>

                    <p v-if="cr.review_note" class="rounded-md bg-muted px-3 py-2 text-sm">
                        <span class="text-muted-foreground">Catatan:</span> {{ cr.review_note }}
                    </p>

                    <p
                        v-if="cr.conflict"
                        class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
                    >
                        <TriangleAlert class="mt-0.5 size-4 shrink-0" /> {{ cr.conflict }}
                    </p>

                    <template v-if="mainEntry(cr)">
                        <dl
                            v-if="changeLines(mainEntry(cr)!.action, mainEntry(cr)!.changes).length"
                            class="grid gap-x-3 rounded-md border px-3 py-2 text-xs sm:grid-cols-[auto_1fr]"
                        >
                            <template v-for="line in changeLines(mainEntry(cr)!.action, mainEntry(cr)!.changes)" :key="line.field">
                                <dt class="text-muted-foreground">{{ line.field }}</dt>
                                <dd class="mb-0.5 break-all">{{ line.text }}</dd>
                            </template>
                        </dl>
                        <details v-if="relatedEntries(cr).length" class="text-xs">
                            <summary class="cursor-pointer text-muted-foreground">+ {{ relatedEntries(cr).length }} perubahan terkait</summary>
                            <ul class="mt-1 flex flex-col gap-1">
                                <li v-for="(entry, i) in relatedEntries(cr)" :key="i">
                                    <span class="font-medium"
                                        >{{ activityActions[entry.action]?.label }} {{ typeLabel(entry.type) }} {{ entry.label }}</span
                                    >
                                    <span v-for="line in changeLines(entry.action, entry.changes)" :key="line.field" class="text-muted-foreground">
                                        · {{ line.field }}: {{ line.text }}
                                    </span>
                                </li>
                            </ul>
                        </details>
                    </template>

                    <div v-if="cr.photos.length" class="flex flex-wrap gap-2">
                        <a v-for="url in cr.photos" :key="url" :href="url" target="_blank" rel="noopener">
                            <img :src="url" alt="Foto request" class="h-28 w-28 rounded-md border object-cover" loading="lazy" />
                        </a>
                    </div>

                    <div v-if="rejecting === cr.id" class="flex flex-col gap-2">
                        <textarea
                            v-model="note"
                            rows="2"
                            maxlength="1000"
                            placeholder="Alasan ditolak (opsional, dilihat teknisi)"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                        />
                        <div class="flex gap-2">
                            <Button size="sm" variant="destructive" :disabled="busy === cr.id" @click="reject(cr)"
                                ><X class="size-4" /> Tolak request</Button
                            >
                            <Button size="sm" variant="outline" @click="rejecting = null">Batal</Button>
                        </div>
                    </div>

                    <div v-else class="flex flex-wrap gap-2">
                        <Button v-if="cr.map" as-child size="sm" variant="outline">
                            <Link :href="cr.map"><MapPin class="size-4" /> Lihat di peta</Link>
                        </Button>
                        <template v-if="canApprove && cr.status === 'pending'">
                            <Button v-if="cr.editable" as-child size="sm" variant="outline">
                                <Link :href="route('map', { approval: cr.id })"><Pencil class="size-4" /> Edit di peta</Link>
                            </Button>
                            <Button size="sm" variant="outline" :disabled="busy === cr.id" @click="startReject(cr)"
                                ><X class="size-4" /> Tolak</Button
                            >
                            <Button size="sm" :disabled="busy === cr.id" @click="approve(cr)"><Check class="size-4" /> Setujui</Button>
                        </template>
                        <Button
                            v-if="!canApprove && cr.status === 'pending'"
                            size="sm"
                            variant="outline"
                            :disabled="busy === cr.id"
                            @click="cancel(cr)"
                        >
                            <Undo2 class="size-4" /> Batalkan
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <div v-if="requests.last_page > 1" class="flex items-center justify-between text-sm">
                <span class="text-muted-foreground">Halaman {{ requests.current_page }} dari {{ requests.last_page }}</span>
                <div class="flex gap-2">
                    <Button variant="outline" size="sm" :disabled="!requests.prev_page_url" @click="visit(filters.tab, requests.current_page - 1)">
                        <ChevronLeft class="size-4" /> Sebelumnya
                    </Button>
                    <Button variant="outline" size="sm" :disabled="!requests.next_page_url" @click="visit(filters.tab, requests.current_page + 1)">
                        Berikutnya <ChevronRight class="size-4" />
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
