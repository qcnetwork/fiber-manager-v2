<script setup lang="ts">
import PhotoGallery from '@/components/map/PhotoGallery.vue';
import { Button } from '@/components/ui/button';
import { formatLength } from '@/lib/cables';
import type { Customer } from '@/types';
import { ArrowRightLeft, MapPinOff, Navigation, Pencil, X } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    customer: Customer;
    canEdit: boolean;
    canDelete: boolean;
}>();

const emit = defineEmits<{
    edit: [];
    move: [];
    close: [];
    refresh: [];
    selectOdp: [id: number];
}>();

const odp = computed(() => props.customer.port?.odp ?? null);
const hasLocation = computed(() => props.customer.lat !== null && props.customer.lng !== null);
const navUrl = computed(() => `https://www.google.com/maps/dir/?api=1&destination=${props.customer.lat},${props.customer.lng}`);

// Straight-line distance from the ODP, a quick check that the location and the port fit together.
const odpDistance = computed(() => {
    const o = odp.value;
    const c = props.customer;
    if (!o || c.lat === null || c.lng === null) return null;
    const rad = Math.PI / 180;
    const dLat = (c.lat - o.lat) * rad;
    const dLng = (c.lng - o.lng) * rad;
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(o.lat * rad) * Math.cos(c.lat * rad) * Math.sin(dLng / 2) ** 2;
    return 2 * 6371000 * Math.asin(Math.sqrt(a));
});
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <div class="text-xs font-medium text-muted-foreground">Pelanggan · {{ customer.area?.name }}</div>
                <h2 class="text-lg leading-tight font-semibold">{{ customer.name }}</h2>
                <div v-if="customer.customer_code" class="truncate text-sm text-muted-foreground">{{ customer.customer_code }}</div>
            </div>
            <Button variant="ghost" size="icon" @click="emit('close')"><X class="size-4" /></Button>
        </div>

        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
            <dt class="text-muted-foreground">ODP</dt>
            <dd>
                <template v-if="odp">
                    <button class="font-medium text-blue-600 hover:underline dark:text-blue-400" @click="emit('selectOdp', odp.id)">
                        {{ odp.code }}
                    </button>
                    · port {{ customer.port!.number }}
                </template>
                <span v-else class="text-amber-700 dark:text-amber-300">Belum terhubung ke port</span>
            </dd>
            <dt class="text-muted-foreground">No. HP</dt>
            <dd>
                <a v-if="customer.phone" :href="`tel:${customer.phone}`" class="text-blue-600 hover:underline dark:text-blue-400">{{
                    customer.phone
                }}</a>
                <span v-else>—</span>
            </dd>
            <dt class="text-muted-foreground">Alamat</dt>
            <dd>{{ customer.address ?? '—' }}</dd>
            <dt class="text-muted-foreground">Paket</dt>
            <dd>{{ customer.package ?? '—' }}</dd>
            <dt class="text-muted-foreground">SN ONT</dt>
            <dd class="font-mono text-xs">{{ customer.ont_sn ?? '—' }}</dd>
            <dt class="text-muted-foreground">Koordinat</dt>
            <dd v-if="hasLocation" class="font-mono text-xs">
                {{ customer.lat!.toFixed(6) }}, {{ customer.lng!.toFixed(6) }}
                <div v-if="odpDistance !== null" class="font-sans text-muted-foreground">{{ formatLength(odpDistance) }} dari ODP (garis lurus)</div>
            </dd>
            <dd v-else class="flex items-center gap-1 text-muted-foreground"><MapPinOff class="size-3.5" /> Belum ada</dd>
        </dl>

        <PhotoGallery
            owner-type="customer"
            :owner-id="customer.id"
            :photos="customer.photos ?? []"
            :can-edit="canEdit"
            :can-delete="canDelete"
            @changed="emit('refresh')"
        />

        <div class="flex flex-wrap gap-2">
            <Button v-if="hasLocation" as="a" :href="navUrl" target="_blank" variant="outline" size="sm"
                ><Navigation class="size-4" /> Navigasi</Button
            >
            <Button v-if="canEdit" variant="outline" size="sm" @click="emit('edit')"><Pencil class="size-4" /> Edit & lokasi</Button>
            <Button v-if="canEdit" variant="outline" size="sm" @click="emit('move')"><ArrowRightLeft class="size-4" /> Pindah ODP/port</Button>
        </div>
    </div>
</template>
