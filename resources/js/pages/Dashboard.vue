<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { activityActions, activityTypes, type ActivityAction } from '@/lib/activity';
import { cableTypes, formatLength } from '@/lib/cables';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    stats: {
        odcs: number;
        odps: number;
        customers: number;
        customers_this_month: number;
        total_ports: number;
        used_ports: number;
        cable_m: number;
        olts: number;
        pon_ports: number;
        pon_used: number;
    };
    portStatus: Record<'kosong' | 'terpakai' | 'booking' | 'rusak', number>;
    odpFill: { empty: number; low: number; high: number; full: number };
    growth: { month: string; count: number }[];
    gaps: {
        customers_without_location: number;
        customers_without_port: number;
        odps_without_odc: number;
        odcs_without_olt: number;
        odps_without_photo: number;
        odps_not_active: number;
    };
    activity:
        | { id: number; user_name: string | null; action: ActivityAction; subject_type: string; subject_label: string | null; created_at: string }[]
        | null;
    cableByType: { type: string; count: number; length_m: number }[];
    areas:
        | {
              id: number;
              code: string;
              name: string;
              odcs_count: number;
              odps_count: number;
              customers_count: number;
              total_ports: number;
              used_ports: number;
          }[]
        | null;
    pops: { id: number; code: string; name: string; olts_count: number; total_ports: number; used_ports: number; broken_ports: number }[];
    almostFull: { id: number; code: string; capacity: number; used_ports: number; free_ports: number; area: { code: string } }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const num = (n: number) => n.toLocaleString('id-ID');
const pct = (used: number, total: number) => (total ? Math.round((used / total) * 100) : 0);
const occupancy = computed(() => pct(props.stats.used_ports, props.stats.total_ports));

const tiles = computed(() => [
    { label: 'ODC', value: num(props.stats.odcs) },
    { label: 'ODP', value: num(props.stats.odps) },
    {
        label: 'Pelanggan',
        value: num(props.stats.customers),
        sub: props.stats.customers_this_month ? `+${num(props.stats.customers_this_month)} bulan ini` : 'Belum ada yang baru bulan ini',
    },
    { label: 'Port ODP kosong', value: num(props.stats.total_ports - props.stats.used_ports), sub: `dari ${num(props.stats.total_ports)} port` },
    {
        label: 'Port PON terpakai',
        value: `${num(props.stats.pon_used)}/${num(props.stats.pon_ports)}`,
        sub: `${num(props.stats.olts)} OLT`,
    },
    { label: 'Panjang kabel', value: formatLength(props.stats.cable_m), sub: `${num(props.cableByType.reduce((n, c) => n + c.count, 0))} jalur` },
]);

const barColor = (p: number) => (p >= 90 ? 'bg-red-600' : p >= 50 ? 'bg-amber-500' : 'bg-green-600');

// Port states, in the order they fill the bar.
const portSegments = computed(() =>
    [
        { key: 'terpakai', label: 'Terpakai', color: 'bg-blue-600' },
        { key: 'booking', label: 'Booking', color: 'bg-amber-500' },
        { key: 'rusak', label: 'Rusak', color: 'bg-violet-600' },
        { key: 'kosong', label: 'Kosong', color: 'bg-muted-foreground/25' },
    ].map((s) => ({ ...s, count: props.portStatus[s.key as keyof typeof props.portStatus] ?? 0 })),
);

// Same colors as the ODP markers on the map.
const fillGroups = computed(() => {
    const groups = [
        { label: 'Belum ada pelanggan', count: props.odpFill.empty, color: 'bg-slate-400' },
        { label: 'Terisi < 50%', count: props.odpFill.low, color: 'bg-green-600' },
        { label: 'Terisi ≥ 50%', count: props.odpFill.high, color: 'bg-amber-500' },
        { label: 'Penuh', count: props.odpFill.full, color: 'bg-red-600' },
    ];
    const max = Math.max(1, ...groups.map((g) => g.count));
    return groups.map((g) => ({ ...g, width: (g.count / max) * 100 }));
});

const monthName = (month: string, style: 'short' | 'long' = 'short') =>
    new Date(`${month}-01T00:00:00`).toLocaleDateString('id-ID', style === 'short' ? { month: 'short' } : { month: 'long', year: 'numeric' });
const growthMax = computed(() => Math.max(1, ...props.growth.map((g) => g.count)));
const growthTotal = computed(() => props.growth.reduce((n, g) => n + g.count, 0));

const gapRows = computed(() => [
    { label: 'Pelanggan tanpa titik lokasi', count: props.gaps.customers_without_location },
    { label: 'Pelanggan belum terhubung ke port ODP', count: props.gaps.customers_without_port },
    { label: 'ODP belum terhubung ke ODC', count: props.gaps.odps_without_odc },
    { label: 'ODC belum terhubung ke port OLT', count: props.gaps.odcs_without_olt },
    { label: 'ODP tanpa foto', count: props.gaps.odps_without_photo },
    { label: 'ODP berstatus rencana / rusak', count: props.gaps.odps_not_active },
]);

