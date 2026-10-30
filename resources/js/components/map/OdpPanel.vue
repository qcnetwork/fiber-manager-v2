<script setup lang="ts">
import PhotoGallery from '@/components/map/PhotoGallery.vue';
import StatusBadge from '@/components/map/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import type { Odp, OdpPort, PortStatus } from '@/types';
import { Navigation, Pencil, Trash2, X } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    odp: Odp;
    canEdit: boolean;
    canDelete: boolean;
}>();

const emit = defineEmits<{
    edit: [];
    delete: [];
    close: [];
    refresh: [];
    openPort: [port: OdpPort];
    selectOdc: [id: number];
    openCustomer: [id: number];
}>();

const portStyles: Record<PortStatus, string> = {
    kosong: 'border-green-500 bg-green-50 text-green-800 dark:bg-green-950 dark:text-green-200',
    terpakai: 'border-red-500 bg-red-500 text-white',
    booking: 'border-amber-500 bg-amber-100 text-amber-900 dark:bg-amber-900 dark:text-amber-100',
    rusak: 'border-gray-500 bg-gray-300 text-gray-700 line-through dark:bg-gray-700 dark:text-gray-300',
};

const ports = computed(() => props.odp.ports ?? []);
const used = computed(() => ports.value.filter((p) => p.status !== 'kosong').length);
const navUrl = computed(() => `https://www.google.com/maps/dir/?api=1&destination=${props.odp.lat},${props.odp.lng}`);
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex items-start justify-between gap-2">
            <div>
                <div class="text-xs font-medium text-muted-foreground">ODP · {{ odp.area?.name }}</div>
                <h2 class="text-lg font-semibold leading-tight">{{ odp.code }}</h2>
                <div v-if="odp.name" class="text-sm text-muted-foreground">{{ odp.name }}</div>
            </div>
            <Button variant="ghost" size="icon" @click="emit('close')"><X class="size-4" /></Button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <StatusBadge :status="odp.status" />
            <span class="text-sm">
                <b>{{ used }}</b
                >/{{ odp.capacity }} port terpakai · <b>{{ odp.capacity - used }}</b> kosong
            </span>
        </div>

        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
            <dt class="text-muted-foreground">ODC induk</dt>
            <dd>
                <button v-if="odp.odc" class="font-medium text-blue-600 hover:underline dark:text-blue-400" @click="emit('selectOdc', odp.odc.id)">
                    {{ odp.odc.code }}
                </button>
                <span v-else>—</span>
                <span v-if="odp.odc_port"> · port {{ odp.odc_port }}</span>
            </dd>
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
            <template v-if="odp.notes">
                <dt class="text-muted-foreground">Catatan</dt>
                <dd class="whitespace-pre-line">{{ odp.notes }}</dd>
            </template>
        </dl>

        <div>
            <div class="mb-2 flex items-center justify-between">
                <h3 class="text-sm font-semibold">Port</h3>
                <span class="text-xs text-muted-foreground">{{ canEdit ? 'Klik port untuk mengubah' : '' }}</span>
            </div>
            <div class="grid grid-cols-4 gap-2">
                <button
                    v-for="port in ports"
                    :key="port.id"
                    type="button"
                    class="flex h-12 flex-col items-center justify-center rounded-md border-2 text-sm font-semibold transition hover:opacity-80 disabled:cursor-default disabled:hover:opacity-100"
                    :class="portStyles[port.status]"
                    :title="port.customer ? port.customer.name : port.status"
                    :disabled="!canEdit"
                    @click="emit('openPort', port)"
                >
                    {{ port.number }}
                    <span v-if="port.customer" class="max-w-full truncate px-1 text-[10px] font-normal">{{ port.customer.name }}</span>
                </button>
            </div>
            <div class="mt-2 flex flex-wrap gap-3 text-xs text-muted-foreground">
                <span><span class="mr-1 inline-block size-2.5 rounded-sm bg-green-500" />Kosong</span>
                <span><span class="mr-1 inline-block size-2.5 rounded-sm bg-red-500" />Terpakai</span>
                <span><span class="mr-1 inline-block size-2.5 rounded-sm bg-amber-400" />Booking</span>
                <span><span class="mr-1 inline-block size-2.5 rounded-sm bg-gray-400" />Rusak</span>
            </div>
        </div>

        <div v-if="ports.some((p) => p.customer)">
            <h3 class="mb-2 text-sm font-semibold">Pelanggan</h3>
            <ul class="divide-y rounded-md border text-sm">
                <li v-for="port in ports.filter((p) => p.customer)" :key="port.id">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-muted"
                        title="Buka data pelanggan"
                        @click="emit('openCustomer', port.customer!.id)"
                    >
                        <span class="w-6 text-center font-mono text-xs text-muted-foreground">{{ port.number }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium">{{ port.customer!.name }}</div>
                            <div class="truncate text-xs text-muted-foreground">
                                {{ [port.customer!.customer_code, port.customer!.phone, port.customer!.package].filter(Boolean).join(' · ') || '—' }}
                            </div>
                        </div>
                    </button>
                </li>
            </ul>
        </div>

        <PhotoGallery
            owner-type="odp"
            :owner-id="odp.id"
            :photos="odp.photos ?? []"
            :can-edit="canEdit"
            :can-delete="canDelete"
            @changed="emit('refresh')"
        />

        <div class="flex flex-wrap gap-2">
            <Button as="a" :href="navUrl" target="_blank" variant="outline" size="sm"><Navigation class="size-4" /> Navigasi</Button>
            <Button v-if="canEdit" variant="outline" size="sm" @click="emit('edit')"><Pencil class="size-4" /> Edit</Button>
            <Button v-if="canDelete" variant="destructive" size="sm" @click="emit('delete')"><Trash2 class="size-4" /> Hapus</Button>
        </div>
    </div>
</template>
