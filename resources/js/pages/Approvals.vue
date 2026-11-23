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

interface Item {
    id: number;
    status: Status;
    /** Items of the batch this one builds on: approving it needs them too. */
    needs: number[];
    action: ActivityAction;
    subject_type: string;
    subject_id: number | null;
    subject_label: string | null;
    preview: PreviewEntry[] | null;
    photos: string[];
    current_photo: string | null;
    conflict: string | null;
    editable: boolean;
}

interface Batch {
    id: number;
    status: Status;
    note: string | null;
    review_note: string | null;
    submitted_at: string | null;
    reviewed_at: string | null;
    user: { id: number; name: string } | null;
    reviewer: { id: number; name: string } | null;
    area: { id: number; code: string; name: string } | null;
    map: string | null;
    items: Item[];
}

const props = defineProps<{
    batches: {
        data: Batch[];
        current_page: number;
        last_page: number;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { tab: 'pending' | 'history'; area_id: number | null };
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

// ---- Tabs and filters, kept in the URL
const filter = reactive({ area_id: props.filters.area_id });

function visit(tab: string, page?: number) {
    const query = Object.fromEntries(
        Object.entries({ tab, area_id: filter.area_id, page }).filter(([, v]) => v !== '' && v !== null && v !== undefined),
    );
    router.get(route('approvals.index'), query, { preserveScroll: true, replace: true });
}

// ---- Decisions on a whole batch
const busy = ref<number | null>(null);
const rejecting = ref<number | null>(null);
const note = ref('');

const done = { preserveScroll: true, onFinish: () => (busy.value = null) };

// ---- Choosing which changes to approve; the ones left out are rejected.
/** Items left out of the approval, by item id. */
const skipped = ref<Record<number, boolean>>({});

const chosen = (batch: Batch) => batch.items.filter((i) => !skipped.value[i.id]);

/** Choosing an item also chooses what it builds on; leaving one out also leaves out what builds on it. */
function toggle(batch: Batch, item: Item, on: boolean) {
    const stack = [item];
    while (stack.length) {
        const current = stack.pop()!;
        if (!skipped.value[current.id] === on) continue;
        skipped.value[current.id] = !on;
        const related = on ? batch.items.filter((i) => current.needs.includes(i.id)) : batch.items.filter((i) => i.needs.includes(current.id));
        stack.push(...related);
    }
}

function approve(batch: Batch) {
    const items = chosen(batch);
    busy.value = batch.id;
    router.post(route('approvals.approve', { batch: batch.id }), items.length === batch.items.length ? {} : { items: items.map((i) => i.id) }, done);
}

const batchStatus = (batch: Batch) =>
    batch.status === 'approved' && batch.items.some((i) => i.status === 'rejected')
        ? { label: 'Disetujui sebagian', class: statuses.approved.class }
        : statuses[batch.status];

function startReject(batch: Batch) {
    rejecting.value = batch.id;
    note.value = '';
}

function reject(batch: Batch) {
    busy.value = batch.id;
    router.post(route('approvals.reject', { batch: batch.id }), { note: note.value }, { ...done, onSuccess: () => (rejecting.value = null) });
}

function cancel(batch: Batch) {
    busy.value = batch.id;
    router.post(route('approvals.cancel', { batch: batch.id }), {}, done);
}

// ---- Display
const formatTime = (iso: string | null) =>
    iso ? new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';

const typeLabel = (type: string) => activityTypes[type] ?? type;
const conflicts = (batch: Batch) => batch.items.filter((i) => i.conflict).length;

/** Items with their changes folded open, by item id. */
const expanded = ref<Record<number, boolean>>({});

const emptyText = computed(() =>
    props.filters.tab === 'pending'
        ? props.canApprove
            ? 'Tidak ada paket yang menunggu persetujuan.'
            : 'Tidak ada paket yang sedang menunggu. Perubahan baru disimpan sebagai draft di peta, lalu dikirim dari bar draft.'
        : 'Belum ada riwayat.',
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
                <span class="ml-auto text-sm text-muted-foreground">{{ batches.total }} paket</span>
            </div>

            <Card v-if="!batches.data.length" class="py-0">
                <CardContent class="p-6 text-center text-sm text-muted-foreground">{{ emptyText }}</CardContent>
            </Card>

            <Card v-for="batch in batches.data" :key="batch.id" class="gap-0 py-0">
                <CardContent class="flex flex-col gap-3 p-4">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="font-semibold">Paket #{{ batch.id }}</span>
                        <span class="text-muted-foreground">· {{ batch.items.length }} perubahan</span>
                        <span v-if="batch.area" class="text-xs text-muted-foreground">· {{ batch.area.name }}</span>
                        <span v-else class="text-xs text-muted-foreground">· beberapa area</span>
                        <span class="ml-auto rounded px-1.5 py-0.5 text-xs font-medium" :class="batchStatus(batch).class">
                            {{ batchStatus(batch).label }}
                        </span>
                    </div>

                    <div class="text-xs text-muted-foreground">
                        Dikirim <span class="font-medium text-foreground">{{ batch.user?.name ?? 'pengguna terhapus' }}</span> ·
                        {{ formatTime(batch.submitted_at) }}
                        <template v-if="batch.status !== 'pending' && batch.reviewed_at">
                            <br />
                            {{ batchStatus(batch).label }}
                            <template v-if="batch.reviewer"
                                >oleh <span class="font-medium text-foreground">{{ batch.reviewer.name }}</span></template
                            >
                            · {{ formatTime(batch.reviewed_at) }}
                        </template>
                    </div>

                    <p v-if="batch.note" class="rounded-md bg-muted px-3 py-2 text-sm">
                        <span class="text-muted-foreground">Catatan teknisi:</span> {{ batch.note }}
                    </p>
                    <p v-if="batch.review_note" class="rounded-md bg-muted px-3 py-2 text-sm">
                        <span class="text-muted-foreground">Catatan admin:</span> {{ batch.review_note }}
                    </p>
                    <p
                        v-if="conflicts(batch)"
                        class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
                    >
                        <TriangleAlert class="mt-0.5 size-4 shrink-0" /> {{ conflicts(batch) }} perubahan menyentuh data yang sudah diubah orang lain
                        sejak dibuat.
                    </p>

                    <ul class="flex flex-col divide-y rounded-md border">
                        <li
                            v-for="item in batch.items"
                            :key="item.id"
                            class="flex flex-col gap-2 px-3 py-2"
                            :class="
                                (skipped[item.id] && batch.status === 'pending') || (batch.status === 'approved' && item.status === 'rejected')
                                    ? 'opacity-60'
                                    : ''
                            "
                        >
                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                <input
                                    v-if="canApprove && batch.status === 'pending' && batch.items.length > 1"
                                    type="checkbox"
                                    class="size-4 accent-primary"
                                    :checked="!skipped[item.id]"
                                    :aria-label="`Setujui ${item.subject_label ?? 'perubahan'}`"
                                    @change="toggle(batch, item, ($event.target as HTMLInputElement).checked)"
                                />
                                <span
                                    v-if="batch.status === 'approved' && item.status === 'rejected'"
                                    class="rounded px-1.5 py-0.5 text-xs font-medium"
                                    :class="statuses.rejected.class"
                                    >Ditolak</span
                                >
                                <span class="rounded px-1.5 py-0.5 text-xs font-medium" :class="activityActions[item.action]?.class">
                                    {{ activityActions[item.action]?.label ?? item.action }}
                                </span>
                                <span class="text-muted-foreground">{{ typeLabel(item.subject_type) }}</span>
                                <button
                                    type="button"
                                    class="min-w-0 flex-1 truncate text-left font-medium"
                                    @click="expanded[item.id] = !expanded[item.id]"
                                >
                                    {{ item.subject_label }}
                                </button>
                                <Button v-if="item.editable" as-child size="sm" variant="ghost" class="h-7 px-2">
                                    <Link :href="route('map', { approval: item.id })"><Pencil class="size-3.5" /> Edit</Link>
                                </Button>
                            </div>
                            <p v-if="item.conflict" class="flex items-start gap-1.5 text-xs text-amber-800 dark:text-amber-300">
                                <TriangleAlert class="mt-0.5 size-3.5 shrink-0" /> {{ item.conflict }}
                            </p>
                            <dl
                                v-if="expanded[item.id] && item.preview?.[0] && changeLines(item.preview[0].action, item.preview[0].changes).length"
                                class="grid grid-cols-[auto_1fr] gap-x-3 text-xs"
                            >
                                <template v-for="line in changeLines(item.preview[0].action, item.preview[0].changes)" :key="line.field">
                                    <dt class="text-muted-foreground">{{ line.field }}</dt>
                                    <dd class="mb-0.5 break-all">{{ line.text }}</dd>
                                </template>
                            </dl>
                            <div
                                v-if="item.photos.length || item.current_photo"
                                class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                            >
                                <img
                                    v-if="item.current_photo"
                                    :src="item.current_photo"
                                    alt="Foto sekarang"
                                    class="h-20 w-20 rounded-md border object-cover"
                                    :class="item.action === 'deleted' && 'opacity-50'"
                                    loading="lazy"
                                />
                                <span v-if="item.current_photo && item.photos.length">→</span>
                                <a v-for="url in item.photos" :key="url" :href="url" target="_blank" rel="noopener">
                                    <img :src="url" alt="Foto request" class="h-20 w-20 rounded-md border object-cover" loading="lazy" />
                                </a>
                            </div>
                        </li>
                    </ul>
                    <p class="text-xs text-muted-foreground">
                        Klik nama perubahan untuk melihat rinciannya.
                        <template v-if="canApprove && batch.status === 'pending' && batch.items.length > 1">
                            Hilangkan centang pada perubahan yang tidak disetujui; perubahan itu akan ditolak.
                        </template>
                    </p>

                    <div v-if="rejecting === batch.id" class="flex flex-col gap-2">
                        <textarea
                            v-model="note"
                            rows="2"
                            maxlength="1000"
                            placeholder="Alasan ditolak (opsional, dilihat teknisi)"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                        />
                        <div class="flex gap-2">
                            <Button size="sm" variant="destructive" :disabled="busy === batch.id" @click="reject(batch)">
                                <X class="size-4" /> Tolak paket
                            </Button>
                            <Button size="sm" variant="outline" @click="rejecting = null">Batal</Button>
                        </div>
                    </div>

                    <div v-else class="flex flex-wrap gap-2">
                        <Button v-if="batch.map" as-child size="sm" variant="outline">
                            <Link :href="batch.map"><MapPin class="size-4" /> Lihat paket di peta</Link>
                        </Button>
                        <template v-if="canApprove && batch.status === 'pending'">
                            <Button size="sm" variant="outline" :disabled="busy === batch.id" @click="startReject(batch)"
                                ><X class="size-4" /> Tolak</Button
                            >
                            <Button size="sm" :disabled="busy === batch.id || !chosen(batch).length" @click="approve(batch)">
                                <Check class="size-4" />
                                {{
                                    chosen(batch).length === batch.items.length
                                        ? 'Setujui semua'
                                        : `Setujui ${chosen(batch).length} dari ${batch.items.length}`
                                }}
                            </Button>
                        </template>
                        <Button
                            v-if="!canApprove && batch.status === 'pending'"
                            size="sm"
                            variant="outline"
                            :disabled="busy === batch.id"
                            @click="cancel(batch)"
                        >
                            <Undo2 class="size-4" /> Batalkan paket
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <div v-if="batches.last_page > 1" class="flex items-center justify-between text-sm">
                <span class="text-muted-foreground">Halaman {{ batches.current_page }} dari {{ batches.last_page }}</span>
                <div class="flex gap-2">
                    <Button variant="outline" size="sm" :disabled="!batches.prev_page_url" @click="visit(filters.tab, batches.current_page - 1)">
                        <ChevronLeft class="size-4" /> Sebelumnya
                    </Button>
                    <Button variant="outline" size="sm" :disabled="!batches.next_page_url" @click="visit(filters.tab, batches.current_page + 1)">
                        Berikutnya <ChevronRight class="size-4" />
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
