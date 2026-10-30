<script setup lang="ts">
import PhotoGallery from '@/components/map/PhotoGallery.vue';
import { Button } from '@/components/ui/button';
import type { Olt, OltPort, Pop } from '@/types';
import { MapPin, Navigation, Pencil, Plus, Trash2, X } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    pop: Pop;
    /** Technicians and up: connect ODCs, add photos. */
    canEdit: boolean;
    /** Admins: edit the POP, add/change/remove OLTs and cards. */
    canManage: boolean;
}>();

const emit = defineEmits<{
    close: [];
    refresh: [];
    editLocation: [];
    addOlt: [];
    editOlt: [olt: Olt];
    deleteOlt: [olt: Olt];
    openPort: [olt: Olt, port: OltPort];
    selectOdc: [id: number];
}>();

const navUrl = computed(() => `https://www.google.com/maps/dir/?api=1&destination=${props.pop.lat},${props.pop.lng}`);

/** Ports of an OLT grouped per card slot, in order. */
const slots = (olt: Olt) => {
    const groups = new Map<number, OltPort[]>();
    for (const port of olt.ports) groups.set(port.slot, [...(groups.get(port.slot) ?? []), port]);
    return [...groups.entries()].map(([slot, ports]) => ({ slot, ports }));
};

const usage = (olt: Olt) => ({
    used: olt.ports.filter((p) => p.odcs.length).length,
    total: olt.ports.length,
});

const portClass = (port: OltPort) =>
    port.status === 'rusak'
        ? 'border-gray-500 bg-gray-300 text-gray-700 line-through dark:bg-gray-700 dark:text-gray-300'
        : port.odcs.length
          ? 'border-blue-600 bg-blue-600 text-white'
          : 'border-green-500 bg-green-50 text-green-800 dark:bg-green-950 dark:text-green-200';
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex items-start justify-between gap-2">
            <div>
                <div class="text-xs font-medium text-muted-foreground">POP · {{ pop.code }}</div>
                <h2 class="text-lg font-semibold leading-tight">{{ pop.name }}</h2>
            </div>
            <Button variant="ghost" size="icon" @click="emit('close')"><X class="size-4" /></Button>
        </div>

        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
            <dt class="text-muted-foreground">Alamat</dt>
            <dd>{{ pop.address ?? '—' }}</dd>
            <dt class="text-muted-foreground">Koordinat</dt>
            <dd v-if="pop.lat !== null && pop.lng !== null" class="font-mono text-xs">{{ pop.lat.toFixed(6) }}, {{ pop.lng.toFixed(6) }}</dd>
            <dd v-else class="text-muted-foreground">—</dd>
            <template v-if="pop.notes">
                <dt class="text-muted-foreground">Catatan</dt>
                <dd class="whitespace-pre-line">{{ pop.notes }}</dd>
            </template>
        </dl>

        <div>
            <div class="mb-2 flex items-center justify-between">
                <h3 class="text-sm font-semibold">OLT ({{ pop.olts.length }})</h3>
                <Button v-if="canManage" variant="outline" size="sm" @click="emit('addOlt')"><Plus class="size-4" /> OLT</Button>
            </div>
            <p v-if="!pop.olts.length" class="text-sm text-muted-foreground">Belum ada OLT di POP ini.</p>

            <div v-for="olt in pop.olts" :key="olt.id" class="mb-3 rounded-md border p-3">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="truncate font-semibold">{{ olt.code }}</div>
                        <div class="truncate text-xs text-muted-foreground">
                            {{ [olt.brand, olt.ip_address].filter(Boolean).join(' · ') || '—' }} · port {{ usage(olt).used }}/{{ usage(olt).total }}
                            terpakai
                        </div>
                    </div>
                    <div v-if="canManage" class="flex shrink-0 gap-1">
                        <Button variant="ghost" size="icon" class="size-8" title="Edit OLT & kartu" @click="emit('editOlt', olt)"
                            ><Pencil class="size-4"
                        /></Button>
                        <Button variant="ghost" size="icon" class="size-8 text-red-600" title="Hapus OLT" @click="emit('deleteOlt', olt)"
                            ><Trash2 class="size-4"
                        /></Button>
                    </div>
                </div>
                <p v-if="olt.notes" class="mt-1 whitespace-pre-line text-xs text-muted-foreground">{{ olt.notes }}</p>

                <div v-for="card in slots(olt)" :key="card.slot" class="mt-2">
                    <div class="mb-1 text-xs font-medium text-muted-foreground">Slot {{ card.slot }}</div>
                    <div class="grid grid-cols-8 gap-1">
                        <button
                            v-for="port in card.ports"
                            :key="port.id"
                            type="button"
                            class="flex h-8 items-center justify-center rounded border-2 text-xs font-semibold transition hover:opacity-80"
                            :class="portClass(port)"
                            :title="port.odcs.length ? port.odcs.map((o) => o.code).join(', ') : port.status === 'rusak' ? 'Rusak' : 'Kosong'"
                            @click="emit('openPort', olt, port)"
                        >
                            {{ port.port }}
                        </button>
                    </div>
                </div>

                <ul v-if="olt.ports.some((p) => p.odcs.length)" class="mt-2 divide-y rounded-md border text-sm">
                    <template v-for="port in olt.ports" :key="port.id">
                        <li v-for="odc in port.odcs" :key="odc.id">
                            <button
                                type="button"
                                class="flex w-full items-center gap-3 px-3 py-1.5 text-left hover:bg-muted"
                                @click="emit('selectOdc', odc.id)"
                            >
                                <span class="w-12 shrink-0 font-mono text-xs text-muted-foreground">{{ port.slot }}/{{ port.port }}</span>
                                <span class="truncate font-medium">{{ odc.code }}</span>
                            </button>
                        </li>
                    </template>
                </ul>
            </div>

            <div class="flex flex-wrap gap-3 text-xs text-muted-foreground">
                <span><span class="mr-1 inline-block size-2.5 rounded-sm bg-green-500" />Kosong</span>
                <span><span class="mr-1 inline-block size-2.5 rounded-sm bg-blue-600" />Ke ODC</span>
                <span><span class="mr-1 inline-block size-2.5 rounded-sm bg-gray-400" />Rusak</span>
                <span>· Klik port untuk {{ canEdit ? 'menyambungkan ODC' : 'melihat detail' }}</span>
            </div>
        </div>

        <PhotoGallery
            owner-type="pop"
            :owner-id="pop.id"
            :photos="pop.photos ?? []"
            :can-edit="canEdit"
            :can-delete="canManage"
            @changed="emit('refresh')"
        />

        <div class="flex flex-wrap gap-2">
            <Button v-if="pop.lat !== null" as="a" :href="navUrl" target="_blank" variant="outline" size="sm"
                ><Navigation class="size-4" /> Navigasi</Button
            >
            <Button v-if="canManage" variant="outline" size="sm" @click="emit('editLocation')"><MapPin class="size-4" /> Edit lokasi & info</Button>
        </div>
    </div>
</template>
