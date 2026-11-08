<script setup lang="ts">
import CoreSplices from '@/components/map/CoreSplices.vue';
import PhotoGallery from '@/components/map/PhotoGallery.vue';
import StatusBadge from '@/components/map/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import type { OdcDetail } from '@/types';
import { Navigation, Pencil, Plus, Trash2, X } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    detail: OdcDetail;
    canEdit: boolean;
    canDelete: boolean;
}>();

const emit = defineEmits<{
    edit: [];
    delete: [];
    close: [];
    refresh: [];
    addOdp: [];
    selectOdp: [id: number];
    selectPop: [id: number];
    selectCable: [id: number];
}>();

const odc = computed(() => props.detail.odc);
const navUrl = computed(() => `https://www.google.com/maps/dir/?api=1&destination=${odc.value.lat},${odc.value.lng}`);
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex items-start justify-between gap-2">
            <div>
                <div class="text-xs font-medium text-muted-foreground">ODC · {{ odc.area?.name }}</div>
                <h2 class="text-lg leading-tight font-semibold">{{ odc.code }}</h2>
                <div v-if="odc.name" class="text-sm text-muted-foreground">{{ odc.name }}</div>
            </div>
            <Button variant="ghost" size="icon" @click="emit('close')"><X class="size-4" /></Button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <StatusBadge :status="odc.status" />
            <span class="text-sm">
                <b>{{ detail.used_odc_ports.length }}</b
                >/{{ odc.capacity }} port ODC terpakai · {{ detail.customer_count }} pelanggan
            </span>
        </div>

        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
            <dt class="text-muted-foreground">Sumber</dt>
            <dd>
                <template v-if="odc.olt_port?.olt">
                    <button class="font-medium text-blue-600 hover:underline dark:text-blue-400" @click="emit('selectPop', odc.olt_port.olt.area_id)">
                        {{ odc.area?.name ?? 'POP' }}
                    </button>
                    › {{ odc.olt_port.olt.code }} › slot {{ odc.olt_port.slot }} / port {{ odc.olt_port.port }}
                    <span v-if="odc.olt_port.status === 'rusak'" class="text-xs font-semibold text-violet-700 dark:text-violet-300"
                        >(port rusak)</span
                    >
                </template>
                <span v-else class="text-muted-foreground">Belum tersambung ke OLT</span>
            </dd>
            <dt class="text-muted-foreground">Splitter</dt>
            <dd>{{ odc.splitter_ratio ?? '—' }}</dd>
            <dt class="text-muted-foreground">Alamat</dt>
            <dd>{{ odc.address ?? '—' }}</dd>
            <dt class="text-muted-foreground">Koordinat</dt>
            <dd class="font-mono text-xs">{{ odc.lat.toFixed(6) }}, {{ odc.lng.toFixed(6) }}</dd>
            <template v-if="odc.installed_at">
                <dt class="text-muted-foreground">Dipasang</dt>
                <dd>{{ odc.installed_at }}</dd>
            </template>
            <template v-if="odc.notes">
                <dt class="text-muted-foreground">Catatan</dt>
                <dd class="whitespace-pre-line">{{ odc.notes }}</dd>
            </template>
        </dl>

        <div>
            <div class="mb-2 flex items-center justify-between">
                <h3 class="text-sm font-semibold">ODP terhubung ({{ detail.odps.length }})</h3>
                <Button v-if="canEdit" variant="outline" size="sm" @click="emit('addOdp')"><Plus class="size-4" /> ODP</Button>
            </div>
            <ul v-if="detail.odps.length" class="divide-y rounded-md border text-sm">
                <li v-for="odp in detail.odps" :key="odp.id">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-muted"
                        @click="emit('selectOdp', odp.id)"
                    >
                        <span class="w-10 font-mono text-xs text-muted-foreground">P{{ odp.odc_port ?? '-' }}</span>
                        <span class="flex-1 truncate font-medium">{{ odp.code }}</span>
                        <span class="text-xs" :class="odp.used_ports >= odp.capacity ? 'font-semibold text-red-600' : 'text-muted-foreground'">
                            {{ odp.used_ports }}/{{ odp.capacity }}
                        </span>
                    </button>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">Belum ada ODP yang terhubung.</p>
        </div>

        <CoreSplices kind="odc" :id="odc.id" :can-edit="canEdit" @select-cable="(id) => emit('selectCable', id)" />

        <PhotoGallery
            owner-type="odc"
            :owner-id="odc.id"
            :photos="odc.photos ?? []"
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