const relative = new Intl.RelativeTimeFormat('id-ID', { numeric: 'auto' });
function ago(iso: string) {
    const minutes = Math.round((new Date(iso).getTime() - Date.now()) / 60000);
    if (minutes > -60) return relative.format(minutes, 'minute');
    if (minutes > -60 * 24) return relative.format(Math.round(minutes / 60), 'hour');
    return relative.format(Math.round(minutes / 60 / 24), 'day');
}
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
                <Card v-for="tile in tiles" :key="tile.label" class="gap-2">
                    <CardHeader class="pb-0">
                        <CardTitle class="text-sm font-medium text-muted-foreground">{{ tile.label }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-semibold tabular-nums sm:text-3xl">{{ tile.value }}</div>
                        <div v-if="tile.sub" class="mt-1 text-xs text-muted-foreground">{{ tile.sub }}</div>
                    </CardContent>
                </Card>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-base">Okupansi port ODP</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="mb-2 flex items-baseline gap-2">
                            <span class="text-3xl font-semibold tabular-nums">{{ occupancy }}%</span>
                            <span class="text-sm text-muted-foreground"
                                >{{ num(stats.used_ports) }} dari {{ num(stats.total_ports) }} port tidak kosong</span
                            >
                        </div>
                        <div class="flex h-3 gap-0.5 overflow-hidden rounded-full bg-muted">
                            <div
                                v-for="s in portSegments.filter((s) => s.count)"
                                :key="s.key"
                                class="h-full first:rounded-l-full last:rounded-r-full"
                                :class="s.color"
                                :style="{ width: `${(s.count / Math.max(1, stats.total_ports)) * 100}%` }"
                                :title="`${s.label}: ${num(s.count)} port`"
                            />
                        </div>
                        <ul class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-4">
                            <li v-for="s in portSegments" :key="s.key" class="flex items-center gap-2">
                                <span class="inline-block size-2.5 rounded-sm" :class="s.color" />
                                <span class="text-muted-foreground">{{ s.label }}</span>
                                <span class="ml-auto font-medium tabular-nums sm:ml-0">{{ num(s.count) }}</span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-base">Sebaran isi ODP · {{ num(stats.odps) }} ODP</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul class="space-y-2.5 text-sm">
                            <li v-for="g in fillGroups" :key="g.label" class="grid grid-cols-[9rem_1fr_2.5rem] items-center gap-3">
                                <span class="text-muted-foreground">{{ g.label }}</span>
                                <div class="h-2.5">
                                    <div v-if="g.count" class="h-full rounded-r" :class="g.color" :style="{ width: `${g.width}%` }" />
                                </div>
                                <span class="text-right font-medium tabular-nums">{{ num(g.count) }}</span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-base">Pelanggan baru per bulan</CardTitle>
                        <p class="text-sm text-muted-foreground">{{ num(growthTotal) }} pelanggan dalam 12 bulan terakhir</p>
                    </CardHeader>
                    <CardContent>
                        <div class="flex h-40 items-end gap-1 border-b sm:gap-2">
                            <div
                                v-for="g in growth"
                                :key="g.month"
                                class="group relative flex h-full flex-1 flex-col justify-end"
                                :title="`${monthName(g.month, 'long')}: ${num(g.count)} pelanggan`"
                            >
                                <span
                                    class="pointer-events-none absolute inset-x-0 text-center text-xs font-medium tabular-nums opacity-0 group-hover:opacity-100"
                                    :style="{ bottom: `calc(${(g.count / growthMax) * 100}% + 2px)` }"
                                    >{{ g.count }}</span
                                >
                                <div
                                    v-if="g.count"
                                    class="rounded-t bg-blue-600 group-hover:bg-blue-500"
                                    :style="{ height: `${(g.count / growthMax) * 100}%` }"
                                />
                            </div>
                        </div>
                        <div class="mt-1 flex gap-1 text-center text-[10px] text-muted-foreground sm:gap-2 sm:text-xs">
                            <span v-for="g in growth" :key="g.month" class="flex-1 truncate">{{ monthName(g.month) }}</span>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-base">Kelengkapan data</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul class="divide-y text-sm">
                            <li v-for="row in gapRows" :key="row.label" class="flex items-center gap-2 py-2">
                                <CircleAlert v-if="row.count" class="size-4 shrink-0 text-amber-600" />
                                <CircleCheck v-else class="size-4 shrink-0 text-green-600" />
                                <span class="flex-1">{{ row.label }}</span>
                                <span class="font-medium tabular-nums" :class="row.count ? '' : 'text-muted-foreground'">{{
                                    row.count ? num(row.count) : 'Lengkap'
                                }}</span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-base">Kabel terpasang · {{ formatLength(stats.cable_m) }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p v-if="!cableByType.length" class="text-sm text-muted-foreground">Belum ada jalur kabel yang digambar.</p>
                        <ul v-else class="divide-y text-sm">
                            <li v-for="row in cableByType" :key="row.type" class="flex items-center gap-3 py-2">
                                <span class="inline-block h-1.5 w-5 rounded-full" :style="{ background: cableTypes[row.type]?.color }" />
                                <span class="flex-1">{{ cableTypes[row.type]?.label ?? row.type }}</span>
                                <span class="text-muted-foreground">{{ row.count }} jalur</span>
                                <span class="w-20 text-right font-medium tabular-nums">{{ formatLength(row.length_m) }}</span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <Card v-if="areas">
                    <CardHeader class="pb-2">
                        <CardTitle class="text-base">Per area</CardTitle>
                    </CardHeader>
                    <CardContent class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-muted-foreground">
                                <tr>
                                    <th class="py-2 font-medium">Area</th>
                                    <th class="py-2 text-right font-medium">ODC</th>
                                    <th class="py-2 text-right font-medium">ODP</th>
                                    <th class="py-2 text-right font-medium">Pelanggan</th>
                                    <th class="w-40 py-2 pl-4 font-medium">Okupansi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="area in areas" :key="area.id">
                                    <td class="py-2">
                                        {{ area.name }} <span class="text-muted-foreground">({{ area.code }})</span>
                                    </td>
                                    <td class="py-2 text-right tabular-nums">{{ area.odcs_count }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ area.odps_count }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ num(area.customers_count) }}</td>
                                    <td class="py-2 pl-4">
                                        <div class="flex items-center gap-2">
                                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    class="h-full rounded-full"
                                                    :class="barColor(pct(area.used_ports, area.total_ports))"
                                                    :style="{ width: `${pct(area.used_ports, area.total_ports)}%` }"
                                                />
                                            </div>
                                            <span class="w-9 text-right tabular-nums">{{ pct(area.used_ports, area.total_ports) }}%</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                <Card v-if="pops.length">
                    <CardHeader class="pb-2">
                        <CardTitle class="text-base">Port PON OLT per POP</CardTitle>
                    </CardHeader>
                    <CardContent class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-muted-foreground">
                                <tr>
                                    <th class="py-2 font-medium">POP</th>
                                    <th class="py-2 text-right font-medium">OLT</th>
                                    <th class="py-2 text-right font-medium">Port</th>
                                    <th class="w-40 py-2 pl-4 font-medium">Terpakai</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="pop in pops" :key="pop.id">
                                    <td class="py-2">
                                        {{ pop.name }}
                                        <span v-if="pop.broken_ports" class="text-xs text-violet-700 dark:text-violet-300">
                                            · {{ pop.broken_ports }} rusak</span
                                        >
                                    </td>
                                    <td class="py-2 text-right tabular-nums">{{ pop.olts_count }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ pop.used_ports }}/{{ pop.total_ports }}</td>
                                    <td class="py-2 pl-4">
                                        <div class="flex items-center gap-2">
                                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    class="h-full rounded-full"
                                                    :class="barColor(pct(pop.used_ports, pop.total_ports))"
                                                    :style="{ width: `${pct(pop.used_ports, pop.total_ports)}%` }"
                                                />
                                            </div>
                                            <span class="w-9 text-right tabular-nums">{{ pct(pop.used_ports, pop.total_ports) }}%</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardTitle class="text-base">ODP hampir / sudah penuh</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p v-if="!almostFull.length" class="text-sm text-muted-foreground">Tidak ada ODP yang hampir penuh.</p>
                        <ul v-else class="divide-y text-sm">
                            <li v-for="odp in almostFull" :key="odp.id" class="flex items-center justify-between py-2">
                                <span>
                                    <span class="font-medium">{{ odp.code }}</span>
                                    <span class="text-muted-foreground"> · {{ odp.area.code }}</span>
                                </span>
                                <span :class="odp.free_ports === 0 ? 'font-semibold text-red-600' : 'text-amber-600'">
                                    {{ odp.free_ports === 0 ? 'Penuh' : `Sisa ${odp.free_ports} port` }}
                                </span>
                            </li>
                        </ul>
                        <Link :href="route('map')" class="mt-3 inline-block text-sm text-blue-600 hover:underline dark:text-blue-400"
                            >Buka peta →</Link
                        >
                    </CardContent>
                </Card>

                <Card v-if="activity">
                    <CardHeader class="pb-2">
                        <CardTitle class="text-base">Aktivitas terbaru</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p v-if="!activity.length" class="text-sm text-muted-foreground">Belum ada aktivitas tercatat.</p>
                        <ul v-else class="divide-y text-sm">
                            <li v-for="log in activity" :key="log.id" class="flex items-start gap-2 py-2">
                                <span class="mt-0.5 shrink-0 rounded px-1.5 py-0.5 text-xs font-medium" :class="activityActions[log.action]?.class">
                                    {{ activityActions[log.action]?.label ?? log.action }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate">
                                        <span class="text-muted-foreground">{{ activityTypes[log.subject_type] ?? log.subject_type }}</span>
                                        {{ log.subject_label }}
                                    </span>
                                    <span class="block text-xs text-muted-foreground"
                                        >{{ log.user_name ?? 'Sistem' }} · {{ ago(log.created_at) }}</span
                                    >
                                </span>
                            </li>
                        </ul>
                        <Link :href="route('activity.index')" class="mt-3 inline-block text-sm text-blue-600 hover:underline dark:text-blue-400"
                            >Lihat semua riwayat →</Link
                        >
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
