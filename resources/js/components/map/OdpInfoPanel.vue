<script setup lang="ts">
import StatusBadge from '@/components/map/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import type { AssetStatus, PortStatus } from '@/types';
import { ArrowLeft, Navigation } from '@lucide/vue';
import { computed } from 'vue';

/** Read-only ODP summary from the coverage page: no customer data, nothing to edit. */
export interface OdpInfo {
    id: number;
    code: string;
    name: string | null;
    lat: number;
    lng: number;
    capacity: number;
    splitter_ratio: string | null;
    pole_number: string | null;
    address: string | null;
    installed_at: string | null;
    status: AssetStatus;
    area: string | null;
    odc: string | null;
    used_ports: number;
    free_ports: number;
    ports: { number: number; status: PortStatus }[];
}

const props = defineProps<{
    odp: OdpInfo;
    /** Distance from the checked point, when the ODP was in the result. */
    distance?: number | null;
}>();

const emit = defineEmits<{ close: [] }>();

const portStyles: Record<PortStatus, string> = {
    kosong: 'border-green-500 bg-green-50 text-green-800 dark:bg-green-950 dark:text-green-200',
    terpakai: 'border-red-500 bg-red-500 text-white',
    booking: 'border-amber-500 bg-amber-100 text-amber-900 dark:bg-amber-900 dark:text-amber-100',
    rusak: 'border-gray-500 bg-gray-300 text-gray-700 line-through dark:bg-gray-700 dark:text-gray-300',
};

const navUrl = computed(() => `https://www.google.com/maps/dir/?api=1&destination=${props.odp.lat},${props.odp.lng}`);
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex items-start gap-2">
            <Button variant="ghost" size="icon" class="-ml-2 shrink-0" title="Kembali ke hasil cek" @click="emit('close')">
                <ArrowLeft class="size-4" />
                <span class="sr-only">Kembali</span>
            </Button>
            <div class="min-w-0">
                <div class="text-xs font-medium text-muted-foreground">ODP{{ odp.area ? ` · ${odp.area}` : '' }}</div>
                <h2 class="text-lg leading-tight font-semibold">{{ odp.code }}</h2>
                <div v-if="odp.name" class="text-sm text-muted-foreground">{{ odp.name }}</div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <StatusBadge :status="odp.status" />
            <span class="text-sm">
                <b>{{ odp.used_ports }}</b
                >/{{ odp.capacity }} port terpakai · <b>{{ odp.free_ports }}</b> kosong
            </span>
        </div>

        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
            <template v-if="distance != null">
                <dt class="text-muted-foreground">Jarak</dt>
                <dd>{{ distance }} m dari titik cek</dd>
            </template>
            <dt class="text-muted-foreground">ODC induk</dt>
            <dd>{{ odp.odc ?? '—' }}</dd>
            <dt class="text-muted-foreground">Splitter</dt>
            <dd>{{ odp.splitter_ratio ?? '—' }}</dd>
            <dt class="text-muted-foreground">No. tiang</dt>
            <dd>{{ odp.pole_number ?? '—' }}</dd>
            <dt class="text-muted-foreground">Alamat</dt>
            <dd>{{ odp.address ?? '—' }}</dd>
            <dt class="text-muted-foreground">Koordinat</dt>
            <dd class="font-mono text-xs">{{ odp.lat.toFixed(6) }}, {{ odp.lng.toFixed(6) }}</dd>
            <template v-if="odp.installed_at">
                <dt class="text-muted-foreground">Dipasang</dt>
                <dd>{{ odp.installed_at }}</dd>
            </template>
        </dl>

        <div>
            <h3 class="mb-2 text-sm font-semibold">Port</h3>
            <div class="grid grid-cols-4 gap-2">
                <div
                    v-for="port in odp.ports"
                    :key="port.number"
                    class="flex h-10 items-center justify-center rounded-md border-2 text-sm font-semibold"
                    :class="portStyles[port.status]"
                    :title="port.status"
                >
                    {{ port.number }}
                </div>
            </div>
            <div class="mt-2 flex flex-wrap gap-3 text-xs text-muted-foreground">
                <span><span class="mr-1 inline-block size-2.5 rounded-sm bg-green-500" />Kosong</span>
                <span><span class="mr-1 inline-block size-2.5 rounded-sm bg-red-500" />Terpakai</span>
                <span><span class="mr-1 inline-block size-2.5 rounded-sm bg-amber-400" />Booking</span>
                <span><span class="mr-1 inline-block size-2.5 rounded-sm bg-gray-400" />Rusak</span>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <Button as="a" :href="navUrl" target="_blank" variant="outline" size="sm"><Navigation class="size-4" /> Navigasi</Button>
        </div>
    </div>
</template>
