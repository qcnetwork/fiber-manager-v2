<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { cableTypes, formatLength } from '@/lib/cables';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    stats: { odcs: number; odps: number; customers: number; total_ports: number; used_ports: number; cable_m: number };
    cableByType: { type: string; count: number; length_m: number }[];
    areas: { id: number; code: string; name: string; odcs_count: number; odps_count: number; total_ports: number; used_ports: number }[] | null;
    almostFull: { id: number; code: string; capacity: number; used_ports: number; free_ports: number; area: { code: string } }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const pct = (used: number, total: number) => (total ? Math.round((used / total) * 100) : 0);
const occupancy = computed(() => pct(props.stats.used_ports, props.stats.total_ports));

const tiles = computed(() => [
    { label: 'ODC', value: props.stats.odcs },
    { label: 'ODP', value: props.stats.odps },
    { label: 'Pelanggan', value: props.stats.customers },
    { label: 'Port ODP kosong', value: props.stats.total_ports - props.stats.used_ports },
]);

const barColor = (p: number) => (p >= 90 ? 'bg-red-600' : p >= 50 ? 'bg-amber-500' : 'bg-green-600');
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4">
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Card v-for="tile in tiles" :key="tile.label">
                    <CardHeader class="pb-2">
                        <CardTitle class="text-sm font-medium text-muted-foreground">{{ tile.label }}</CardTitle>
                    </CardHeader>
                    <CardContent class="text-3xl font-semibold tabular-nums">{{ tile.value.toLocaleString('id-ID') }}</CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="text-base">Okupansi port ODP</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="mb-2 flex items-baseline gap-2">
                        <span class="text-3xl font-semibold tabular-nums">{{ occupancy }}%</span>
                        <span class="text-sm text-muted-foreground">{{ stats.used_ports }} dari {{ stats.total_ports }} port terpakai</span>
                    </div>
                    <div class="h-3 overflow-hidden rounded-full bg-muted">
                        <div class="h-full rounded-full" :class="barColor(occupancy)" :style="{ width: `${occupancy}%` }" />
                    </div>
                </CardContent>
            </Card>

            <div class="grid gap-4 lg:grid-cols-2">
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
            </div>
        </div>
    </AppLayout>
</template>
