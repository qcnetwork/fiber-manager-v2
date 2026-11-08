<script setup lang="ts">
import PhotoGallery from '@/components/map/PhotoGallery.vue';
import StatusBadge from '@/components/map/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { cableTypes, formatLength, jointTypes } from '@/lib/cables';
import type { JointDetail } from '@/types';
import { Navigation, Pencil, Trash2, X } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    detail: JointDetail;
    canEdit: boolean;
    canDelete: boolean;
}>();

const emit = defineEmits<{
    edit: [];
    delete: [];
    close: [];
    refresh: [];
    selectCable: [id: number];
}>();

const joint = computed(() => props.detail.joint);
const typeLabel = computed(() => jointTypes[joint.value.type]?.label ?? 'Joint');
const navUrl = computed(() => `https://www.google.com/maps/dir/?api=1&destination=${joint.value.lat},${joint.value.lng}`);
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex items-start justify-between gap-2">
            <div>
                <div class="text-xs font-medium text-muted-foreground">{{ typeLabel }} · {{ joint.area?.name }}</div>
                <h2 class="text-lg leading-tight font-semibold">{{ joint.code }}</h2>
                <div v-if="joint.name" class="text-sm text-muted-foreground">{{ joint.name }}</div>
            </div>
            <Button variant="ghost" size="icon" @click="emit('close')"><X class="size-4" /></Button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <StatusBadge :status="joint.status" />
            <span class="text-sm">{{ detail.cables.length }} kabel tersambung</span>
        </div>

        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
            <template v-if="joint.capacity">
                <dt class="text-muted-foreground">Kapasitas</dt>
                <dd>{{ joint.capacity }} sambungan core</dd>
            </template>
            <dt class="text-muted-foreground">No. tiang</dt>
            <dd>{{ joint.pole_number ?? '—' }}</dd>
            <dt class="text-muted-foreground">Alamat</dt>
            <dd>{{ joint.address ?? '—' }}</dd>
            <dt class="text-muted-foreground">Koordinat</dt>
            <dd class="font-mono text-xs">{{ joint.lat.toFixed(6) }}, {{ joint.lng.toFixed(6) }}</dd>
            <template v-if="joint.installed_at">
                <dt class="text-muted-foreground">Dipasang</dt>
                <dd>{{ joint.installed_at }}</dd>
            </template>
            <template v-if="joint.notes">
                <dt class="text-muted-foreground">Catatan</dt>
                <dd class="whitespace-pre-line">{{ joint.notes }}</dd>
            </template>
        </dl>

        <div>
            <h3 class="mb-2 text-sm font-semibold">Kabel tersambung ({{ detail.cables.length }})</h3>
            <ul v-if="detail.cables.length" class="divide-y rounded-md border text-sm">
                <li v-for="cable in detail.cables" :key="cable.id">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-muted"
                        @click="emit('selectCable', cable.id)"
                    >
                        <span class="inline-block h-1.5 w-5 shrink-0 rounded-full" :style="{ background: cableTypes[cable.type]?.color }" />
                        <span class="flex-1 truncate font-medium">{{ cable.code ?? `Kabel ${cableTypes[cable.type]?.label} #${cable.id}` }}</span>
                        <span class="text-xs text-muted-foreground tabular-nums">{{ cable.cores }} core · {{ formatLength(cable.length_m) }}</span>
                    </button>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">Belum ada kabel. Sambungkan lewat Edit (kabel terdekat) atau saat menggambar kabel.</p>
        </div>

        <PhotoGallery
            owner-type="joint"
            :owner-id="joint.id"
            :photos="joint.photos ?? []"
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
